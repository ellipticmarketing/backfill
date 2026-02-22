# Laravel Backfill

**Pull a sanitized copy of your production database into local/staging environments.**

Backfill is a Laravel package that creates a secure bridge between your production server and local development environments. It copies your production data into a temporary database, sanitizes sensitive information using pure SQL (no PHP row iteration), and streams the result as a SQL dump to your local machine — where it's imported via the `mysql` CLI for maximum speed.

Production data is **never modified**. The package refuses to run destructive operations outside of explicitly allowed environments.

---

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration Reference](#configuration-reference)
  - [Authentication](#authentication)
  - [Server Settings](#server-settings)
  - [Client Settings](#client-settings)
  - [Sanitization Rules](#sanitization-rules)
  - [Row Limits](#row-limits)
  - [Excluded Tables](#excluded-tables)
- [Commands](#commands)
  - [backfill:install](#backfillinstall)
  - [backfill:pull](#backfillpull)
  - [backfill:status](#backfillstatus)
  - [backfill:cleanup](#backfillcleanup)
- [Events](#events)
- [How It Works](#how-it-works)
  - [Architecture](#architecture)
  - [Temporary Database Strategy](#temporary-database-strategy)
  - [SQL-Level Sanitization](#sql-level-sanitization)
  - [Incremental (Delta) Sync](#incremental-delta-sync)
  - [Foreign Key Handling](#foreign-key-handling)
- [Alternate Database Credentials](#alternate-database-credentials)
- [Cleanup & Crash Recovery](#cleanup--crash-recovery)
- [Security](#security)
- [Edge Cases & Limitations](#edge-cases--limitations)
- [Testing](#testing)
- [License](#license)

---

## Installation

Require the package via Composer:

```bash
composer require elliptic/backfill
```

Laravel will auto-discover the service provider. No manual registration needed.

Publish the configuration file:

```bash
php artisan vendor:publish --tag="backfill-config"
```

> **No migrations needed.** Sync history is stored in `storage/backfill-state.json`, not a database table. This avoids the chicken-and-egg problem of needing a migration for a tool that overwrites your database.

---

## Quick Start

### 1. Run the install command

This generates a secure token and shows you exactly what to add to your `.env`:

```bash
php artisan backfill:install
```

The command detects your environment and shows the right instructions — server setup on production, client setup on local/staging. It can also write the token directly to your `.env` file.

### 2. Configure both environments

The install command will tell you exactly what to add. In short:

**Server** (production) `.env`:
```env
BACKFILL_TOKEN=<generated-token>
BACKFILL_SERVER_ENABLED=true
```

**Client** (local/staging) `.env`:
```env
BACKFILL_TOKEN=<same-token>
BACKFILL_SOURCE_URL=https://your-production-app.com
```

### 3. Set up sanitization rules

Edit `config/backfill.php` on the **server** side:

```php
'sanitize' => [
    'users' => [
        'email' => [
            'type' => 'email',
            'exclude' => ['*@yourcompany.com'],
        ],
        'name' => ['type' => 'name'],
        'password' => ['type' => 'hash'],
        'phone' => ['type' => 'phone'],
    ],
    'customers' => [
        'email' => ['type' => 'email'],
        'address' => ['type' => 'address'],
    ],
],
```

### 4. Pull the database

On your local machine:

```bash
# First time — full sync
php artisan backfill:pull --full

# Subsequent times — only new/updated rows
php artisan backfill:pull
```

That's it. Your local database now has sanitized production data.

---

## Configuration Reference

All configuration lives in `config/backfill.php`. The server (production) and client (local) share the same config file — each side only reads the section relevant to it.

### Authentication

```php
'auth_token' => env('BACKFILL_TOKEN'),
```

A shared secret token used for all communication between server and client. This token is sent as a `Bearer` token in the `Authorization` header and validated using a timing-safe comparison (`hash_equals`).

**Recommendations:**
- Use a random string of at least 64 characters
- Never commit the token to version control
- Rotate the token periodically

---

### Server Settings

```php
'server' => [
    'enabled' => env('BACKFILL_SERVER_ENABLED', false),
    'route_prefix' => 'api/backfill',
    'middleware' => [],
    'temp_strategy' => env('BACKFILL_TEMP_STRATEGY', 'database'),
    'temp_username' => env('BACKFILL_TEMP_USERNAME'),
    'temp_password' => env('BACKFILL_TEMP_PASSWORD'),
    'chunk_size' => 5000,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Must be `true` on the production server to expose the sync API endpoints |
| `route_prefix` | `api/backfill` | URL prefix for the sync endpoints. Endpoints are `GET /{prefix}/manifest` and `GET /{prefix}/dump/{table}` |
| `middleware` | `[]` | Additional middleware to apply to sync routes (on top of `api` and token auth) |
| `temp_strategy` | `database` | How temporary data is stored during sanitization. See [Temporary Database Strategy](#temporary-database-strategy) |
| `temp_username` | `null` | Alternate DB username for temp operations (if your app user can't create databases). See [Alternate Database Credentials](#alternate-database-credentials) |
| `temp_password` | `null` | Password for the alternate DB user |
| `chunk_size` | `5000` | Number of rows read per chunk when building the SQL dump |

---

### Client Settings

```php
'client' => [
    'source_url' => env('BACKFILL_SOURCE_URL'),
    'allowed_environments' => ['local', 'staging'],
    'timeout' => 300,
    'chunk_size' => 5000,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `source_url` | `null` | Base URL of the production server (e.g., `https://myapp.com`) |
| `allowed_environments` | `['local', 'staging']` | The `backfill:pull` command will **refuse to run** in any environment not listed here. This is the primary safety mechanism preventing accidental overwrites of production data |
| `timeout` | `300` | HTTP timeout in seconds for each request to the server |
| `chunk_size` | `5000` | Number of rows inserted per batch during import |

---

### Sanitization Rules

```php
'sanitize' => [
    'table_name' => [
        'column_name' => [
            'type' => 'email',           // Required: sanitization type
            'exclude' => ['pattern'],     // Optional: patterns to skip
        ],
    ],
],
```

All sanitization happens **via SQL** in the temporary database. No PHP iteration over rows. This is critical for performance on large tables.

#### Available Sanitization Types

| Type | SQL Expression | Example Output |
|------|---------------|----------------|
| `email` | `CONCAT(UUID(), '@example.test')` | `550e8400-e29b-41d4-a716-446655440000@example.test` |
| `name` | `CONCAT('User_', id)` | `User_123` |
| `phone` | `CONCAT('+1555', LPAD(id, 7, '0'))` | `+15550000123` |
| `text` | `CONCAT('text_', MD5(RAND()))` | `text_a1b2c3d4e5f6...` |
| `hash` | Static bcrypt hash of `'password'` | `$2y$10$92IXUNpk...` (always the same) |
| `null` | `NULL` | `NULL` |
| `address` | `CONCAT(id, ' Example St')` | `123 Example St` |

#### Exclude Patterns

The `exclude` option lets you keep certain rows **unsanitized**. This is useful for preserving team accounts, test accounts, or system users.

```php
'users' => [
    'email' => [
        'type' => 'email',
        'exclude' => [
            '*@yourcompany.com',      // Wildcard: keep all company emails
            'admin@specific.com',     // Exact match
            'test-*@example.com',     // Prefix wildcard
        ],
    ],
],
```

Exclude patterns use SQL `LIKE` under the hood (`*` is converted to `%`). The generated SQL looks like:

```sql
UPDATE `users` SET `email` = CASE
    WHEN `email` LIKE '%@yourcompany.com' THEN `email`
    WHEN `email` LIKE 'admin@specific.com' THEN `email`
    WHEN `email` LIKE 'test-%@example.com' THEN `email`
    ELSE CONCAT(UUID(), '@example.test')
END
```

---

### Row Limits

```php
'limits' => [
    'table_name' => [
        'max_rows' => 1000,             // Required
        'order_by' => 'created_at',     // Optional (default: primary key)
        'direction' => 'desc',          // Optional (default: 'desc')
    ],
],
```

Limits the number of rows synced for specific tables. Useful for large log, audit, or analytics tables where you only need recent data for development.

The package resolves foreign key dependencies utilizing a **Stateless Subset Resolver**:
- **Bottom-Up Inclusion:** If a child table has no limit (e.g., you want all recent `cars`), the package organically keeps *all parent rows* referenced by those cars, even if the parent table itself has a limit (e.g., `users`).
- **Top-Down Exclusion:** If a parent row evaluates as too old and gets discarded, any child rows referencing that orphaned parent are automatically removed as well.

This ensures perfect referential integrity, computing exact subsets via recursive subqueries directly in the database engine without PHP memory pressure.

**Example:**

```php
'limits' => [
    'activity_log' => ['max_rows' => 1000, 'order_by' => 'created_at', 'direction' => 'desc'],
    'telescope_entries' => ['max_rows' => 500],
    'notifications' => ['max_rows' => 2000, 'order_by' => 'id'],
],
```

---

### Excluded Tables

```php
'exclude_tables' => [
    'telescope_entries',
    'telescope_entries_tags',
    'telescope_monitoring',
    'failed_jobs',
],
```

Tables listed here are **completely skipped** during sync. No data is transferred. Use this for:
- Debug/monitoring tables (Telescope, Horizon)
- Job tables (failed_jobs, job_batches)
- Cache tables
- Any table with highly volatile data that isn't useful in development

---

## Commands

### `backfill:install`

Generate a sync token and display environment-specific setup instructions.

```
php artisan backfill:install
```

- Generates a cryptographically random 64-character token
- Offers to write `BACKFILL_TOKEN` directly to your `.env` file
- Detects your environment and shows the right instructions:
  - **Production** → server-side `.env` vars and privilege setup
  - **Local/Staging** → client-side `.env` vars and pull commands
- Reminds you to publish and customize the config file

---

### `backfill:pull`

The main command. Pulls sanitized data from the production server.

```
php artisan backfill:pull [options]
```

| Option | Description |
|--------|-------------|
| `--full` | Force a complete re-sync, ignoring the last pull timestamp. Truncates all local tables before importing. |
| `--tables=users,orders` | Only sync specific tables (comma-separated). |
| `--dry-run` | Show what would be synced without making any changes. Displays a table with row counts, sanitization status, and limit status. |

**Examples:**

```bash
# First sync — always a full pull
php artisan backfill:pull --full

# Incremental sync — only new/updated data since last pull
php artisan backfill:pull

# Re-sync only the users and orders tables
php artisan backfill:pull --full --tables=users,orders

# Preview what will happen
php artisan backfill:pull --dry-run
```

**Dry run output:**

```
📊 Dry run — tables that would be synced:

+------------------+--------+-----------+---------+------------+
| Table            | Rows   | Sanitized | Limited | Timestamps |
+------------------+--------+-----------+---------+------------+
| users            | 5,240  | ✓         |         | ✓          |
| orders           | 45,000 |           |         | ✓          |
| activity_log     | 890,000|           | ✓       | ✓          |
| products         | 320    |           |         | ✓          |
+------------------+--------+-----------+---------+------------+
```

---

### `backfill:status`

Shows the history of past sync operations.

```
php artisan backfill:status
```

**Example output:**

```
📊 Database Sync History (last 10)

+----+-------+-------------+--------+-----------+----------+---------------------+
| ID | Mode  | Status      | Rows   | Tables    | Duration | Started             |
+----+-------+-------------+--------+-----------+----------+---------------------+
| 3  | DELTA | ✅ Complete | 1,250  | 12 tables | 45s      | 2024-03-15 10:30:00 |
| 2  | FULL  | ✅ Complete | 85,000 | 28 tables | 3m       | 2024-03-01 09:00:00 |
| 1  | FULL  | ✅ Complete | 82,000 | 28 tables | 3m       | 2024-02-15 14:00:00 |
+----+-------+-------------+--------+-----------+----------+---------------------+

Last successful sync: 2024-03-15T10:30:45+00:00
A delta sync will pull data created/updated after this timestamp.
```

---

### `backfill:cleanup`

Drops orphaned temporary databases and tables left behind by failed or interrupted sync operations.

```
php artisan backfill:cleanup [options]
```

| Option | Description |
|--------|-------------|
| `--force` | Skip the confirmation prompt. Required for scheduled/automated runs. |
| `--max-age=60` | Only drop temp databases older than this many minutes (default: 60). Prevents killing an active sync. |

This command is **automatically scheduled to run hourly** on the server when `BACKFILL_SERVER_ENABLED=true`. You don't need to set up a cron job for it.

---

## Events

### `SyncCompleted`

Dispatched after a successful `backfill:pull` operation. Useful for clearing caches, triggering Laravel Scout re-indexing, or sending notifications.

```php
use Elliptic\Backfill\Events\SyncCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(function (SyncCompleted $event) {
    if ($event->isFullSync) {
        // e.g., php artisan scout:import "App\Models\User"
    }

    $syncedTables = $event->tables;
    $totalRows = $event->rowsSynced;
});
```

---

## How It Works

### Architecture

```
Local (Client)                          Production (Server)
─────────────────                       ──────────────────────────
                                        
1. GET /manifest  ─────────────────────▶ Reads INFORMATION_SCHEMA
   (Bearer token)                        Returns table list, row counts,
                  ◀──── JSON response── FK ordering, column metadata
                                        
2. GET /dump/users ────────────────────▶ CREATE DATABASE _backfill_temp_*
   (Bearer token)                        CREATE TABLE ... LIKE ...
                                         INSERT INTO temp SELECT * FROM prod
                                         UPDATE temp (sanitize via SQL)
                                         DELETE excess rows (limits)
                                         mysqldump temp.users
                  ◀── streamed .sql ─── Stream SQL dump to client
                                         DROP temp table
                                        
3. Receive .sql file                    
   mysql < users.sql                    
   (or PHP fallback for non-MySQL)      
                                        
4. Repeat for each table...             
                                        
5. Record pull timestamp in             
   storage/backfill-state.json           
```

### Temporary Database Strategy

The package supports two strategies for creating the temporary workspace where data is copied and sanitized:

#### `database` strategy (default)

Creates a separate temporary database named `_backfill_temp_{timestamp}_{random}`.

```env
BACKFILL_TEMP_STRATEGY=database
```

**Pros:**
- Complete isolation from production tables
- No risk of accidental production data modification
- Easy to identify and clean up

**Cons:**
- Requires a DB user with `CREATE DATABASE` / `DROP DATABASE` privileges
- May need [alternate credentials](#alternate-database-credentials)

#### `tables` strategy

Creates temporary tables in the **same database** with a `_backfill_` prefix.

```env
BACKFILL_TEMP_STRATEGY=tables
```

**Pros:**
- Works with restricted DB users (no extra privileges needed)
- Simpler setup

**Cons:**
- Temporary tables live alongside production tables (though they're prefixed and cleaned up)

---

### SQL-Level Sanitization

All data sanitization happens via `UPDATE` statements executed directly in the database engine. This means:

- **No PHP memory pressure** — even a 10-million-row users table is sanitized in a single `UPDATE` statement
- **Database-engine speed** — the DB engine processes the update natively, far faster than PHP could iterate rows
- **Transactional safety** — the update is atomic

For example, sanitizing emails with exclude patterns generates:

```sql
UPDATE `_backfill_temp_1234`.`users` SET `email` = CASE
    WHEN `email` LIKE '%@yourcompany.com' THEN `email`
    ELSE CONCAT(UUID(), '@example.test')
END
```

---

### Incremental (Delta) Sync

After your first full sync, subsequent runs use **delta mode** by default:

1. The package checks `storage/backfill-state.json` for the last successful sync timestamp
2. The server filters the temp data: `DELETE FROM temp WHERE created_at < ? AND updated_at < ?`
3. Only remaining (new/updated) rows are included in the dump
4. The client uses `REPLACE INTO` instead of `INSERT INTO` to upsert without conflicts

**Requirements for delta sync:**
- The table must have both `created_at` and `updated_at` columns
- Tables without timestamps always get a full re-pull

**Limitations:**
- **Deleted rows are not synced.** If a row was deleted on production, it will still exist locally after a delta sync. Use `--full` periodically to get a clean state.
- **Schema changes are not synced.** If production adds a new column, run migrations locally first, then do a `--full` pull.

---

### Foreign Key Handling

The package handles FK dependencies at three levels:

1. **Import order:** Tables are topologically sorted so parent tables are imported before children. Discovered automatically via `INFORMATION_SCHEMA.KEY_COLUMN_USAGE`.

2. **Row limiting:** When a parent table has a row limit, child rows referencing deleted parents are cleaned up first to prevent orphan records.

3. **Import execution:** FK checks are disabled during import (`SET FOREIGN_KEY_CHECKS=0`) and re-enabled afterward.

Self-referencing tables (e.g., `categories` with `parent_id`) and circular references are detected and handled gracefully — cycles are broken in the topological sort.

---

## Alternate Database Credentials

If your application's database user doesn't have `CREATE DATABASE` / `DROP DATABASE` privileges, you can configure a separate, more privileged user for temp database operations:

```env
BACKFILL_TEMP_USERNAME=backfill_admin
BACKFILL_TEMP_PASSWORD=secure-password
```

This user is **only used** for:
- Creating/dropping the temp database
- Copying tables into the temp database
- Running sanitization `UPDATE` statements on the temp database
- Running `mysqldump` on the temp database

It is **never used** for reading schema information or touching the production database.

**Recommended MySQL grant (least privilege):**

```sql
-- Create the user
CREATE USER 'backfill_admin'@'%' IDENTIFIED BY 'secure-password';

-- Only allow operations on databases matching the temp naming convention
GRANT ALL PRIVILEGES ON `_backfill\_temp\_%`.* TO 'backfill_admin'@'%';

-- Allow reading schema info from production
GRANT SELECT ON `INFORMATION_SCHEMA`.* TO 'backfill_admin'@'%';

-- Allow reading production data (for CREATE TABLE ... LIKE and INSERT ... SELECT)
GRANT SELECT ON `your_production_db`.* TO 'backfill_admin'@'%';

FLUSH PRIVILEGES;
```

---

## Cleanup & Crash Recovery

Temporary databases are cleaned up through multiple safety layers:

| Layer | When it runs | Catches |
|-------|-------------|---------|
| `finally` blocks in controllers | After each table is streamed | Normal completion and caught exceptions |
| `register_shutdown_function` | When PHP process exits | Fatal errors, OOM kills, uncaught exceptions |
| **Hourly scheduled job** | Every hour (automatic) | Server crashes, reboots, `kill -9`, network drops |
| `php artisan backfill:cleanup` | On demand | Manual cleanup when needed |

The scheduled job is **automatically registered** when `BACKFILL_SERVER_ENABLED=true`. It runs with `--force --max-age=60`, meaning it only drops temp databases older than 60 minutes (to avoid interfering with an active long-running sync).

**How orphans are detected:** The cleanup command runs `SHOW DATABASES LIKE '_backfill_temp_%'` and parses the Unix timestamp embedded in the database name. If it's older than the `--max-age` threshold, it's considered orphaned.

---

## Security

| Protection | How |
|------------|-----|
| **Token authentication** | All API endpoints require `Authorization: Bearer <token>`. Validated with timing-safe `hash_equals`. |
| **Environment guard** | `backfill:pull` refuses to run unless `app()->environment()` is in the `allowed_environments` list. Default: `local`, `staging`. |
| **No production writes** | The server never modifies production data. All operations happen in a temporary database that is dropped after use. |
| **SQL injection prevention** | Table and column names are validated against the actual schema. Exclude patterns use parameterized `LIKE` clauses. |
| **Transport** | Use HTTPS in production. The token is sent as a Bearer token in the Authorization header. |

> **⚠️ Important:** Always use HTTPS for your production URL. The auth token and sanitized data travel over this connection. HTTP would expose both to man-in-the-middle attacks.

---

## Edge Cases & Limitations

| Scenario | Behavior |
|----------|----------|
| Table has no primary key | Uses `id` column as fallback. If that doesn't exist either, the table is still synced but delta upserts won't work (use `--full`). |
| Table has no timestamps | Delta sync falls back to full sync for that specific table. Other tables with timestamps still use delta. |
| Deleted rows on production | **Not reflected** in delta sync. Use `--full` periodically for a clean state. |
| Schema changes on production | Run migrations locally first, then `--full` pull. Column mismatches are handled gracefully — only matching columns are imported. |
| Very large tables (100M+ rows) | The `mysqldump` + `mysql` import path handles this well. Consider using row limits to cap development data size. |
| Circular FK references | Detected and handled — cycles are broken in the topological sort. FK checks are disabled during import. |
| Self-referencing tables | Supported (e.g., `categories.parent_id → categories.id`). Self-references are excluded from FK sorting. |
| Multiple databases | Currently targets the default database connection only. Multi-database support is not yet implemented. |
| Supported Engines | The server-side sanitization and transport require **MySQL** or **MariaDB**. The package explicitly checks engine variants and will immediately fail if run against PostgreSQL, SQLite, or SQL Server. |
| Binary/blob columns | Handled natively by `mysqldump`. No special configuration needed. |

---

## Testing

The package includes a test suite using [Pest](https://pestphp.com/):

```bash
composer test
```

Tests cover:
- Authentication middleware (valid/invalid/missing tokens)
- Subsetting & Row Limiting (Top-down exclusions & Bottom-up inclusions)
- Sanitization SQL generation (all types + exclude patterns)
- Import service (full import, empty files, temp table name rewriting)
- Artisan commands (interactive install, environment guards, status logic)

---

## License

MIT
