<?php
declare( strict_types = 1 );

use JEPH\MySQL\Session;

beforeEach( function() {
	$this->pdo = create_test_pdo();
	create_test_tables( $this->pdo );
} );

afterEach( function() {
	drop_test_tables( $this->pdo );
} );

describe( 'Session Locking', function() {
	test( 'lock is acquired on read', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'lock_test_session';
		$session->read( id: $session_id );

		// Check that lock exists in database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 1 );

		$session->close();
	} );

	test( 'lock is released on close', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'lock_release_test';
		$session->read( id: $session_id );
		$session->close();

		// Check that lock is removed
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'stale lock can be claimed', function() {
		$session_id = 'stale_lock_test';

		// Insert a stale lock manually (60 seconds old)
		$stale_time = time() - 60;
		$stmt = $this->pdo->prepare(
			'INSERT INTO session_locks (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)'
		);
		$stmt->execute( [
			':session_id' => $session_id,
			':lock_token' => 'old_token_123',
			':locked_at' => $stale_time,
		] );

		// Try to acquire lock with lock_max_age of 30 seconds
		$session = new Session(
			pdo: $this->pdo,
			lock_max_age: 30
		);
		$session->open( path: '', name: 'PHPSESSID' );

		$data = $session->read( id: $session_id );

		// Should succeed - stale lock was claimed
		expect( $data )->toBe( '' );

		// Verify our new lock is in place (different token)
		$stmt = $this->pdo->prepare( 'SELECT lock_token FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['lock_token'] )->not->toBe( 'old_token_123' );

		$session->close();
	} );

	test( 'lock timeout returns false', function() {
		$session_id = 'timeout_test_session';

		// Insert a fresh lock (not stale)
		$stmt = $this->pdo->prepare(
			'INSERT INTO session_locks (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)'
		);
		$stmt->execute( [
			':session_id' => $session_id,
			':lock_token' => 'held_by_another_process',
			':locked_at' => time(),
		] );

		// Try to acquire lock with very short timeout
		$session = new Session(
			pdo: $this->pdo,
			lock_timeout: 1,
			lock_max_age: 30,
			lock_retry_interval: 100
		);
		$session->open( path: '', name: 'PHPSESSID' );

		$start = time();
		$data = $session->read( id: $session_id );
		$elapsed = time() - $start;

		// Should fail after timeout
		expect( $data )->toBeFalse();
		expect( $elapsed )->toBeGreaterThanOrEqual( 1 );
	} );

	test( 'destroy cleans up lock', function() {
		$session_id = 'destroy_lock_test';

		// Insert a lock
		$stmt = $this->pdo->prepare(
			'INSERT INTO session_locks (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)'
		);
		$stmt->execute( [
			':session_id' => $session_id,
			':lock_token' => 'orphaned_lock',
			':locked_at' => time(),
		] );

		// Destroy should clean it up
		$session = new Session( pdo: $this->pdo );
		$session->destroy( id: $session_id );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'gc cleans up stale locks', function() {
		// Insert some stale locks
		$stale_time = time() - 120; // 2 minutes old
		for ( $i = 0; $i < 3; $i++ ) {
			$stmt = $this->pdo->prepare(
				'INSERT INTO session_locks (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)'
			);
			$stmt->execute( [
				':session_id' => "stale_gc_test_{$i}",
				':lock_token' => "stale_token_{$i}",
				':locked_at' => $stale_time,
			] );
		}

		// Run gc with lock_max_age of 30 seconds
		$session = new Session(
			pdo: $this->pdo,
			lock_max_age: 30
		);
		$session->gc( max_lifetime: 1800 );

		// All stale locks should be gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks' );
		$stmt->execute();
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Concurrent Session Locking', function() {
	test( 'second process waits for lock release', function() {
		$session_id = 'concurrent_test_' . uniqid();
		$helper_script = __DIR__ . '/helpers/lock-holder.php';

		// Skip if helper doesn't exist
		if ( ! file_exists( $helper_script ) ) {
			$this->markTestSkipped( 'Lock holder helper script not found' );
		}

		// Start a background process that holds a lock using proc_open
		$cmd = sprintf(
			'php %s %s %d',
			escapeshellarg( $helper_script ),
			escapeshellarg( $session_id ),
			2 // hold for 2 seconds
		);

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			$this->markTestSkipped( 'Failed to start background process' );
		}

		// Close stdin
		fclose( $pipes[0] );

		// Wait for the process to acquire the lock (read first line of output)
		$output = fgets( $pipes[1] );
		if ( strpos( $output, 'Lock acquired' ) === false ) {
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			$this->fail( "Background process failed to acquire lock: {$error}" );
		}

		// Verify lock exists
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 1 );

		// Try to acquire same lock - should succeed after background process releases
		$session = new Session(
			pdo: $this->pdo,
			lock_timeout: 5
		);
		$session->open( path: '', name: 'PHPSESSID' );

		$start = microtime( true );
		$data = $session->read( id: $session_id );
		$elapsed = microtime( true ) - $start;

		// Clean up background process
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		// Should have waited approximately 2 seconds (lock holder holds for 2s)
		expect( $data )->not->toBeFalse();
		expect( $elapsed )->toBeGreaterThan( 1.5 );
		expect( $elapsed )->toBeLessThan( 4.0 );

		$session->close();
	} );
} );

describe( 'Session Regenerate ID', function() {
	test( 'write to new session ID acquires lock', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$old_id = 'old_session_id';
		$new_id = 'new_session_id';

		// Read with old ID (acquires lock on old ID)
		$session->read( id: $old_id );

		// Verify lock on old ID
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $old_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// Write to new ID (simulates session_regenerate_id behavior)
		$result = $session->write( id: $new_id, data: 'test_data' );
		expect( $result )->toBeTrue();

		// Verify old lock is released
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $old_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		// Verify new lock is acquired
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// Verify data was written
		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( $row['data'] )->toBe( 'test_data' );

		$session->close();
	} );

	test( 'destroy then write simulates session_regenerate_id with delete', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$old_id = 'old_session_to_destroy';
		$new_id = 'new_regenerated_session';

		// Read and write to old session
		$session->read( id: $old_id );
		$session->write( id: $old_id, data: 'old_data' );

		// Verify old session exists
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $old_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// Destroy old session (simulates session_regenerate_id(true))
		$session->destroy( id: $old_id );

		// Verify old session and lock are gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $old_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $old_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		// Write to new session ID
		$result = $session->write( id: $new_id, data: 'new_data' );
		expect( $result )->toBeTrue();

		// Verify new session has lock and data
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( $row['data'] )->toBe( 'new_data' );

		$session->close();
	} );

	test( 'close releases lock on new session ID after regenerate', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$old_id = 'session_before_regenerate';
		$new_id = 'session_after_regenerate';

		// Read old, destroy old, write new (full regenerate flow)
		$session->read( id: $old_id );
		$session->destroy( id: $old_id );
		$session->write( id: $new_id, data: 'regenerated_data' );

		// Verify new lock exists before close
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// Close should release the new lock
		$session->close();

		// Verify new lock is released
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		// Verify data persists
		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $new_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( $row['data'] )->toBe( 'regenerated_data' );
	} );
} );
