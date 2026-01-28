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

describe( 'Read-Only Mode - Basic Functionality', function() {
	test( 'is_read_only returns false by default', function() {
		$session = Session::create( pdo: $this->pdo );
		expect( $session->is_read_only() )->toBeFalse();
	} );

	test( 'is_read_only returns true when read_only is enabled', function() {
		$session = Session::create(
			pdo: $this->pdo,
			read_only: true
		);
		expect( $session->is_read_only() )->toBeTrue();
	} );

	test( 'read-only session can read existing session data', function() {
		// First, create a session with data (in normal mode)
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'read_only_test_1';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'user_id|i:123;' );
		$session->close();

		// Now read it in read-only mode
		$ro_session = Session::create(
			pdo: $this->pdo,
			read_only: true
		);
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$data = $ro_session->read( id: $session_id );
		$ro_session->close();

		expect( $data )->toBe( 'user_id|i:123;' );
	} );

	test( 'read-only session returns empty string for non-existent session', function() {
		$session = Session::create(
			pdo: $this->pdo,
			read_only: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$data = $session->read( id: 'non_existent_session' );
		$session->close();

		expect( $data )->toBe( '' );
	} );
} );

describe( 'Read-Only Mode - No Lock Acquisition', function() {
	test( 'read-only session does not acquire lock', function() {
		// Create a session with data
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'no_lock_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'test_data' );
		$session->close();

		// Read in read-only mode
		$ro_session = Session::create(
			pdo: $this->pdo,
			read_only: true
		);
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$ro_session->read( id: $session_id );

		// Check that no lock was created
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );

		$ro_session->close();
	} );

	test( 'multiple read-only sessions can read same session concurrently', function() {
		// Create a session with data
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'concurrent_read_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'shared_data' );
		$session->close();

		// Open multiple read-only sessions simultaneously
		$ro_session1 = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session2 = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session3 = Session::create( pdo: $this->pdo, read_only: true );

		$ro_session1->open( path: '', name: 'PHPSESSID' );
		$ro_session2->open( path: '', name: 'PHPSESSID' );
		$ro_session3->open( path: '', name: 'PHPSESSID' );

		// All should be able to read without blocking
		$data1 = $ro_session1->read( id: $session_id );
		$data2 = $ro_session2->read( id: $session_id );
		$data3 = $ro_session3->read( id: $session_id );

		expect( $data1 )->toBe( 'shared_data' );
		expect( $data2 )->toBe( 'shared_data' );
		expect( $data3 )->toBe( 'shared_data' );

		// No locks should exist
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		$ro_session1->close();
		$ro_session2->close();
		$ro_session3->close();
	} );

	test( 'read-only session does not block normal session', function() {
		// Create initial session
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'no_block_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'initial_data' );
		$session->close();

		// Open read-only session
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$ro_session->read( id: $session_id );

		// Normal session should be able to acquire lock while read-only has "read" it
		$normal_session = Session::create( pdo: $this->pdo );
		$normal_session->open( path: '', name: 'PHPSESSID' );
		$data = $normal_session->read( id: $session_id );

		expect( $data )->toBe( 'initial_data' );

		// Normal session should have the lock
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		$normal_session->close();
		$ro_session->close();
	} );
} );

describe( 'Read-Only Mode - No Writes', function() {
	test( 'write returns true but does not save data', function() {
		// Create a session with data
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'no_write_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'original_data' );
		$session->close();

		// Try to write in read-only mode
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$ro_session->read( id: $session_id );
		$result = $ro_session->write( id: $session_id, data: 'modified_data' );
		$ro_session->close();

		// Write should return true
		expect( $result )->toBeTrue();

		// But data should not have changed
		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['data'] )->toBe( 'original_data' );
	} );

	test( 'updateTimestamp returns true but does not update', function() {
		// Create a session with data
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'no_timestamp_update_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'test_data' );
		$session->close();

		// Get original timestamp
		$stmt = $this->pdo->prepare( 'SELECT last_accessed FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		$original_timestamp = (int) $row['last_accessed'];

		// Wait a moment
		sleep( 1 );

		// Try to update timestamp in read-only mode
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$result = $ro_session->updateTimestamp( id: $session_id, data: 'test_data' );
		$ro_session->close();

		// Should return true
		expect( $result )->toBeTrue();

		// But timestamp should not have changed
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['last_accessed'] )->toBe( $original_timestamp );
	} );

	test( 'read-only session cannot create new session', function() {
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'new_session_attempt';
		$ro_session->read( id: $session_id );
		$ro_session->write( id: $session_id, data: 'new_data' );
		$ro_session->close();

		// Session should not have been created
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Read-Only Mode - With Fingerprint Protection', function() {
	test( 'read-only session validates fingerprint', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Test Browser';

		// Create session with fingerprint
		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'fingerprint_ro_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'protected_data' );
		$session->close();

		// Read with same fingerprint in read-only mode
		$ro_session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true,
			read_only: true
		);
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$data = $ro_session->read( id: $session_id );
		$ro_session->close();

		expect( $data )->toBe( 'protected_data' );
	} );

	test( 'read-only session returns empty on fingerprint mismatch without destroying', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Original Browser';

		// Create session with fingerprint
		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'fingerprint_mismatch_ro_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'protected_data' );
		$session->close();

		// Change User-Agent
		$_SERVER['HTTP_USER_AGENT'] = 'Different Browser';

		// Read with different fingerprint in read-only mode
		$ro_session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true,
			read_only: true
		);
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$data = $ro_session->read( id: $session_id );
		$ro_session->close();

		// Should return empty string
		expect( $data )->toBe( '' );

		// But session should NOT be destroyed (read-only can't write/delete)
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 1 );
	} );
} );

describe( 'Read-Only Mode - Close Behavior', function() {
	test( 'close returns true in read-only mode', function() {
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$ro_session->read( id: 'any_session' );

		$result = $ro_session->close();
		expect( $result )->toBeTrue();
	} );

	test( 'close does not try to release non-existent lock', function() {
		// Create a session
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'close_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'data' );
		// Keep the lock held

		// Open read-only session for the same ID
		$ro_session = Session::create( pdo: $this->pdo, read_only: true );
		$ro_session->open( path: '', name: 'PHPSESSID' );
		$ro_session->read( id: $session_id );
		$ro_session->close();

		// Original session's lock should still exist
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		$session->close();
	} );
} );
