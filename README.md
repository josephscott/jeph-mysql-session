# JEPH/Framework/MySQL/Session

[![Tests](https://github.com/josephscott/jeph-mysql-session/actions/workflows/tests.yml/badge.svg)](https://github.com/josephscott/jeph-mysql-session/actions/workflows/tests.yml)

Just Enough PHP to provide MySQL session storage.

A custom PHP session handler that stores session data in a MySQL database with proper session locking.

## Usage

```php
<?php
// Create a PDO connection
$pdo = new PDO(
    dsn: 'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    username: 'user',
    password: 'password'
);

// Create the session handler
$session_handler = JEPH\MySQL\Session::create( pdo: $pdo );
if ( $session_handler === false ) {
    die( 'Failed to create session handler' );
}

// Register the handler
session_set_save_handler( session_handler: $session_handler, register_shutdown: true );

// Start the session - use sessions as normal from here
session_start();
$_SESSION['user_id'] = 123;
```

## Configuration Options

All options are passed to the `create()` factory method, which returns `Session|false`:

```php
$session_handler = JEPH\MySQL\Session::create(
    pdo: $pdo,
    table_name: 'sessions',           // Session data table (default: 'sessions')
    lock_table_name: 'session_locks', // Lock table (default: 'session_locks')
    lock_timeout: 10,                 // Seconds to wait for lock (default: 10)
    lock_max_age: 30,                 // Seconds before lock is stale (default: 30)
    lock_retry_interval: 100,         // Milliseconds between retries (default: 100)
    security_code: 'your_secret',     // Secret for fingerprint (default: '')
    lock_to_user_agent: true,         // Bind to User-Agent (default: false)
    lock_to_ip: true                  // Bind to IP address (default: false)
);
if ( $session_handler === false ) {
    die( 'Invalid configuration' );
}
```

The factory method returns `false` if table names contain invalid characters (only alphanumeric and underscores are allowed) to prevent SQL injection.

| Option | Default | Description |
|--------|---------|-------------|
| table_name | `sessions` | Name of the sessions table |
| lock_table_name | `session_locks` | Name of the locks table |
| lock_timeout | `10` | Seconds to wait when acquiring a lock |
| lock_max_age | `30` | Seconds before a lock is considered abandoned |
| lock_retry_interval | `100` | Milliseconds between lock acquisition attempts |
| security_code | `''` | Secret string for session fingerprint validation |
| lock_to_user_agent | `false` | Bind session to the client's User-Agent header |
| lock_to_ip | `false` | Bind session to client IP address (bool or callable) |

## Database Tables

### session_locks

Used for table-based session locking to ensure data consistency during concurrent requests.

```sql
CREATE TABLE session_locks (
    session_id VARCHAR(128) NOT NULL PRIMARY KEY,
    lock_token VARCHAR(64) NOT NULL,
    locked_at INT UNSIGNED NOT NULL,
    INDEX idx_locked_at (locked_at)
) ENGINE=InnoDB;
```

| Column | Type | Description |
|--------|------|-------------|
| session_id | VARCHAR(128) | The PHP session ID (primary key) |
| lock_token | VARCHAR(64) | Unique token identifying the lock holder |
| locked_at | INT UNSIGNED | Unix timestamp when lock was acquired |

### sessions

Stores the actual session data.

```sql
CREATE TABLE sessions (
    session_id VARCHAR(128) NOT NULL PRIMARY KEY,
    data MEDIUMBLOB NOT NULL,
    fingerprint VARCHAR(64) NOT NULL DEFAULT '',
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB;
```

| Column | Type | Description |
|--------|------|-------------|
| session_id | VARCHAR(128) | The PHP session ID (primary key) |
| data | MEDIUMBLOB | Serialized session data (up to 16MB) |
| fingerprint | VARCHAR(64) | SHA256 hash for session hijacking protection |
| last_accessed | INT UNSIGNED | Unix timestamp for garbage collection |

## Session Hijacking Protection

This handler provides optional session hijacking protection through fingerprint validation. When enabled, a hash of client characteristics is stored with the session and validated on each read. If the fingerprint doesn't match, the session is destroyed.

### Configuration

Enable protection by setting one or more of these options:

```php
$session_handler = JEPH\MySQL\Session::create(
    pdo: $pdo,
    security_code: 'a_random_secret_string_12chars',  // Server-side secret
    lock_to_user_agent: true,                          // Bind to browser
    lock_to_ip: true                                   // Bind to IP address
);
```

### Options Explained

**security_code** - A secret string (recommended: 12+ characters with mixed case and numbers) that is included in the fingerprint calculation. This adds server-side entropy that an attacker cannot know, making it harder to forge a valid fingerprint.

**lock_to_user_agent** - When `true`, the session is bound to the client's User-Agent header. If the User-Agent changes, the session is invalidated. Note: Some browsers (especially older IE versions) may change User-Agent between requests, so test thoroughly.

**lock_to_ip** - When `true`, the session is bound to `$_SERVER['REMOTE_ADDR']`. This provides strong protection but may cause issues for users whose IP changes frequently (mobile networks, some ISPs).

### Using a Callable for IP Address

If your application is behind a load balancer or reverse proxy, `REMOTE_ADDR` will be the proxy's IP. Use a callable to extract the real client IP:

```php
$session_handler = JEPH\MySQL\Session::create(
    pdo: $pdo,
    security_code: 'your_secret',
    lock_to_ip: function(): string {
        // Check trusted proxy headers
        // WARNING: Only trust these headers if you control the proxy!
        foreach ( ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header ) {
            if ( isset( $_SERVER[$header] ) && $_SERVER[$header] !== '' ) {
                // X-Forwarded-For may contain multiple IPs; take the first
                $ip = explode( ',', $_SERVER[$header] )[0];
                return trim( $ip );
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
);
```

### Security Considerations

- **Recommended**: Always set `security_code` when using fingerprint protection
- **User-Agent binding** adds minor security; an attacker who steals a session cookie likely has the same browser
- **IP binding** is stronger but may cause legitimate session loss for mobile users
- A mismatched fingerprint destroys the session and returns empty data, forcing a new session
- Uses `hash_equals()` for constant-time comparison to prevent timing attacks

### Upgrading Existing Sessions

If you enable fingerprint protection on an existing application, sessions created before the upgrade will be invalidated (they have no stored fingerprint). Users will need to log in again.

## Session Locking

This handler implements table-based session locking to prevent race conditions during concurrent requests. When a session is read, a lock is acquired and held until the session is closed.

**How it works:**
1. When `session_start()` is called, the handler attempts to acquire a lock
2. If the lock is held by another request, it retries until `lock_timeout` is reached
3. If a lock is older than `lock_max_age`, it is considered abandoned and can be claimed
4. The lock is released when `session_write_close()` is called or the script ends

**Best practices:**
- Call `session_write_close()` early in long-running scripts to release the lock
- Keep `lock_timeout` reasonable to avoid blocking requests indefinitely
- The `lock_max_age` should be longer than your maximum expected script execution time

### Long-Running Scripts

For scripts that run longer than `lock_max_age`, use `refresh_lock()` to prevent the lock from becoming stale:

```php
$session_handler = JEPH\MySQL\Session::create( pdo: $pdo );
session_set_save_handler( session_handler: $session_handler, register_shutdown: true );
session_start();

// For long operations, periodically refresh the lock
foreach ( $large_dataset as $item ) {
    process_item( $item );
    
    // Refresh lock every iteration (or based on time elapsed)
    $session_handler->refresh_lock();
}

session_write_close();
```

The `refresh_lock()` method returns `true` if the lock was successfully refreshed, or `false` if no lock is held or the lock was lost. Call it at intervals shorter than `lock_max_age` (e.g., every `lock_max_age / 2` seconds).

## Testing

Tests are written using [Pest](https://pestphp.com/) and require a MySQL database.

### Setup

1. Create a test database and user:

```sql
CREATE DATABASE jeph_session_test;
CREATE USER 'jeph_session_test'@'localhost' IDENTIFIED BY 'jeph_session_test';
GRANT ALL PRIVILEGES ON jeph_session_test.* TO 'jeph_session_test'@'localhost';
FLUSH PRIVILEGES;
```

2. Configure the database connection via environment variables (if using different credentials):

```bash
export TEST_DB_HOST=localhost
export TEST_DB_PORT=3306
export TEST_DB_NAME=jeph_session_test
export TEST_DB_USER=jeph_session_test
export TEST_DB_PASS=jeph_session_test
```

### Running Tests

```bash
# Run all checks (style, lint, analyze, tests)
make all

# Run only tests
make tests
```

The test suite includes:
- **session-tests.php** - Basic session handler functionality (read, write, destroy, gc)
- **locking-tests.php** - Lock acquisition, release, stale lock handling, and concurrent access tests
- **fingerprint-tests.php** - Session hijacking protection via fingerprint validation