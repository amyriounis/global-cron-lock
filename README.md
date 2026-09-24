# Global Cron Lock

**Global Cron Lock** is a WordPress plugin designed for environments where multiple WordPress installations need to execute the same resource-intensive cron jobs without running them concurrently.

It provides a lightweight, file-based locking and queueing mechanism that coordinates cron execution across multiple WordPress installations hosted on the same server.

## The Problem

When multiple WordPress sites share the same server and perform the same resource-intensive background task, their cron jobs can execute simultaneously.

For example:

```text
Site A ──┐
Site B ──┤
Site C ──┼──> Heavy Cron Job
Site D ──┘
```

If every site starts the job at the same time, this can result in:

* High CPU and memory usage
* Database contention
* Increased server load
* Longer execution times
* Multiple processes performing the same expensive operation simultaneously

Global Cron Lock turns this into a controlled execution queue:

```text
Site A ──> 🔒 Running
Site B ──> ⏳ Waiting
Site C ──> ⏳ Waiting
Site D ──> ⏳ Waiting
              │
              ▼
         Site A finishes
              │
              ▼
         Site B runs
              │
              ▼
         Site C runs
```

## Features

* 🔒 **Cross-site file-based locking**
* 📋 **FIFO-style queue management**
* 🔄 **Automatic triggering of queued jobs**
* 🧹 **Automatic stale-lock detection and cleanup**
* ⚙️ **Per-event or global locking modes**
* 📊 **JSON-based execution status**
* 📝 **Detailed execution logging**
* 🚀 **WP-CLI and HTTP trigger support**
* 🔁 **Automatic fallback between trigger methods**
* 🛡️ **Protection against duplicate queue entries**
* ⏱️ **Configurable queue delays and lock timeouts**
* 🔌 **Designed to work with existing WordPress cron events**

## How It Works

The plugin intercepts configured public cron events and attempts to acquire a filesystem lock before allowing the associated task to execute.

The lock is acquired using PHP's `flock()` with a non-blocking exclusive lock. If another site is already processing the event, the new request is placed into a queue instead of executing immediately.

Once the currently running process finishes, a shutdown handler releases the lock and triggers the next queued job.

```text
                 Cron Event
                     │
                     ▼
              Try to acquire lock
                     │
            ┌────────┴────────┐
            │                 │
         Success             Locked
            │                 │
            ▼                 ▼
         Run job        Add to queue
            │                 │
            ▼                 │
      Release lock            │
            │                 │
            └────────┬────────┘
                     ▼
             Trigger next job
```

The plugin also records information about the current process, including the site URL, event name, timestamp, and process ID.

## Locking Modes

### Per-event locking

The default configuration uses a separate lock for each cron event:

```php
'per_event_locking' => true,
```

This allows unrelated cron jobs to execute independently.

For example:

```text
Event A
├── Site 1 → Running
├── Site 2 → Waiting
└── Site 3 → Waiting

Event B
├── Site 4 → Running
└── Site 5 → Waiting
```

This is useful when different cron jobs are independent of each other.

### Global locking

Global locking can be enabled by setting:

```php
'per_event_locking' => false,
```

In this mode, all configured events share a single global lock and queue.

```text
Global Queue
────────────────────────────
Site A → Event A
Site B → Event B
Site C → Event A
Site D → Event C
────────────────────────────
          ↓
      One at a time
```

This is useful when the primary goal is to limit the overall background workload across a group of sites.

## Configuration

The plugin keeps its configuration in the main plugin class:

```php
private static $config = array(
    'lock_dir' => GCL_PLUGIN_DIR,
    'status_file' => GCL_PLUGIN_DIR . '/global-cron-status.json',
    'log_file' => GCL_PLUGIN_DIR . '/global-cron.log',

    'per_event_locking' => true,

    'max_lock_age' => 300,

    'base_delay' => 5,
    'max_delay' => 35,

    'trigger_method' => 'both',

    'wp_cli_path' => '/usr/local/bin/wp',

    'site_paths' => array(),

    'locked_events' => array(),
);
```

### Configuration options

| Option              | Description                                   |                   Default |
| ------------------- | --------------------------------------------- | ------------------------: |
| `lock_dir`          | Directory used for lock files                 |          Plugin directory |
| `status_file`       | JSON file containing queue/status information | `global-cron-status.json` |
| `log_file`          | Execution log file                            |         `global-cron.log` |
| `per_event_locking` | Use independent locks per event               |                    `true` |
| `max_lock_age`      | Maximum age before a lock is considered stale |                    `300s` |
| `base_delay`        | Base delay between queued executions          |                      `5s` |
| `max_delay`         | Maximum queue retry delay                     |                     `35s` |
| `trigger_method`    | `http`, `wpcli`, or `both`                    |                    `both` |
| `wp_cli_path`       | Path to the WP-CLI binary                     |       `/usr/local/bin/wp` |
| `site_paths`        | Explicit site URL → WordPress path mappings   |                      `[]` |
| `locked_events`     | Public cron event → internal event mappings   |                      `[]` |

## Configuring Locked Events

The `locked_events` configuration maps a public cron event to the actual internal WordPress action that should execute once the lock has been acquired.

Example:

```php
'locked_events' => array(
    'my_public_cron_event' => 'my_internal_cron_event',
),
```

The public event is responsible for entering the locking and queueing mechanism, while the internal event is executed only after the lock has been successfully acquired.

## Trigger Methods

After a job finishes, Global Cron Lock needs to start the next queued job.

It supports three modes.

### HTTP

```php
'trigger_method' => 'http',
```

The plugin makes a non-blocking request to the target site's `wp-cron.php`.

### WP-CLI

```php
'trigger_method' => 'wpcli',
```

The plugin uses WP-CLI to execute the cron event asynchronously.

### Both

```php
'trigger_method' => 'both',
```

WP-CLI is attempted first, with HTTP used as a fallback when necessary.

This allows the implementation to work across different hosting configurations while taking advantage of WP-CLI where it is available.

## Queue Management

When a site cannot acquire the lock, it is added to the appropriate queue.

Duplicate entries for the same site/event combination are prevented.

Queued jobs are scheduled using progressively increasing delays:

```text
Position 0 → 5s
Position 1 → 10s
Position 2 → 15s
Position 3 → 20s
...
Maximum → 35s
```

The queue is persisted in a JSON status file and updated using filesystem locking to avoid concurrent modifications.

## Stale Lock Recovery

A process can sometimes terminate unexpectedly while holding a lock.

To prevent a permanently blocked queue, the plugin periodically checks the age of lock files.

The default timeout is:

```php
'max_lock_age' => 300
```

Locks older than the configured threshold are considered stale and removed automatically.

This provides a recovery mechanism for situations such as:

* PHP fatal errors
* Process termination
* Server interruptions
* Unexpected worker shutdowns

## Status & Logging

The plugin maintains a JSON status file containing information about currently running and queued jobs.

A status entry contains information such as:

```json
{
    "events": {
        "my_cron_event": {
            "status": "running",
            "site": "Example Site",
            "site_url": "https://example.com",
            "timestamp": "2026-09-24T10:00:00Z",
            "queue": []
        }
    }
}
```

Execution information is also written to a log file, including:

* Timestamp
* Process ID
* Site name
* Site URL
* Lock acquisition/release
* Queue operations
* Trigger attempts
* Stale-lock cleanup
* Errors and fallback operations

Log writes use filesystem locking to prevent concurrent processes from corrupting the log.

## Requirements

* WordPress
* PHP with filesystem locking support
* A shared filesystem between the participating WordPress installations
* WP-CLI is optional, but recommended when using the `wpcli` or `both` trigger modes

### Shared filesystem

The participating WordPress installations must have access to the same directory containing the plugin and lock files.

The recommended deployment uses a centralized plugin file in a shared `/locks` directory, which is then symlinked into each WordPress installation's `wp-content/plugins` directory.

## Installation

Global Cron Lock was designed to be deployed centrally on a server hosting multiple WordPress installations.

A typical directory structure looks like:

```text
/var/www/
├── locks/
│   └── global-cron-lock.php
│
├── site-a/
│   └── public/
│       └── wp-content/
│           └── plugins/
│               └── global-cron-lock.php → /var/www/locks/global-cron-lock.php
│
├── site-b/
│   └── public/
│       └── wp-content/
│           └── plugins/
│               └── global-cron-lock.php → /var/www/locks/global-cron-lock.php
│
└── site-c/
    └── public/
        └── wp-content/
            └── plugins/
                └── global-cron-lock.php → /var/www/locks/global-cron-lock.php
```

### 1. Create the shared directory

Create a dedicated directory alongside the WordPress installations:

```bash
mkdir /var/www/locks
```

Copy `global-cron-lock.php` into this directory.

### 2. Symlink the plugin into each WordPress installation

Instead of maintaining a separate copy of the plugin for every site, create a symbolic link in each site's plugins directory:

```bash
ln -s /var/www/locks/global-cron-lock.php \
      /var/www/site-a/public/wp-content/plugins/global-cron-lock.php
```

Repeat this for each WordPress installation participating in the shared cron locking system.

This ensures that all installations use the same plugin code and shared lock files, allowing them to coordinate through the same filesystem.

### 3. Activate the plugin

Activate the plugin normally from the WordPress admin, or using WP-CLI:

```bash
wp plugin activate global-cron-lock
```

Repeat for each WordPress installation.

### 4. Configure the participating sites

Configure the plugin with the relevant:

* Site paths
* Locked events
* Locking mode
* Trigger method
* Queue delays
* Lock timeout

Make sure the shared directory is writable by the PHP process running the WordPress installations.

## Example Architecture

A typical multi-site deployment might look like:

```text
                         Shared Server
                              │
                 ┌────────────┴────────────┐
                 │                         │
              /locks/                 WordPress Sites
                 │                         │
      global-cron-lock.php          ┌──────┼──────┐
                 │                   │      │      │
                 │                Site A Site B Site C
                 │                   │      │      │
                 └───────────────────┴──────┴──────┘
                                      │
                              Symlinked Plugin
                                      │
                                      ▼
                               Global Cron Lock
                                      │
                         ┌────────────┴────────────┐
                         │                         │
                    Lock Files              Queue / Status
                         │                         │
                         └────────────┬────────────┘
                                      │
                                 Cron Worker
```

Instead of allowing every site to execute a heavy task independently, the plugin coordinates execution and serializes the workload.

## Design Notes

### Filesystem-based mutex

The implementation deliberately uses the filesystem rather than the WordPress database for the actual mutex.

The core locking operation uses PHP's `flock()`:

```php
flock($fp, LOCK_EX | LOCK_NB);
```

The `LOCK_NB` flag allows competing processes to fail immediately when a lock is already held rather than blocking indefinitely.

### Shared plugin deployment

The plugin was designed for a server hosting multiple WordPress installations.

Rather than installing separate copies of the plugin, all sites reference a single shared plugin file through symbolic links:

```text
/locks/global-cron-lock.php
        ▲
        │
        ├── Site A/wp-content/plugins/global-cron-lock.php
        ├── Site B/wp-content/plugins/global-cron-lock.php
        └── Site C/wp-content/plugins/global-cron-lock.php
```

This provides a single source of truth for the plugin code and, more importantly, gives all participating installations access to the same locking infrastructure.

### Shutdown handling

Shutdown handlers are used to ensure that locks are released and queued work can continue after the current cron execution finishes.

This is particularly important for long-running background operations where the next queued job should only begin after the current process has completed.

## Use Case

Global Cron Lock was developed to solve a real-world infrastructure problem in a multi-site WordPress environment.

Several WordPress installations were hosted on the same server and periodically needed to perform the same resource-intensive background operation.

Without coordination:

```text
Site A ──> Heavy Job ──────────────┐
Site B ──> Heavy Job ──────────────┤
Site C ──> Heavy Job ──────────────┼──> High Server Load
Site D ──> Heavy Job ──────────────┘
```

With Global Cron Lock:

```text
Site A ──> Heavy Job ──> Complete
                              │
Site B ────────────────> Heavy Job ──> Complete
                                           │
Site C ─────────────────────────────> Heavy Job
```

This allows the workload to be serialized while still allowing each WordPress installation to initiate its own cron process normally.

## Limitations

Global Cron Lock is designed specifically for WordPress installations sharing a filesystem.

It is **not intended to provide distributed locking across completely independent servers** where the participating installations cannot access the same filesystem.

For distributed environments, a shared datastore or distributed locking mechanism such as Redis, a database-based mutex, or another coordination service would be more appropriate.

## Project Status

**Version:** `1.0.0`

This project was developed as an internal solution for coordinating resource-intensive WordPress cron jobs across multiple sites hosted on the same server.
