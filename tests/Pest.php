<?php
declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php';

/**
 * Get the database configuration for testing.
 * Returns an array with 'dsn', 'username', 'password' keys.
 */
function get_test_dsn(): array {
	$host = getenv( 'TEST_DB_HOST' ) ?: 'localhost';
	$port = getenv( 'TEST_DB_PORT' ) ?: '3306';
	$database = getenv( 'TEST_DB_NAME' ) ?: 'jeph_session_test';

	return [
		'dsn' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
		'username' => getenv( 'TEST_DB_USER' ) ?: 'jeph_session_test',
		'password' => getenv( 'TEST_DB_PASS' ) ?: 'jeph_session_test',
	];
}

/**
 * Create a test PDO connection.
 * Requires MySQL database configured via environment variables.
 */
function create_test_pdo(): PDO {
	$config = get_test_dsn();

	return new PDO(
		dsn: $config['dsn'],
		username: $config['username'],
		password: $config['password'],
		options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
		]
	);
}

/**
 * Create the test tables.
 */
function create_test_tables( PDO $pdo ): void {
	$pdo->exec( '
		CREATE TABLE IF NOT EXISTS session_locks (
			session_id VARCHAR(128) NOT NULL PRIMARY KEY,
			lock_token VARCHAR(64) NOT NULL,
			locked_at INT UNSIGNED NOT NULL,
			INDEX idx_locked_at (locked_at)
		) ENGINE=InnoDB
	' );

	$pdo->exec( '
		CREATE TABLE IF NOT EXISTS sessions (
			session_id VARCHAR(128) NOT NULL PRIMARY KEY,
			data MEDIUMBLOB NOT NULL,
			fingerprint VARCHAR(64) NOT NULL DEFAULT \'\',
			last_accessed INT UNSIGNED NOT NULL,
			INDEX idx_last_accessed (last_accessed)
		) ENGINE=InnoDB
	' );
}

/**
 * Drop test tables for clean state.
 */
function drop_test_tables( PDO $pdo ): void {
	$pdo->exec( 'DROP TABLE IF EXISTS sessions' );
	$pdo->exec( 'DROP TABLE IF EXISTS session_locks' );
}
