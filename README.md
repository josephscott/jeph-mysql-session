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
$session_handler = new JEPH\MySQL\Session( pdo: $pdo );

// Register the handler
session_set_save_handler( session_handler: $session_handler, register_shutdown: true );

// Start the session - use sessions as normal from here
session_start();
$_SESSION['user_id'] = 123;
```

## Configuration Options

All options are passed to the constructor:

```php
$session_handler = new JEPH\MySQL\Session(
    pdo: $pdo,
    table_name: 'sessions',        // Session data table (default: 'sessions')
    lock_table_name: 'session_locks', // Lock table (default: 'session_locks')
    lock_timeout: 10,              // Seconds to wait for lock (default: 10)
    lock_max_age: 30,              // Seconds before lock is stale (default: 30)
    lock_retry_interval: 100       // Milliseconds between retries (default: 100)
);
```

| Option | Default | Description |
|--------|---------|-------------|
| table_name | `sessions` | Name of the sessions table |
| lock_table_name | `session_locks` | Name of the locks table |
| lock_timeout | `10` | Seconds to wait when acquiring a lock |
| lock_max_age | `30` | Seconds before a lock is considered abandoned |
| lock_retry_interval | `100` | Milliseconds between lock acquisition attempts |

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
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB;
```

| Column | Type | Description |
|--------|------|-------------|
| session_id | VARCHAR(128) | The PHP session ID (primary key) |
| data | MEDIUMBLOB | Serialized session data (up to 16MB) |
| last_accessed | INT UNSIGNED | Unix timestamp for garbage collection |

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