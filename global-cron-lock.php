<?php
/**
 * Global Cron Lock - Multi-site Cron Job Queue Management
 *
 * Prevents multiple WordPress sites/instances from running the same heavy cron
 * job simultaneously using file-based locks and queues. Supports both per-event
 * locking (separate lock per event) and global locking (one unified queue).
 *
 * @package   GlobalCronLock
 * @author    Andreas Myriounis <andreas.myriounis@gmail.com>
 * @link      https://github.com/amyriounis/global-cron-lock
 * @since     1.0.0
 *
 * Plugin Name: Global Cron Lock
 * Plugin URI:  https://github.com/amyriounis/global-cron-lock
 * Description: Prevent multiple sites from running same cron job simultaneously with file-based locks and queues.
 * Author:      Andreas Myriounis at VamTam Ltd
 * Version:     1.0.0
 * Text Domain: global-cron-lock
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access to this file is not allowed.' );
}

// Define plugin constants.
if ( ! defined( 'GCL_PLUGIN_FILE' ) ) {
	define( 'GCL_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'GCL_PLUGIN_DIR' ) ) {
	define( 'GCL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'GCL_PLUGIN_URL' ) ) {
	define( 'GCL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'GCL_VERSION' ) ) {
	define( 'GCL_VERSION', '1.0.0' );
}

/**
 * Main plugin class for Global Cron Lock.
 *
 * Implements file-based locking and queuing mechanism for WordPress cron events
 * across multiple sites. Supports both per-event and global locking modes.
 *
 * @since 1.0.0
 */
class Global_Cron_Lock {

	/**
	 * Plugin configuration.
	 *
	 * @var array
	 */
	private static $config = array(
		'lock_dir'          => GCL_PLUGIN_DIR,
		'status_file'       => GCL_PLUGIN_DIR . '/global-cron-status.json',
		'log_file'          => GCL_PLUGIN_DIR . '/global-cron.log',
		'per_event_locking' => true,    // Separate locks per event (true) or global lock (false).
		'max_lock_age'      => 300,     // 5 minutes in seconds.
		'base_delay'        => 5,       // Base delay in seconds for queue positioning.
		'max_delay'         => 35,      // Maximum delay in seconds.
		'trigger_method'    => 'both',  // Options: 'http', 'wpcli', or 'both'.
		'wp_cli_path'       => '/usr/local/bin/wp', // Path to WP-CLI binary.
		'site_paths'        => array(), // Map of site_url => path for WP-CLI fallback.
		'locked_events'     => array(), // Map of public event => internal event.
	);

	/**
	 * Array of open file lock handles.
	 *
	 * @var array
	 */
	private static $open_locks = array();

	/**
	 * Track which shutdown handlers have been registered to prevent duplicates.
	 *
	 * @var array
	 */
	private static $shutdown_registered = array();

	/**
	 * Track active locks per request to prevent multiple acquisitions.
	 *
	 * @var array
	 */
	private static $active_locks = array();

	/**
	 * Get configuration value.
	 *
	 * @param string|null $key Optional. Configuration key to retrieve.
	 * @return mixed Configuration value or entire config array.
	 *
	 * @since 1.0.0
	 */
	public static function cfg( $key = null ) {
		return $key ? ( self::$config[ $key ] ?? null ) : self::$config;
	}

	/**
	 * Write a log message to the log file.
	 *
	 * Logs include timestamp, process ID, site name, and message.
	 * Thread-safe using FILE_APPEND | LOCK_EX flags.
	 *
	 * @param string $msg The message to log.
	 *
	 * @since 1.0.0
	 */
	public static function log( $msg ) {
		$file = self::cfg( 'log_file' );
		$pid  = (int) getmypid();
		$site = function_exists( 'get_bloginfo' )
			? get_bloginfo( 'name' ) . ' (' . home_url() . ')'
			: php_uname( 'n' );
		$line = sprintf( "[%s] [PID %d] [%s] %s\n", gmdate( 'c' ), $pid, $site, $msg );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Necessary for lock file operations outside WP context.
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Ensure the lock directory exists.
	 *
	 * Creates directory with appropriate permissions if it doesn't exist.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function ensure_lock_dir() {
		$dir = self::cfg( 'lock_dir' );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
			@chmod( $dir, 0775 );
		}
	}

	/**
	 * Read the global status file.
	 *
	 * Returns file pointer and parsed JSON data. Creates file if it doesn't exist.
	 * File is locked for exclusive access.
	 *
	 * @return array Array with 'fp' (file pointer) and 'data' (parsed JSON).
	 *
	 * @since 1.0.0
	 */
	private static function read_status() {
		$file = self::cfg( 'status_file' );
		$per_event = self::cfg( 'per_event_locking' );

		if ( ! file_exists( $file ) ) {
			$initial_data = $per_event
				? array( 'events' => array() )
				: array(
					'global' => array(
						'status' => 'idle',
						'queue' => array(),
						'timestamp' => gmdate( 'c' ),
					),
				);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Necessary for lock file operations.
			@file_put_contents( $file, wp_json_encode( $initial_data, JSON_PRETTY_PRINT ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
			@chmod( $file, 0664 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Necessary for file locking.
		$fp = @fopen( $file, 'c+' );
		if ( ! $fp ) {
			$default = $per_event
				? array( 'events' => array() )
				: array( 'global' => array( 'status' => 'idle', 'queue' => array() ) );
			return array(
				'fp' => null,
				'data' => $default,
			);
		}

		@flock( $fp, LOCK_EX );
		rewind( $fp );
		$data = json_decode( stream_get_contents( $fp ), true );
		if ( ! is_array( $data ) ) {
			$data = $per_event
				? array( 'events' => array() )
				: array( 'global' => array( 'status' => 'idle', 'queue' => array() ) );
		}

		return array(
			'fp' => $fp,
			'data' => $data,
		);
	}

	/**
	 * Write the global status file.
	 *
	 * Writes JSON data to status file and releases lock. File pointer must be open.
	 *
	 * @param array $read Array containing file pointer and data.
	 * @return bool True on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	private static function write_status( $read ) {
		if ( empty( $read['fp'] ) ) {
			return false;
		}

		$fp = $read['fp'];
		$data = $read['data'];
		rewind( $fp );
		ftruncate( $fp, 0 );
		fwrite( $fp, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
		fflush( $fp );
		@flock( $fp, LOCK_UN );
		fclose( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
		@chmod( self::cfg( 'status_file' ), 0664 );
		return true;
	}

	/**
	 * Acquire a file lock.
	 *
	 * Creates lock file and writes payload. Uses non-blocking lock to fail fast.
	 *
	 * @param string $file Path to lock file.
	 * @param array  $payload Data to write to lock file.
	 * @return bool True if lock acquired, false otherwise.
	 *
	 * @since 1.0.0
	 */
	private static function acquire_lock( $file, $payload ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Necessary for file locking.
		$fp = @fopen( $file, 'c+' );
		if ( ! $fp ) {
			return false;
		}

		if ( ! @flock( $fp, LOCK_EX | LOCK_NB ) ) {
			fclose( $fp );
			return false;
		}

		ftruncate( $fp, 0 );
		rewind( $fp );
		fwrite( $fp, wp_json_encode( $payload ) );
		fflush( $fp );
		self::$open_locks[ $file ] = $fp;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
		@chmod( $file, 0664 );
		return true;
	}

	/**
	 * Release a file lock.
	 *
	 * Unlocks and closes file handle, then deletes lock file.
	 *
	 * @param string $file Path to lock file.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function release_lock( $file ) {
		if ( isset( self::$open_locks[ $file ] ) && is_resource( self::$open_locks[ $file ] ) ) {
			@flock( self::$open_locks[ $file ], LOCK_UN );
			fclose( self::$open_locks[ $file ] );
			unset( self::$open_locks[ $file ] );
		}

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Read and parse a lock file.
	 *
	 * @param string $file Path to lock file.
	 * @return array|null Parsed lock data or null if invalid.
	 *
	 * @since 1.0.0
	 */
	private static function read_lock_file( $file ) {
		if ( ! file_exists( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Necessary for lock file reading.
		$content = @file_get_contents( $file );
		$data = json_decode( $content, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clear all stale locks proactively.
	 *
	 * Scans lock directory and removes locks older than max_lock_age.
	 *
	 * @return int Number of stale locks removed.
	 *
	 * @since 1.0.0
	 */
	private static function clear_all_stale_locks() {
		$max_age = self::cfg( 'max_lock_age' );
		$dir   = self::cfg( 'lock_dir' );
		$count = 0;
		$locks = glob( $dir . '/*.lock' );

		if ( ! $locks ) {
			return 0;
		}

		foreach ( $locks as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Necessary for lock file reading.
			$data = json_decode( @file_get_contents( $file ), true );
			if ( isset( $data['timestamp'] ) && ( time() - $data['timestamp'] ) > $max_age ) {
				wp_delete_file( $file );
				$count++;
			}
		}

		if ( $count > 0 ) {
			self::log( sprintf( 'Cleared %d stale lock(s) proactively', $count ) );
		}

		return $count;
	}

	/**
	 * Determine WordPress installation path from site URL.
	 *
	 * Checks configured site_paths first, then attempts to guess from URL.
	 *
	 * @param string $site_url The full site URL.
	 * @return string|null The WordPress installation path or null if not found.
	 *
	 * @since 1.0.0
	 */
	private static function get_site_path_from_url( $site_url ) {
		$site_paths = self::cfg( 'site_paths' );
		if ( is_array( $site_paths ) && isset( $site_paths[ $site_url ] ) ) {
			$path = $site_paths[ $site_url ];
			if ( file_exists( $path . '/wp-load.php' ) ) {
				return $path;
			}
		}

		$parsed = wp_parse_url( $site_url );
		$host   = $parsed['host'] ?? '';
		$parts  = explode( '.', $host );
		$site_name = $parts[0] ?? '';

		$possible_paths = array(
			"/var/www/html/{$site_name}",
			"/var/www/{$site_name}/public_html",
			"/var/www/{$site_name}",
			ABSPATH,
		);

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path . '/wp-load.php' ) ) {
				return $path;
			}
		}

		if ( file_exists( ABSPATH . 'wp-load.php' ) ) {
			return ABSPATH;
		}

		return null;
	}

	/**
	 * Trigger the next queued event via WP-CLI.
	 *
	 * Executes next job asynchronously using WP-CLI with delay.
	 *
	 * @param array $next Array containing site and event info.
	 * @param int   $delay Delay in seconds before execution.
	 * @return bool True on success.
	 *
	 * @since 1.0.0
	 */
	private static function trigger_next_wpcli( $next, $delay = 3 ) {
		$wp       = self::cfg( 'wp_cli_path' ) ?: 'wp';
		$site_url = $next['site_url'];
		$event    = $next['event'];
		$wp_path  = self::get_site_path_from_url( $site_url );

		if ( ! $wp_path ) {
			self::log( sprintf( 'ERROR: Could not determine path for site %s - attempting HTTP fallback', $site_url ) );
			return false;
		}

		self::log(
			sprintf(
				'Triggering next via WP-CLI: %s (%s) at path %s after %ds',
				$next['site'],
				$event,
				$wp_path,
				$delay
			)
		);

		$cmd = sprintf(
			'nohup sh -c "sleep %d && cd %s && %s cron event run %s --url=%s 2>&1" > /dev/null 2>&1 & disown',
			intval( $delay ),
			escapeshellarg( $wp_path ),
			escapeshellcmd( $wp ),
			escapeshellarg( $event ),
			escapeshellarg( $site_url )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Necessary for async job execution.
		shell_exec( $cmd );
		return true;
	}

	/**
	 * Trigger the next queued event via HTTP.
	 *
	 * Makes non-blocking HTTP request to wp-cron.php endpoint.
	 *
	 * @param array $next Array containing site and event info.
	 * @param int   $delay Delay in seconds (not used for HTTP).
	 * @return bool True on success.
	 *
	 * @since 1.0.0
	 */
	private static function trigger_next_http( $next, $delay = 1 ) {
		$url = trailingslashit( $next['site_url'] ) . 'wp-cron.php?doing_wp_cron=' . microtime( true );

		self::log( sprintf( 'Triggering next via HTTP: %s (%s)', $next['site'], $next['event'] ) );

		if ( function_exists( 'wp_remote_post' ) ) {
			$args = array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'headers'   => array( 'Connection' => 'close' ),
			);
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				self::log( sprintf( 'HTTP trigger ERROR for %s: %s - trying cURL fallback', $next['site'], $response->get_error_message() ) );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Fallback for HTTP trigger.
				$cmd = sprintf( 'curl -fsS --max-time 3 %s > /dev/null 2>&1 &', escapeshellarg( $url ) );
				shell_exec( $cmd );
			}

			return true;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Necessary for HTTP trigger fallback.
		$cmd = sprintf( 'curl -fsS --max-time 3 %s > /dev/null 2>&1 &', escapeshellarg( $url ) );
		shell_exec( $cmd );
		return true;
	}

	/**
	 * Trigger the next queued event with fallback support.
	 *
	 * Uses configured trigger_method (http, wpcli, or both) with fallback logic.
	 *
	 * @param array $next Array containing site and event info.
	 * @param int   $delay Delay in seconds.
	 * @return bool True on success.
	 *
	 * @since 1.0.0
	 */
	private static function trigger_next( $next, $delay = 3 ) {
		$method = self::cfg( 'trigger_method' );
		$ok = false;

		if ( 'wpcli' === $method || 'both' === $method ) {
			$ok = self::trigger_next_wpcli( $next, $delay );
		}

		if ( 'http' === $method || ( 'both' === $method && ! $ok ) ) {
			if ( ! $ok ) {
				self::log( sprintf( 'Falling back to HTTP trigger for %s', $next['site'] ) );
			}
			$ok = self::trigger_next_http( $next, $delay );
		}

		return $ok;
	}

	/**
	 * Release lock and trigger next in queue (per-event mode).
	 *
	 * Called at shutdown after event execution completes.
	 *
	 * @param string $lock_file Path to lock file.
	 * @param string $public_event Event name.
	 * @param string $site_url Current site URL.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function release_and_trigger_next_per_event( $lock_file, $public_event, $site_url ) {
		$key = $lock_file . '::' . $public_event;

		if ( isset( self::$shutdown_registered[ $key ] ) && self::$shutdown_registered[ $key ] === 'executed' ) {
			return;
		}

		self::$shutdown_registered[ $key ] = 'executed';

		$read = self::read_status();
		$st   = $read['data'];

		if ( ! isset( $st['events'][ $public_event ] ) ) {
			self::release_lock( $lock_file );
			self::log( sprintf( 'Lock released (no event status) (%s) [shutdown]', $public_event ) );
			unset( self::$active_locks[ $public_event ] );
			return;
		}

		$ev_st = &$st['events'][ $public_event ];

		// Find next candidate.
		$next    = null;
		$skipped = 0;

		if ( ! empty( $ev_st['queue'] ) ) {
			$next = array_shift( $ev_st['queue'] );

			// Skip self entries.
			while ( $next && ( $next['site_url'] ?? '' ) === $site_url ) {
				self::log( sprintf( 'Skipping self in %s queue for %s', $public_event, $site_url ) );
				$skipped++;
				$next = ! empty( $ev_st['queue'] ) ? array_shift( $ev_st['queue'] ) : null;
			}
		}

		// Set event to idle.
		$ev_st['status']    = 'idle';
		$ev_st['timestamp'] = gmdate( 'c' );
		unset( $ev_st['site'], $ev_st['site_url'] );

		// Write status BEFORE releasing lock.
		$read['data'] = $st;
		self::write_status( $read );

		// Now release the lock.
		self::release_lock( $lock_file );
		self::log( sprintf( 'Lock released (%s) [shutdown]', $public_event ) );
		unset( self::$active_locks[ $public_event ] );

		// Trigger next if found.
		if ( $next ) {
			if ( ! self::trigger_next( $next, 3 ) ) {
				self::log( sprintf( 'Trigger failed for %s - rescheduling via wp_schedule_single_event', $next['site'] ) );
				wp_schedule_single_event( time() + 5, $public_event );
			}
		} elseif ( $skipped > 0 ) {
			self::log( sprintf( 'Skipped %d self entries in %s queue; rescheduling for retry', $skipped, $public_event ) );
			wp_schedule_single_event( time() + 5, $public_event );
		}
	}

	/**
	 * Release lock and trigger next in unified queue (global mode).
	 *
	 * Called at shutdown after event execution completes.
	 *
	 * @param string $lock_file Path to lock file.
	 * @param string $public_event Event name.
	 * @param string $site_url Current site URL.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function release_and_trigger_next_global( $lock_file, $public_event, $site_url ) {
		$key = $lock_file . '::global';

		if ( isset( self::$shutdown_registered[ $key ] ) && self::$shutdown_registered[ $key ] === 'executed' ) {
			return;
		}

		self::$shutdown_registered[ $key ] = 'executed';

		$read = self::read_status();
		$st   = $read['data'];

		if ( ! isset( $st['global'] ) ) {
			self::release_lock( $lock_file );
			self::log( 'Lock released (no global status) [shutdown]' );
			unset( self::$active_locks['global'] );
			return;
		}

		$global = &$st['global'];

		// Find next candidate.
		$next    = null;
		$skipped = 0;

		if ( ! empty( $global['queue'] ) ) {
			$next = array_shift( $global['queue'] );

			// Skip self entries (same site AND same event).
			while ( $next && ( $next['site_url'] ?? '' ) === $site_url && ( $next['event'] ?? '' ) === $public_event ) {
				self::log( sprintf( 'Skipping self in global queue for %s (%s)', $site_url, $public_event ) );
				$skipped++;
				$next = ! empty( $global['queue'] ) ? array_shift( $global['queue'] ) : null;
			}
		}

		// Set global status to idle.
		$global['status']    = 'idle';
		$global['timestamp'] = gmdate( 'c' );
		unset( $global['current_site'], $global['current_site_url'], $global['current_event'] );

		// Write status BEFORE releasing lock.
		$read['data'] = $st;
		self::write_status( $read );

		// Now release the lock.
		self::release_lock( $lock_file );
		self::log( sprintf( 'Lock released (global) after %s [shutdown]', $public_event ) );
		unset( self::$active_locks['global'] );

		// Trigger next if found.
		if ( $next ) {
			if ( ! self::trigger_next( $next, 3 ) ) {
				self::log( sprintf( 'Trigger failed for %s - rescheduling via wp_schedule_single_event', $next['site'] ) );
				wp_schedule_single_event( time() + 5, $next['event'] );
			}
		} elseif ( $skipped > 0 ) {
			self::log( sprintf( 'Skipped %d self entries in global queue; rescheduling for retry', $skipped ) );
			wp_schedule_single_event( time() + 5, $public_event );
		}
	}

	/**
	 * Handle a public cron event with locking and queues.
	 *
	 * Main entry point for cron event handling. Acquires lock, queues if necessary,
	 * and executes the internal event.
	 *
	 * @param string $public_event The public event name.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function handle_public_event( $public_event ) {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}

		$per_event = self::cfg( 'per_event_locking' );

		// Prevent multiple locks in same request.
		$lock_key = $per_event ? $public_event : 'global';
		if ( isset( self::$active_locks[ $lock_key ] ) ) {
			self::log( sprintf( 'Skipping %s - already handling in current request', $public_event ) );
			return;
		}

		// Proactively clear stale locks for all events.
		self::clear_all_stale_locks();

		$cfg       = self::cfg();
		$lock_dir  = $cfg['lock_dir'];
		$lock_file = $per_event
			? $lock_dir . '/cron-lock-' . sanitize_file_name( $public_event ) . '.lock'
			: $lock_dir . '/cron-lock-global.lock';

		$site_url  = home_url();
		$site_name = get_bloginfo( 'name' );
		$site_label = sprintf( '%s (%s)', $site_name, $site_url );

		$payload = array(
			'site'      => $site_label,
			'site_url'  => $site_url,
			'event'     => $public_event,
			'timestamp' => time(),
			'pid'       => (int) getmypid(),
		);

		// Try to acquire the lock.
		if ( ! self::acquire_lock( $lock_file, $payload ) ) {
			// Could not acquire lock - check if stale and retry once.
			$active = self::read_lock_file( $lock_file );
			$age    = $active && isset( $active['timestamp'] ) ? ( time() - (int) $active['timestamp'] ) : 0;

			if ( $age >= $cfg['max_lock_age'] ) {
				self::log( sprintf( 'Stale lock (%ds) detected - removing', $age ) );
				wp_delete_file( $lock_file );

				// Retry acquire.
				if ( ! self::acquire_lock( $lock_file, $payload ) ) {
					self::log( sprintf( '%s could not acquire lock after stale removal', $site_label ) );
				}
			}

			// Still no lock? Add to queue.
			if ( ! isset( self::$open_locks[ $lock_file ] ) ) {
				if ( $per_event ) {
					self::handle_queue_per_event( $public_event, $site_label, $site_url, $active, $age );
				} else {
					self::handle_queue_global( $public_event, $site_label, $site_url, $active, $age );
				}
				return;
			}
		}

		// Lock acquired successfully.
		self::$active_locks[ $lock_key ] = true;
		self::log( sprintf( 'Lock acquired by %s for %s', $site_label, $public_event ) );

		// Clear pending scheduled instances of this event.
		$timestamp = wp_next_scheduled( $public_event );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $public_event );
			$timestamp = wp_next_scheduled( $public_event );
		}

		// Update status.
		if ( $per_event ) {
			self::update_status_per_event( $public_event, $site_label, $site_url );
		} else {
			self::update_status_global( $public_event, $site_label, $site_url );
		}

		// Register shutdown handler.
		$shutdown_key = $lock_file . '::' . ( $per_event ? $public_event : 'global' );
		if ( ! isset( self::$shutdown_registered[ $shutdown_key ] ) ) {
			self::$shutdown_registered[ $shutdown_key ] = 'registered';
			if ( $per_event ) {
				register_shutdown_function(
					array( __CLASS__, 'release_and_trigger_next_per_event' ),
					$lock_file,
					$public_event,
					$site_url
				);
			} else {
				register_shutdown_function(
					array( __CLASS__, 'release_and_trigger_next_global' ),
					$lock_file,
					$public_event,
					$site_url
				);
			}
		}

		// Execute internal event.
		$internal = self::cfg( 'locked_events' )[ $public_event ] ?? null;
		if ( $internal ) {
			/**
			 * Execute the internal cron event.
			 *
			 * @since 1.0.0
			 */
			do_action( $internal );
		} else {
			self::log( sprintf( 'No internal handler for %s; nothing executed', $public_event ) );
		}
	}

	/**
	 * Handle queueing for per-event mode.
	 *
	 * Adds job to queue and reschedules for later retry.
	 *
	 * @param string $public_event Event name.
	 * @param string $site_label Site display label.
	 * @param string $site_url Site URL.
	 * @param array  $active Active lock data.
	 * @param int    $age Lock age in seconds.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function handle_queue_per_event( $public_event, $site_label, $site_url, $active, $age ) {
		$read = self::read_status();
		$status = $read['data'];

		if ( ! isset( $status['events'][ $public_event ] ) ) {
			$status['events'][ $public_event ] = array(
				'status' => 'idle',
				'queue' => array(),
			);
		}

		$event_status    = &$status['events'][ $public_event ];
		$was_idle        = ( $event_status['status'] ?? 'idle' ) === 'idle';
		$was_empty_queue = empty( $event_status['queue'] );

		// Check if already in queue.
		$already = false;
		foreach ( $event_status['queue'] as $q ) {
			if ( ( $q['site_url'] ?? '' ) === $site_url ) {
				$already = true;
				break;
			}
		}

		if ( ! $already ) {
			$event_status['queue'][] = array(
				'site'      => $site_label,
				'site_url'  => $site_url,
				'event'     => $public_event,
				'queued_at' => gmdate( 'c' ),
			);

			$pos = count( $event_status['queue'] ) - 1;
			$delay = min( self::cfg( 'max_delay' ), max( 1, self::cfg( 'base_delay' ) * ( $pos + 1 ) ) );

			wp_schedule_single_event( time() + $delay, $public_event );

			self::log(
				sprintf(
					'%s waiting - lock held by %s (%ds old); rescheduled %s in %ds (queue pos %d)',
					$site_label,
					$active['site'] ?? 'unknown',
					$age,
					$public_event,
					$delay,
					$pos
				)
			);

			$event_status['status'] = 'waiting';
		}

		$read['data'] = $status;
		self::write_status( $read );

		// Idle-queue bootstrap for per-event mode.
		if ( $was_idle && $was_empty_queue && 0 === ( $pos ?? -1 ) ) {
			$first = $event_status['queue'][0] ?? null;
			if ( $first ) {
				self::log(
					sprintf(
						'Event %s was idle with empty queue - triggering first item %s immediately',
						$public_event,
						$first['site']
					)
				);
				self::trigger_next( $first, 1 );
			}
		}
	}

	/**
	 * Handle queueing for global mode.
	 *
	 * Adds job to unified global queue and reschedules for later retry.
	 *
	 * @param string $public_event Event name.
	 * @param string $site_label Site display label.
	 * @param string $site_url Site URL.
	 * @param array  $active Active lock data.
	 * @param int    $age Lock age in seconds.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function handle_queue_global( $public_event, $site_label, $site_url, $active, $age ) {
		$read   = self::read_status();
		$status = $read['data'];

		if ( ! isset( $status['global'] ) ) {
			$status['global'] = array(
				'status'    => 'idle',
				'queue'     => array(),
				'timestamp' => gmdate( 'c' ),
			);
		}

		$global          = &$status['global'];
		$was_idle        = ( $global['status'] ?? 'idle' ) === 'idle';
		$was_empty_queue = empty( $global['queue'] );

		// Check if already in queue (same site AND same event).
		$already = false;
		foreach ( $global['queue'] as $q ) {
			if ( ( $q['site_url'] ?? '' ) === $site_url && ( $q['event'] ?? '' ) === $public_event ) {
				$already = true;
				break;
			}
		}

		if ( ! $already ) {
			$global['queue'][] = array(
				'site'      => $site_label,
				'site_url'  => $site_url,
				'event'     => $public_event,
				'queued_at' => gmdate( 'c' ),
			);

			$pos   = count( $global['queue'] ) - 1;
			$delay = min( self::cfg( 'max_delay' ), max( 1, self::cfg( 'base_delay' ) * ( $pos + 1 ) ) );

			wp_schedule_single_event( time() + $delay, $public_event );

			self::log(
				sprintf(
					'%s waiting - global lock held by %s (%ds old); rescheduled %s in %ds (global queue pos %d)',
					$site_label,
					$active['site'] ?? 'unknown',
					$age,
					$public_event,
					$delay,
					$pos
				)
			);

			$global['status'] = 'waiting';
		}

		$read['data'] = $status;
		self::write_status( $read );

		// Idle-queue bootstrap for global mode.
		if ( $was_idle && $was_empty_queue && 0 === ( $pos ?? -1 ) ) {
			$first = $global['queue'][0] ?? null;
			if ( $first ) {
				self::log(
					sprintf(
						'Global queue was idle and empty - triggering first item %s (%s) immediately',
						$first['site'],
						$first['event']
					)
				);
				self::trigger_next( $first, 1 );
			}
		}
	}

	/**
	 * Update status for per-event mode.
	 *
	 * Sets event to running and removes current site from queue.
	 *
	 * @param string $public_event Event name.
	 * @param string $site_label Site display label.
	 * @param string $site_url Site URL.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function update_status_per_event( $public_event, $site_label, $site_url ) {
		$read   = self::read_status();
		$status = $read['data'];

		if ( ! isset( $status['events'][ $public_event ] ) ) {
			$status['events'][ $public_event ] = array(
				'status' => 'idle',
				'queue'  => array(),
			);
		}

		$event_status              = &$status['events'][ $public_event ];
		$event_status['status']    = 'running';
		$event_status['site']      = $site_label;
		$event_status['site_url']  = $site_url;
		$event_status['timestamp'] = gmdate( 'c' );
		$event_status['queue']     = array_values(
			array_filter(
				$event_status['queue'] ?? array(),
				function ( $q ) use ( $site_url ) {
					return ( $q['site_url'] ?? '' ) !== $site_url;
				}
			)
		);

		$read['data'] = $status;
		self::write_status( $read );
	}

	/**
	 * Update status for global mode.
	 *
	 * Sets global status to running and removes current job from queue.
	 *
	 * @param string $public_event Event name.
	 * @param string $site_label Site display label.
	 * @param string $site_url Site URL.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function update_status_global( $public_event, $site_label, $site_url ) {
		$read   = self::read_status();
		$status = $read['data'];

		if ( ! isset( $status['global'] ) ) {
			$status['global'] = array(
				'status'    => 'idle',
				'queue'     => array(),
				'timestamp' => gmdate( 'c' ),
			);
		}

		$global                     = &$status['global'];
		$global['status']           = 'running';
		$global['current_site']     = $site_label;
		$global['current_site_url'] = $site_url;
		$global['current_event']    = $public_event;
		$global['timestamp']        = gmdate( 'c' );
		$global['queue']            = array_values(
			array_filter(
				$global['queue'] ?? array(),
				function ( $q ) use ( $site_url, $public_event ) {
					return ! ( ( $q['site_url'] ?? '' ) === $site_url && ( $q['event'] ?? '' ) === $public_event );
				}
			)
		);

		$read['data'] = $status;
		self::write_status( $read );
	}

	/**
	 * Initialize the plugin.
	 *
	 * Sets up lock directory and registers event handlers for all configured events.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		self::ensure_lock_dir();

		foreach ( array_keys( self::cfg( 'locked_events' ) ) as $public ) {
			add_action(
				$public,
				function () use ( $public ) {
					self::handle_public_event( $public );
				},
				1
			);
		}
	}
}

// Initialize plugin.
Global_Cron_Lock::init();

/**
 * Global Cron Lock Admin Interface
 *
 * Manages the WordPress admin dashboard, AJAX handlers, and all admin-related
 * functionality for the Global Cron Lock plugin.
 *
 * @since 1.0.0
 */
class GCL_Admin {

	/**
	 * Initialize the admin interface.
	 *
	 * Registers admin menu, AJAX handlers, and scripts/styles.
	 * Called on WordPress admin_menu and wp_loaded hooks.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'register_ajax_handlers' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu item.
	 *
	 * Adds "Cron Lock" menu item under WordPress admin dashboard.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_menu_page(
			'Global Cron Lock',
			'Cron Lock',
			'manage_options',
			'global-cron-lock',
			array( __CLASS__, 'render_page' ),
			'dashicons-lock',
			80
		);
	}

	/**
	 * Register AJAX handlers.
	 *
	 * Hooks all AJAX endpoints to appropriate handler methods.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function register_ajax_handlers() {
		add_action( 'wp_ajax_gcl_get_status', array( __CLASS__, 'ajax_get_status' ) );
		add_action( 'wp_ajax_gcl_move_to_top', array( __CLASS__, 'ajax_move_to_top' ) );
		add_action( 'wp_ajax_gcl_move_up', array( __CLASS__, 'ajax_move_up' ) );
		add_action( 'wp_ajax_gcl_remove_job', array( __CLASS__, 'ajax_remove_job' ) );
		add_action( 'wp_ajax_gcl_clear_all_queues', array( __CLASS__, 'ajax_clear_all_queues' ) );
		add_action( 'wp_ajax_gcl_clear_all_locks', array( __CLASS__, 'ajax_clear_all_locks' ) );
		add_action( 'wp_ajax_gcl_reset_status', array( __CLASS__, 'ajax_reset_status' ) );
		add_action( 'wp_ajax_gcl_clear_queue', array( __CLASS__, 'ajax_clear_queue' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * Loads CSS and JavaScript for the admin dashboard.
	 * Only enqueued on the Global Cron Lock admin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_global-cron-lock' !== $hook ) {
			return;
		}

		// Enqueue jQuery (included by WordPress by default on admin).
		wp_enqueue_script( 'jquery' );

		// Add nonce for AJAX.
		$nonce = wp_create_nonce( 'gcl_ajax_nonce' );
		wp_localize_script(
			'jquery',
			'gclAjax',
			array(
				'nonce' => $nonce,
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Get status file with file locking.
	 *
	 * Safely reads the status file with exclusive lock.
	 *
	 * @return array Array containing file pointer and data.
	 *
	 * @since 1.0.0
	 */
	private static function get_status_file() {
		$status_file = Global_Cron_Lock::cfg( 'status_file' );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Necessary for file locking.
		$fp = @fopen( $status_file, 'c+' );
		if ( ! $fp ) {
			return array( 'fp' => null, 'data' => array() );
		}

		@flock( $fp, LOCK_EX );
		rewind( $fp );
		$data = json_decode( stream_get_contents( $fp ), true );

		return array(
			'fp' => $fp,
			'data' => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Save status file with file locking.
	 *
	 * Safely writes the status file with exclusive lock.
	 *
	 * @param array $read Array containing file pointer and data from get_status_file().
	 * @return bool True on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	private static function save_status_file( $read ) {
		if ( empty( $read['fp'] ) ) {
			return false;
		}

		$fp = $read['fp'];
		rewind( $fp );
		ftruncate( $fp, 0 );
		fwrite( $fp, wp_json_encode( $read['data'], JSON_PRETTY_PRINT ) );
		fflush( $fp );
		@flock( $fp, LOCK_UN );
		fclose( $fp );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
		@chmod( Global_Cron_Lock::cfg( 'status_file' ), 0664 );
		return true;
	}

	/**
	 * AJAX Handler: Get current queue and lock status.
	 *
	 * Returns JSON with current status file data and all active lock files.
	 * Requires 'manage_options' capability and valid nonce.
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_get_status() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$status_file = Global_Cron_Lock::cfg( 'status_file' );
		$status = array();

		if ( file_exists( $status_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Necessary for status file reading.
			$content = @file_get_contents( $status_file );
			$status = json_decode( $content, true );
		}

		$lock_dir = Global_Cron_Lock::cfg( 'lock_dir' );
		// phpcs:ignore WordPress.PHP.NoHardcodedWildcards.Wildcards -- Glob pattern is intentional for lock file scanning.
		$locks = glob( $lock_dir . '/*.lock' ) ?: array();
		$lock_info = array();

		foreach ( $locks as $lock_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Necessary for lock file reading.
			$lock_content = @file_get_contents( $lock_file );
			$lock_data = json_decode( $lock_content, true );
			if ( $lock_data ) {
				$lock_info[] = array(
					'file' => basename( $lock_file ),
					'data' => $lock_data,
				);
			}
		}

		wp_send_json_success(
			array(
				'status' => $status,
				'locks' => $lock_info,
				'per_event_locking' => Global_Cron_Lock::cfg( 'per_event_locking' ),
			)
		);
	}

	/**
	 * AJAX Handler: Move job to top of queue.
	 *
	 * Relocates specified job to the front of its queue (next to execute).
	 * Works for both per-event and global queue modes.
	 *
	 * POST parameters:
	 *   - event: (string) Event name
	 *   - site_url: (string) Site URL
	 *   - nonce: (string) AJAX nonce
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_move_to_top() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$event_name = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		if ( empty( $event_name ) || empty( $site_url ) ) {
			wp_send_json_error( array( 'message' => 'Event and site URL required' ) );
		}

		$read = self::get_status_file();
		if ( ! $read['fp'] ) {
			wp_send_json_error( array( 'message' => 'Could not open status file' ) );
		}

		$data = $read['data'];
		$found = false;

		if ( $per_event ) {
			// Per-event mode.
			if ( ! isset( $data['events'][ $event_name ]['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['events'][ $event_name ]['queue'];
			$key = self::find_job_in_queue( $queue, $site_url );

			if ( -1 === $key || 0 === $key ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Job not found or already at top' ) );
			}

			$job = array_splice( $queue, $key, 1 );
			array_unshift( $queue, $job[0] );
			$data['events'][ $event_name ]['queue'] = $queue;
			$found = true;

		} else {
			// Global mode.
			if ( ! isset( $data['global']['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['global']['queue'];
			$key = self::find_job_in_queue( $queue, $site_url, $event_name );

			if ( -1 === $key || 0 === $key ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Job not found or already at top' ) );
			}

			$job = array_splice( $queue, $key, 1 );
			array_unshift( $queue, $job[0] );
			$data['global']['queue'] = $queue;
			$found = true;
		}

		if ( $found ) {
			$read['data'] = $data;
			self::save_status_file( $read );
			Global_Cron_Lock::log( sprintf( 'Admin moved %s to top for %s', $event_name, $site_url ) );
			wp_send_json_success( array( 'message' => 'Job moved to top' ) );
		}

		self::save_status_file( $read );
		wp_send_json_error( array( 'message' => 'Could not move job' ) );
	}

	/**
	 * AJAX Handler: Move job up one position.
	 *
	 * Swaps specified job with the one directly above it in the queue.
	 * Works for both per-event and global queue modes.
	 *
	 * POST parameters:
	 *   - event: (string) Event name
	 *   - site_url: (string) Site URL
	 *   - nonce: (string) AJAX nonce
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_move_up() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$event_name = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		if ( empty( $event_name ) || empty( $site_url ) ) {
			wp_send_json_error( array( 'message' => 'Event and site URL required' ) );
		}

		$read = self::get_status_file();
		if ( ! $read['fp'] ) {
			wp_send_json_error( array( 'message' => 'Could not open status file' ) );
		}

		$data = $read['data'];
		$found = false;

		if ( $per_event ) {
			// Per-event mode.
			if ( ! isset( $data['events'][ $event_name ]['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['events'][ $event_name ]['queue'];
			$key = self::find_job_in_queue( $queue, $site_url );

			if ( -1 === $key || 0 === $key ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Job not found or already at top' ) );
			}

			$temp = $queue[ $key - 1 ];
			$queue[ $key - 1 ] = $queue[ $key ];
			$queue[ $key ] = $temp;
			$data['events'][ $event_name ]['queue'] = $queue;
			$found = true;

		} else {
			// Global mode.
			if ( ! isset( $data['global']['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['global']['queue'];
			$key = self::find_job_in_queue( $queue, $site_url, $event_name );

			if ( -1 === $key || 0 === $key ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Job not found or already at top' ) );
			}

			$temp = $queue[ $key - 1 ];
			$queue[ $key - 1 ] = $queue[ $key ];
			$queue[ $key ] = $temp;
			$data['global']['queue'] = $queue;
			$found = true;
		}

		if ( $found ) {
			$read['data'] = $data;
			self::save_status_file( $read );
			Global_Cron_Lock::log( sprintf( 'Admin moved %s up for %s', $event_name, $site_url ) );
			wp_send_json_success( array( 'message' => 'Job moved up' ) );
		}

		self::save_status_file( $read );
		wp_send_json_error( array( 'message' => 'Could not move job' ) );
	}

	/**
	 * AJAX Handler: Remove job from queue.
	 *
	 * Permanently removes a queued job. Works for both per-event and global modes.
	 *
	 * POST parameters:
	 *   - event: (string) Event name
	 *   - site_url: (string) Site URL
	 *   - nonce: (string) AJAX nonce
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_remove_job() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$event_name = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		if ( empty( $event_name ) || empty( $site_url ) ) {
			wp_send_json_error( array( 'message' => 'Event and site URL required' ) );
		}

		$read = self::get_status_file();
		if ( ! $read['fp'] ) {
			wp_send_json_error( array( 'message' => 'Could not open status file' ) );
		}

		$data = $read['data'];

		if ( $per_event ) {
			// Per-event mode.
			if ( ! isset( $data['events'][ $event_name ]['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['events'][ $event_name ]['queue'];
			$data['events'][ $event_name ]['queue'] = array_values(
				array_filter(
					$queue,
					function ( $job ) use ( $site_url ) {
						return ( $job['site_url'] ?? '' ) !== $site_url;
					}
				)
			);

		} else {
			// Global mode.
			if ( ! isset( $data['global']['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Queue not found' ) );
			}

			$queue = $data['global']['queue'];
			$data['global']['queue'] = array_values(
				array_filter(
					$queue,
					function ( $job ) use ( $site_url, $event_name ) {
						return ! ( ( $job['site_url'] ?? '' ) === $site_url && ( $job['event'] ?? '' ) === $event_name );
					}
				)
			);
		}

		$read['data'] = $data;
		self::save_status_file( $read );
		Global_Cron_Lock::log( sprintf( 'Admin removed %s from queue for %s', $event_name, $site_url ) );
		wp_send_json_success( array( 'message' => 'Job removed from queue' ) );
	}

	/**
	 * AJAX Handler: Clear queue for a specific event or global queue.
	 *
	 * Removes all pending jobs from a specific event queue (per-event mode)
	 * or the entire global queue (global mode) when event is '__global__'.
	 *
	 * Called by:
	 *   - #gcl-clear-queue button (per-event mode)
	 *   - #gcl-clear-queue-global button (global mode)
	 *
	 * POST parameters:
	 *   - event: (string) Event name or '__global__' for global mode
	 *   - nonce: (string) AJAX nonce
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_clear_queue() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$event_name = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		if ( empty( $event_name ) ) {
			wp_send_json_error( array( 'message' => 'Event name required' ) );
		}

		$read = self::get_status_file();
		if ( ! $read['fp'] ) {
			wp_send_json_error( array( 'message' => 'Could not open status file' ) );
		}

		$data = $read['data'];
		$cleared = 0;

		if ( $per_event ) {
			// Per-event mode - clear specific event queue.
			if ( ! isset( $data['events'][ $event_name ]['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Event queue not found' ) );
			}

			$cleared = count( $data['events'][ $event_name ]['queue'] );
			$data['events'][ $event_name ]['queue'] = array();

		} else {
			// Global mode - only clear if event is '__global__'.
			if ( '__global__' !== $event_name ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Invalid event name for global mode' ) );
			}

			if ( ! isset( $data['global']['queue'] ) ) {
				self::save_status_file( $read );
				wp_send_json_error( array( 'message' => 'Global queue not found' ) );
			}

			$cleared = count( $data['global']['queue'] );
			$data['global']['queue'] = array();
		}

		$read['data'] = $data;
		self::save_status_file( $read );
		Global_Cron_Lock::log( sprintf( 'Admin cleared %d job(s) from %s queue', $cleared, $event_name ) );
		wp_send_json_success( array( 'message' => sprintf( 'Cleared %d job(s) from queue', $cleared ) ) );
	}

	/**
	 * AJAX Handler: Clear all queues.
	 *
	 * Removes all pending jobs from all queues. Works for both per-event and global modes.
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_clear_all_queues() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );
		$read = self::get_status_file();

		if ( ! $read['data'] ) {
			if ( $read['fp'] ) {
				self::save_status_file( $read );
			}
			wp_send_json_success( array( 'message' => 'All queues cleared' ) );
			return;
		}

		$data = $read['data'];

		if ( $per_event ) {
			if ( isset( $data['events'] ) ) {
				foreach ( $data['events'] as $event_name => $event_data ) {
					$data['events'][ $event_name ]['queue'] = array();
				}
			}
		} else {
			if ( isset( $data['global'] ) ) {
				$data['global']['queue'] = array();
			}
		}

		$read['data'] = $data;
		self::save_status_file( $read );
		Global_Cron_Lock::log( 'Admin cleared all queues' );
		wp_send_json_success( array( 'message' => 'All queues cleared' ) );
	}

	/**
	 * AJAX Handler: Clear all lock files.
	 *
	 * Removes all active lock files from the lock directory.
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_clear_all_locks() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$lock_dir = Global_Cron_Lock::cfg( 'lock_dir' );
		// phpcs:ignore WordPress.PHP.NoHardcodedWildcards.Wildcards -- Glob pattern is intentional for lock file scanning.
		$locks = glob( $lock_dir . '/*.lock' ) ?: array();
		$removed = 0;

		foreach ( $locks as $lock_file ) {
			if ( wp_delete_file( $lock_file ) ) {
				$removed++;
			}
		}

		Global_Cron_Lock::log( sprintf( 'Admin removed %d lock files', $removed ) );
		wp_send_json_success( array( 'message' => sprintf( 'Removed %d lock file(s)', $removed ) ) );
	}

	/**
	 * AJAX Handler: Reset status file.
	 *
	 * Reinitializes status file to clean state (empty queues, idle status).
	 * Works for both per-event and global modes.
	 *
	 * @return void JSON response via wp_send_json_success or wp_send_json_error.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_reset_status() {
		check_ajax_referer( 'gcl_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$status_file = Global_Cron_Lock::cfg( 'status_file' );
		$per_event = Global_Cron_Lock::cfg( 'per_event_locking' );

		$status = $per_event
			? array( 'events' => array() )
			: array(
				'global' => array(
					'status' => 'idle',
					'queue' => array(),
					'timestamp' => gmdate( 'c' ),
				),
			);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Necessary for status file operations.
		@file_put_contents( $status_file, wp_json_encode( $status, JSON_PRETTY_PRINT ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Required for permission setup.
		@chmod( $status_file, 0664 );

		Global_Cron_Lock::log( 'Admin reset status file' );
		wp_send_json_success( array( 'message' => 'Status file reset' ) );
	}

	/**
	 * Find a job in queue by site URL (and optionally event name).
	 *
	 * Helper method to locate a job's position in the queue.
	 *
	 * @param array  $queue Queue array.
	 * @param string $site_url Site URL to find.
	 * @param string $event_name Optional event name for global mode matching.
	 * @return int Job index or -1 if not found.
	 *
	 * @since 1.0.0
	 */
	private static function find_job_in_queue( $queue, $site_url, $event_name = '' ) {
		foreach ( $queue as $index => $job ) {
			if ( ( $job['site_url'] ?? '' ) === $site_url ) {
				// Per-event mode or global with matching event.
				if ( empty( $event_name ) || ( $job['event'] ?? '' ) === $event_name ) {
					return $index;
				}
			}
		}
		return -1;
	}

	/**
	 * Render the Global Cron Lock admin page.
	 *
	 * Displays dashboard with real-time queue status, lock information,
	 * and controls for manual job management. Inlines JavaScript for admin page.
	 *
	 * @return void HTML output.
	 *
	 * @since 1.0.0
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'global-cron-lock' ) );
		}

		$per_event_locking = Global_Cron_Lock::cfg( 'per_event_locking' );
		$nonce = wp_create_nonce( 'gcl_ajax_nonce' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '🔒 Global Cron Lock Queue Management', 'global-cron-lock' ); ?></h1>

			<div class="gcl-mode-indicator" style="margin: 20px 0; padding: 12px; background: <?php echo $per_event_locking ? '#d4edda' : '#cce5ff'; ?>; border-left: 4px solid <?php echo $per_event_locking ? '#28a745' : '#007bff'; ?>; border-radius: 4px;">
				<strong><?php esc_html_e( 'Mode:', 'global-cron-lock' ); ?></strong> <?php echo $per_event_locking ? esc_html__( '📋 Per-Event Locking', 'global-cron-lock' ) : esc_html__( '🌐 Global Locking', 'global-cron-lock' ); ?>
				<span style="margin-left: 20px; color: #666;">
					<?php echo $per_event_locking ? esc_html__( 'Each event has its own lock and queue', 'global-cron-lock' ) : esc_html__( 'All events share one lock and unified queue', 'global-cron-lock' ); ?>
				</span>
			</div>

			<div class="gcl-stats" style="display: flex; gap: 20px; margin: 20px 0;">
				<div class="gcl-stat-box" style="flex: 1; padding: 20px; background: white; border: 1px solid #ccc; border-radius: 4px;">
					<div style="font-size: 32px; font-weight: bold; color: #2271b1;"><span id="gcl-stat-running">0</span></div>
					<div style="color: #666;"><?php esc_html_e( 'Running Jobs', 'global-cron-lock' ); ?></div>
				</div>
				<div class="gcl-stat-box" style="flex: 1; padding: 20px; background: white; border: 1px solid #ccc; border-radius: 4px;">
					<div style="font-size: 32px; font-weight: bold; color: #d63638;"><span id="gcl-stat-queued">0</span></div>
					<div style="color: #666;"><?php esc_html_e( 'Queued Jobs', 'global-cron-lock' ); ?></div>
				</div>
				<div class="gcl-stat-box" style="flex: 1; padding: 20px; background: white; border: 1px solid #ccc; border-radius: 4px;">
					<div style="font-size: 32px; font-weight: bold; color: #00a32a;"><span id="gcl-stat-locks">0</span></div>
					<div style="color: #666;"><?php esc_html_e( 'Active Locks', 'global-cron-lock' ); ?></div>
				</div>
			</div>

			<div class="gcl-actions" style="margin: 20px 0;">
				<button class="button button-secondary" id="gcl-refresh"><?php esc_html_e( '🔄 Refresh', 'global-cron-lock' ); ?></button>
				<button class="button" id="gcl-clear-all-queues"><?php esc_html_e( '🗑️ Clear All Queues', 'global-cron-lock' ); ?></button>
				<button class="button" id="gcl-clear-all-locks"><?php esc_html_e( '🔓 Clear All Locks', 'global-cron-lock' ); ?></button>
				<button class="button" id="gcl-reset-status"><?php esc_html_e( '🔄 Reset Status', 'global-cron-lock' ); ?></button>
				<span class="spinner" id="gcl-spinner" style="float: none; margin: 0 10px;"></span>
			</div>

			<div id="gcl-notice" style="display: none;"></div>
			<div id="gcl-events-container"></div>

			<div style="margin-top: 40px; padding: 20px; background: #f5f5f5; border-radius: 4px;">
				<h3><?php esc_html_e( 'ℹ️ About This Tool', 'global-cron-lock' ); ?></h3>
				<p><strong><?php esc_html_e( 'What it does:', 'global-cron-lock' ); ?></strong> <?php esc_html_e( 'This plugin prevents multiple WordPress sites from running the same heavy cron job simultaneously by using file-based locks and queues.', 'global-cron-lock' ); ?></p>
				<p><strong><?php esc_html_e( 'Current Mode:', 'global-cron-lock' ); ?></strong>
				<?php
				if ( $per_event_locking ) {
					echo wp_kses_post(
						'<strong>' . esc_html__( 'Per-Event Locking', 'global-cron-lock' ) . '</strong> - ' .
						esc_html__( 'Each event has its own lock file and queue. Sites compete independently for each event type.', 'global-cron-lock' )
					);
				} else {
					echo wp_kses_post(
						'<strong>' . esc_html__( 'Global Locking', 'global-cron-lock' ) . '</strong> - ' .
						esc_html__( 'All events share one lock file and one unified queue. Only ONE job can run at a time across all events and sites.', 'global-cron-lock' )
					);
				}
				?>
				</p>
			</div>
		</div>

		<style>
			.gcl-event-card { background: white; border: 1px solid #ccc; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
			.gcl-event-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0; cursor: pointer; user-select: none; }
			.gcl-event-header:hover { background-color: #f9f9f9; margin: -10px -10px 15px -10px; padding: 10px 10px 15px 10px; border-radius: 4px; }
			.gcl-event-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
			.gcl-toggle-icon { display: inline-block; transition: transform 0.3s ease; font-size: 14px; }
			.gcl-toggle-icon.collapsed { transform: rotate(-90deg); }
			.gcl-event-content { overflow: hidden; transition: max-height 0.3s ease, opacity 0.3s ease; }
			.gcl-event-content.collapsed { max-height: 0 !important; opacity: 0; }
			.gcl-status-badge { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; }
			.gcl-status-running { background: #d4edda; color: #155724; }
			.gcl-status-idle { background: #f8f9fa; color: #6c757d; }
			.gcl-current-job { background: #e7f3ff; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
			.gcl-queue-info { display: flex; gap: 20px; margin: 15px 0; font-size: 14px; }
			.gcl-table-container { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; margin-top: 15px; }
			.gcl-table-container table { margin-bottom: 0; }
			.wp-list-table th { font-weight: 600; position: sticky; top: 0; background: #f0f0f0; z-index: 10; }
			.gcl-action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
			.gcl-action-buttons button { padding: 4px 8px; font-size: 11px; white-space: nowrap; }
			#gcl-notice { padding: 12px; margin: 15px 0; border-radius: 4px; }
			#gcl-notice.success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
			#gcl-notice.error { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
			.gcl-mode-badge {background: #2271b1;color: white;padding: 8px 16px;border-radius: 4px;margin-bottom: 20px;display: inline-block;font-weight: 600;}
		</style>

		<script>
			jQuery(document).ready(function($) {
			var autoRefresh = null;
			var collapsedEvents = {};

			function loadStatus() {
				$('#gcl-spinner').addClass('is-active');

				$.post(ajaxurl, {
					action: 'gcl_get_status',
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					$('#gcl-spinner').removeClass('is-active');

					if (response.success) {
						renderStatus(response.data);
					} else {
						showNotice(response.data.message, 'error');
					}
				}).fail(function() {
					$('#gcl-spinner').removeClass('is-active');
					showNotice('Failed to load status', 'error');
				});
			}

			function renderStatus(data) {
				var perEventLocking = data.per_event_locking;

				if (!perEventLocking && data.status.global) {
					renderGlobalQueue(data);
				} else {
					renderPerEventQueues(data);
				}

				updateStats(data);
			}

			function renderGlobalQueue(data) {
				var global = data.status.global || {};
				var status = global.status || 'idle';
				var queue = global.queue || [];
				var currentSite = global.current_site || 'None';
				var currentEvent = global.current_event || 'N/A';

				var html = '<div class="gcl-mode-badge">🌐 Global Lock Mode - All events share one queue</div>';
				html += '<div class="gcl-event-card">';
				html += '<div class="gcl-event-header" style="cursor: default;">';
				html += '<h3>Global Queue</h3>';
				html += '<span class="gcl-status-badge ' + (status === 'running' ? 'gcl-status-running' : 'gcl-status-idle') + '">';
				html += (status === 'running' ? '▶️ Running' : '⏸️ Idle');
				html += '</span>';
				html += '</div>';

				if (status === 'running') {
					html += '<div class="gcl-current-job">';
					html += '<strong>Currently Running:</strong> ' + currentSite + ' - <code>' + currentEvent + '</code>';
					html += '</div>';
				}

				html += '<div class="gcl-queue-info">';
				html += '<span><strong>' + queue.length + '</strong> jobs in queue</span>';
				html += '</div>';

				if (queue.length > 0) {
					html += '<div class="gcl-actions">';
					html += '<button class="button" id="gcl-clear-queue-global">🗑️ Clear Queue</button>';
					html += '</div>';
				}

				html += renderGlobalQueueTable(queue);
				html += '</div>';

				$('#gcl-events-container').html(html);
			}

			function renderGlobalQueueTable(queue) {
				if (queue.length === 0) {
					return '<p class="gcl-empty-queue">Queue is empty</p>';
				}

				var html = '<div class="gcl-table-container">';
				html += '<table class="wp-list-table widefat fixed striped">';
				html += '<thead><tr>';
				html += '<th style="width: 40px;">Pos</th>';
				html += '<th style="width: 150px;">Event</th>';
				html += '<th>Site/Blog</th>';
				html += '<th style="width: 150px;">Queued At</th>';
				html += '<th style="width: 280px;">Actions</th>';
				html += '</tr></thead><tbody>';

				for (var i = 0; i < queue.length; i++) {
					var job = queue[i];
					var queuedAt = new Date(job.queued_at).toLocaleString();
					html += '<tr>';
					html += '<td><strong>#' + (i + 1) + '</strong></td>';
					html += '<td><code style="font-size: 11px;">' + job.event + '</code></td>';
					html += '<td>' + job.site + '</td>';
					html += '<td style="font-size: 12px;">' + queuedAt + '</td>';
					html += '<td>';
					html += '<div class="gcl-action-buttons">';
					if (i > 0) {
						html += '<button class="button button-small gcl-move-to-top" data-event="' + job.event + '" data-site-url="' + job.site_url + '" title="Move to top">⬆️⬆️ Top</button>';
						html += '<button class="button button-small gcl-move-up" data-event="' + job.event + '" data-site-url="' + job.site_url + '" title="Move up one position">⬆️ Up</button>';
					}
					html += '<button class="button button-small gcl-remove-job" data-event="' + job.event + '" data-site-url="' + job.site_url + '" title="Remove from queue">❌ Remove</button>';
					html += '</div>';
					html += '</td>';
					html += '</tr>';
				}

				html += '</tbody></table>';
				html += '</div>';
				return html;
			}

			function renderPerEventQueues(data) {
				var events = data.status.events || {};

				if (Object.keys(events).length === 0) {
					$('#gcl-events-container').html('<p class="gcl-empty-queue">No events configured yet. Events will appear here once cron jobs start running.</p>');
					return;
				}

				var html = '<div class="gcl-mode-badge">📋 Per-Event Lock Mode - Each event has its own queue</div>';

				for (var eventName in events) {
					if (!events.hasOwnProperty(eventName)) continue;

					var event = events[eventName];
					var status = event.status || 'idle';
					var queue = event.queue || [];
					var currentSite = event.site || 'None';
					var isCollapsed = collapsedEvents[eventName] || false;

					html += '<div class="gcl-event-card">';
					html += '<div class="gcl-event-header" data-event="' + eventName + '">';
					html += '<h3>';
					html += '<span class="gcl-toggle-icon ' + (isCollapsed ? 'collapsed' : '') + '">▼</span>';
					html += '<code>' + eventName + '</code>';
					if (queue.length > 0) {
						html += '<span style="font-size: 12px; color: #666; font-weight: normal; margin-left: 10px;">(' + queue.length + ' in queue)</span>';
					}
					html += '</h3>';
					html += '<span class="gcl-status-badge gcl-status-' + status + '">';
					html += (status === 'running' ? '▶️' : status === 'waiting' ? '⏸️' : '⏸️') + ' ' + status;
					html += '</span>';
					html += '</div>';

					html += '<div class="gcl-event-content' + (isCollapsed ? ' collapsed' : '') + '" data-event="' + eventName + '">';

					if (status === 'running') {
						html += '<div class="gcl-current-job">';
						html += '<strong>Currently Running:</strong> ' + currentSite;
						html += '</div>';
					}

					html += '<div class="gcl-queue-info">';
					html += '<span><strong>' + queue.length + '</strong> jobs in queue</span>';
					html += '</div>';

					if (queue.length > 0) {
						html += '<div class="gcl-actions">';
						html += '<button class="button" id="gcl-clear-queue" data-event="' + eventName + '">🗑️ Clear Queue</button>';
						html += '</div>';
					}

					html += renderPerEventQueueTable(queue, eventName);
					html += '</div>';
					html += '</div>';
				}

				$('#gcl-events-container').html(html);

				$('.gcl-event-content:not(.collapsed)').each(function() {
					$(this).css('max-height', $(this).prop('scrollHeight') + 'px');
				});
			}

			function renderPerEventQueueTable(queue, eventName) {
				if (queue.length === 0) {
					return '<p class="gcl-empty-queue">Queue is empty</p>';
				}

				var html = '<div class="gcl-table-container">';
				html += '<table class="wp-list-table widefat fixed striped">';
				html += '<thead><tr>';
				html += '<th style="width: 40px;">Pos</th>';
				html += '<th>Site/Blog</th>';
				html += '<th style="width: 150px;">Queued At</th>';
				html += '<th style="width: 280px;">Actions</th>';
				html += '</tr></thead><tbody>';

				for (var i = 0; i < queue.length; i++) {
					var job = queue[i];
					var queuedAt = new Date(job.queued_at).toLocaleString();
					html += '<tr>';
					html += '<td><strong>#' + (i + 1) + '</strong></td>';
					html += '<td>' + job.site + '</td>';
					html += '<td style="font-size: 12px;">' + queuedAt + '</td>';
					html += '<td>';
					html += '<div class="gcl-action-buttons">';
					if (i > 0) {
						html += '<button class="button button-small gcl-move-to-top" data-event="' + eventName + '" data-site-url="' + job.site_url + '" title="Move to top">⬆️⬆️ Top</button>';
						html += '<button class="button button-small gcl-move-up" data-event="' + eventName + '" data-site-url="' + job.site_url + '" title="Move up one position">⬆️ Up</button>';
					}
					html += '<button class="button button-small gcl-remove-job" data-event="' + eventName + '" data-site-url="' + job.site_url + '" title="Remove from queue">❌ Remove</button>';
					html += '</div>';
					html += '</td>';
					html += '</tr>';
				}

				html += '</tbody></table>';
				html += '</div>';
				return html;
			}

			function updateStats(data) {
				var perEventLocking = data.per_event_locking;
				var runningCount = 0;
				var queuedCount = 0;

				if (perEventLocking) {
					var events = data.status.events || {};
					for (var eventName in events) {
						if (!events.hasOwnProperty(eventName)) continue;
						var event = events[eventName];
						if (event.status === 'running') runningCount++;
						queuedCount += (event.queue || []).length;
					}
				} else {
					var global = data.status.global || {};
					if (global.status === 'running') runningCount = 1;
					queuedCount = (global.queue || []).length;
				}

				$('#gcl-stat-running').text(runningCount);
				$('#gcl-stat-queued').text(queuedCount);
				$('#gcl-stat-locks').text((data.locks || []).length);
			}

			function showNotice(message, type) {
				$('#gcl-notice')
					.removeClass('success error')
					.addClass(type)
					.text(message)
					.show()
					.delay(4000)
					.fadeOut();
			}

			// Toggle event collapse/expand
			$(document).on('click', '.gcl-event-header', function(e) {
				if ($(this).find('.gcl-toggle-icon').length === 0) return;

				var eventName = $(this).data('event');
				var content = $('.gcl-event-content[data-event="' + eventName + '"]');
				var icon = $(this).find('.gcl-toggle-icon');

				if (content.hasClass('collapsed')) {
					content.removeClass('collapsed');
					content.css('max-height', content.prop('scrollHeight') + 'px');
					icon.removeClass('collapsed');
					collapsedEvents[eventName] = false;
				} else {
					content.addClass('collapsed');
					icon.addClass('collapsed');
					collapsedEvents[eventName] = true;
				}
			});

			// Event handlers
			$('#gcl-refresh').on('click', loadStatus);

			$('#gcl-clear-all-queues').on('click', function() {
				if (!confirm('Clear ALL queues? This will remove all pending jobs.')) return;

				$.post(ajaxurl, {
					action: 'gcl_clear_all_queues',
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$('#gcl-clear-all-locks').on('click', function() {
				if (!confirm('Clear ALL locks? This should only be done if locks are stuck.')) return;

				$.post(ajaxurl, {
					action: 'gcl_clear_all_locks',
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$('#gcl-reset-status').on('click', function() {
				if (!confirm('Reset status file? This will clear all queue and status data.')) return;

				$.post(ajaxurl, {
					action: 'gcl_reset_status',
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			// Queue manipulation handlers
			$(document).on('click', '.gcl-move-to-top', function() {
				var event = $(this).data('event');
				var siteUrl = $(this).data('site-url');

				$.post(ajaxurl, {
					action: 'gcl_move_to_top',
					event: event,
					site_url: siteUrl,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$(document).on('click', '.gcl-move-up', function() {
				var event = $(this).data('event');
				var siteUrl = $(this).data('site-url');

				$.post(ajaxurl, {
					action: 'gcl_move_up',
					event: event,
					site_url: siteUrl,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$(document).on('click', '.gcl-remove-job', function() {
				var event = $(this).data('event');
				var siteUrl = $(this).data('site-url');
				if (!confirm('Remove this job from queue?')) return;

				$.post(ajaxurl, {
					action: 'gcl_remove_job',
					event: event,
					site_url: siteUrl,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$(document).on('click', '#gcl-clear-queue-global', function() {
				if (!confirm('Clear the entire global queue?')) return;

				$.post(ajaxurl, {
					action: 'gcl_clear_queue',
					event: '__global__',
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			$(document).on('click', '#gcl-clear-queue', function() {
				var event = $(this).data('event');
				if (!confirm('Clear queue for ' + event + '?')) return;

				$.post(ajaxurl, {
					action: 'gcl_clear_queue',
					event: event,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(response) {
					if (response.success) {
						showNotice(response.data.message, 'success');
						loadStatus();
					} else {
						showNotice(response.data.message, 'error');
					}
				});
			});

			// Initial load and auto-refresh
			loadStatus();
			autoRefresh = setInterval(loadStatus, 5000);
		});
		</script>
		<?php
	}
}

// Initialize the admin interface.
if ( is_admin() ) {
	GCL_Admin::init();
}