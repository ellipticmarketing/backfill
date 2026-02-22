<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Token
    |--------------------------------------------------------------------------
    |
    | A shared secret token used to authenticate sync requests between the
    | server (production) and client (local/staging). Both sides must use
    | the same token. The installation command can generate it.
    |
    */

    'auth_token' => env('BACKFILL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Server Configuration (Production Side)
    |--------------------------------------------------------------------------
    |
    | These settings control how the production server exposes data for sync.
    | The server creates a temporary database, copies + sanitizes data there,
    | then streams it to the client. Production data is NEVER modified.
    |
    */

    'server' => [
        'enabled' => env('BACKFILL_SERVER_ENABLED', false),
        'route_prefix' => 'api/backfill',
        'middleware' => [],

        // 'database' = create a temporary database (requires CREATE/DROP DB privileges)
        // 'tables'   = create temporary tables in the same database (less privileges needed)
        'temp_strategy' => env('BACKFILL_TEMP_STRATEGY', 'database'),

        // Alternate DB credentials for temp database operations.
        // If your app's DB user lacks CREATE/DROP DATABASE privileges,
        // set these to a user that does. Leave null to use the default connection.
        'temp_username' => env('BACKFILL_TEMP_USERNAME'),
        'temp_password' => env('BACKFILL_TEMP_PASSWORD'),

        // Number of rows to read per chunk when streaming data
        'chunk_size' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Configuration (Local/Staging Side)
    |--------------------------------------------------------------------------
    |
    | These settings control how the local/staging environment pulls data
    | from production. The client will ONLY run in the listed environments.
    |
    */

    'client' => [
        'source_url' => env('BACKFILL_SOURCE_URL'), // e.g. https://myapp.com

        // Environments where the pull command is allowed to run
        'allowed_environments' => ['local', 'staging'],

        // HTTP timeout in seconds for each chunk request
        'timeout' => 300,

        // Number of rows to insert per batch during import
        'chunk_size' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitization Rules
    |--------------------------------------------------------------------------
    |
    | Define which columns in which tables should have their data sanitized.
    | All sanitization happens via SQL functions — no PHP processing of rows.
    |
    | Supported types:
    |   'email'   → CONCAT(UUID(), '@example.test')
    |   'name'    → CONCAT('User_', id)
    |   'phone'   → CONCAT('+1555', LPAD(id, 7, '0'))
    |   'text'    → CONCAT('text_', MD5(RAND()))
    |   'hash'    → Static bcrypt hash of 'password'
    |   'null'    → NULL
    |   'address' → CONCAT(id, ' Example St')
    |   'local_ip'→ CONCAT('192.168.', FLOOR(RAND() * 255), '.', FLOOR(RAND() * 255))
    |
    | Each column rule may include:
    |   'type'    => (required) one of the types above
    |   'exclude' => (optional) array of patterns to SKIP sanitization for.
    |                Supports wildcards: '*@company.com', 'admin@*'
    |
    | Example:
    |   'users' => [
    |       'email' => [
    |           'type'    => 'email',
    |           'exclude' => ['*@mycompany.test', 'john@example.com'],
    |       ],
    |       'name'     => ['type' => 'name'],
    |       'phone'    => ['type' => 'phone'],
    |       'password' => ['type' => 'hash'],
    |   ],
    |
    */

    'sanitize' => [
        // 'users' => [
        //     'email' => [
        //         'type' => 'email',
        //         'exclude' => ['*@mycompany.test'],
        //     ],
        //     'name' => ['type' => 'name'],
        //     'password' => ['type' => 'hash'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Row Limits
    |--------------------------------------------------------------------------
    |
    | Limit how many rows are synced for specific tables. Useful for large
    | log or audit tables where you only need recent data.
    |
    | When rows are limited, the package automatically resolves foreign key
    | dependencies to avoid orphan records — child rows referencing deleted
    | parents will also be removed.
    |
    | Options per table:
    |   'keep_days' => (optional) Keep rows newer than N days ago
    |   'max_rows'  => (optional) Maximum number of rows to keep
    |   'order_by'  => (optional) Column to sort/filter by (default: primary key)
    |   'direction' => (optional) 'desc' or 'asc' (default: 'desc')
    |
    */

    'limits' => [
        // 'logs' => ['keep_days' => 30, 'order_by' => 'created_at'],
        // 'audit_trail' => ['max_rows' => 1000, 'order_by' => 'id', 'direction' => 'desc'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables listed here will be completely skipped during sync.
    | The table structure will still be available but no data is transferred.
    |
    */

    'exclude_tables' => [
        // Laravel Telescope
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',

        // Laravel Pulse
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',

        // Laravel Horizon
        'failed_jobs',
        'job_batches',
        'jobs',
    ],
];
