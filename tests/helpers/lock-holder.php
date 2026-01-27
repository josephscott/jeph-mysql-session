<?php
/**
 * Helper script for testing concurrent session locking.
 * Acquires a session lock and holds it for a specified duration.
 *
 * Usage: php lock-holder.php <session_id> [hold_seconds]
 */
declare( strict_types = 1 );

// Only run when executed directly, not when included
if ( PHP_SAPI !== 'cli' || ! isset( $argv ) || realpath( $argv[0] ) !== realpath( __FILE__ ) ) {
	return;
}

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php lock-holder.php <session_id> [hold_seconds]\n" );
	exit( 1 );
}

$session_id = $argv[1];
$hold_seconds = isset( $argv[2] ) ? (int) $argv[2] : 2;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../Pest.php';

use JEPH\MySQL\Session;

// Connect to database using test configuration
$config = get_test_dsn();
$pdo = new PDO(
	dsn: $config['dsn'],
	username: $config['username'],
	password: $config['password'],
	options: [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
	]
);

// Ensure tables exist
create_test_tables( $pdo );

// Create session handler and acquire lock
$session = new Session( pdo: $pdo );
$session->open( path: '', name: 'PHPSESSID' );

$data = $session->read( id: $session_id );
if ( $data === false ) {
	fwrite( STDERR, "Failed to acquire lock for session: {$session_id}\n" );
	exit( 1 );
}

fwrite( STDOUT, "Lock acquired for session: {$session_id}\n" );
fwrite( STDOUT, "Holding lock for {$hold_seconds} seconds...\n" );

// Hold the lock
sleep( $hold_seconds );

// Release the lock
$session->close();
fwrite( STDOUT, "Lock released for session: {$session_id}\n" );

exit( 0 );
