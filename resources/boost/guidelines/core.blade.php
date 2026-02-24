## Elliptic Backfill

`ellipticmarketing/backfill` securely pulls a sanitized copy of a production database down to local/staging environments. It prevents accidental overwrites by refusing to run in production, and guarantees data privacy by sanitizing sensitive columns (emails, passwords, IPs) using pure SQL in a temporary database *before* streaming the dump.

### Environment Setup

Backfill requires environment variables on both the Server (production) and the Client (local/staging). The easiest way to configure both sides is:

@verbatim
<code-snippet name="Run the setup wizard" lang="bash">
php artisan backfill:install
</code-snippet>
@endverbatim

The Server requires:
- `BACKFILL_TOKEN` (shared secret)
- `BACKFILL_SERVER_ENABLED=true`

The Client requires:
- `BACKFILL_TOKEN` (same shared secret)
- `BACKFILL_SOURCE_URL` (the production URL, e.g., `https://example.com`)

### Configuration & Sanitization

Sanitization rules are defined in `config/backfill.php` on the **Server**. 
Data is never altered on the original tables; Backfill creates a temporary database, copies the data, runs `UPDATE` statements using pure SQL functions, and streams the result.

@verbatim
<code-snippet name="Configure sanitization rules" lang="php">
// config/backfill.php (Server side)
'sanitize' => [
    'users' => [
        'email' => [
            'type' => 'email', // Transforms to UUID@example.test
            'exclude' => ['*@mycompany.com'], // Optional: keep company emails
        ],
        'password' => ['type' => 'hash'], 
        'phone' => ['type' => 'phone'],
        'last_login_ip' => ['type' => 'local_ip'], // Transforms to 192.168.x.x
    ],
],
</code-snippet>
@endverbatim

### Usage

To download and import the data on the client environment:

@verbatim
<code-snippet name="Pull data from production" lang="bash">
# Perform a full sync (truncates local tables, imports full dump)
php artisan backfill:pull --full

# Perform an incremental sync (only rows where updated_at > last sync)
php artisan backfill:pull

# Preview what will be synced without making changes
php artisan backfill:pull --dry-run

# Run sync completely non-interactively
php artisan backfill:pull --force

# Force a fresh download instead of using an existing local cache
php artisan backfill:pull --fresh
</code-snippet>
@endverbatim

**Note:** If `backfill:pull` reports a schema difference (e.g., missing columns on local), instruct the user to run their local migrations (`php artisan migrate`) first.
