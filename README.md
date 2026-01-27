# JEPH/Framework/MySQL/Session

Just Enough PHP to provide MySQL session storage.

A custom PHP session handler that stores session data in a MySQL database with proper session locking.

## Database Tables

### session_locks

Used for table-based session locking to ensure data consistency during concurrent requests.

```sql
CREATE TABLE session_locks (
    session_id VARCHAR(128) NOT NULL PRIMARY KEY,
    lock_token VARCHAR(64) NOT NULL,
    locked_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
```

| Column | Type | Description |
|--------|------|-------------|
| session_id | VARCHAR(128) | The PHP session ID (primary key) |
| lock_token | VARCHAR(64) | Unique token identifying the lock holder |
| locked_at | INT UNSIGNED | Unix timestamp when lock was acquired |