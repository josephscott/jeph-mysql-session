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

describe( 'Factory Validation', function() {
	test( 'create returns Session for valid table names', function() {
		$session = Session::create(
			pdo: $this->pdo,
			table_name: 'my_sessions_123',
			lock_table_name: 'my_locks_456'
		);
		expect( $session )->toBeInstanceOf( Session::class );
	} );

	test( 'create returns false for table name with spaces', function() {
		$session = Session::create(
			pdo: $this->pdo,
			table_name: 'my sessions'
		);
		expect( $session )->toBeFalse();
	} );

	test( 'create returns false for table name with semicolon', function() {
		$session = Session::create(
			pdo: $this->pdo,
			table_name: 'sessions; DROP TABLE users;--'
		);
		expect( $session )->toBeFalse();
	} );

	test( 'create returns false for table name with quotes', function() {
		$session = Session::create(
			pdo: $this->pdo,
			table_name: "sessions'--"
		);
		expect( $session )->toBeFalse();
	} );

	test( 'create returns false for empty table name', function() {
		$session = Session::create(
			pdo: $this->pdo,
			table_name: ''
		);
		expect( $session )->toBeFalse();
	} );

	test( 'create returns false for invalid lock table name', function() {
		$session = Session::create(
			pdo: $this->pdo,
			lock_table_name: 'locks; DROP TABLE users;--'
		);
		expect( $session )->toBeFalse();
	} );
} );

describe( 'Session Handler', function() {
	test( 'open returns true', function() {
		$session = Session::create( pdo: $this->pdo );
		expect( $session->open( path: '', name: 'PHPSESSID' ) )->toBeTrue();
	} );

	test( 'read returns empty string for new session', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$data = $session->read( id: 'test_session_id_123' );
		expect( $data )->toBe( '' );

		$session->close();
	} );

	test( 'write stores session data', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_id_456';
		$session->read( id: $session_id );

		$result = $session->write( id: $session_id, data: 'foo|s:3:"bar";' );
		expect( $result )->toBeTrue();

		$session->close();

		// Verify data was stored
		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['data'] )->toBe( 'foo|s:3:"bar";' );
	} );

	test( 'read returns previously written data', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_id_789';
		$test_data = 'user_id|i:42;name|s:4:"John";';

		$session->read( id: $session_id );
		$session->write( id: $session_id, data: $test_data );
		$session->close();

		// Read again in new session
		$session2 = Session::create( pdo: $this->pdo );
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( $test_data );
	} );

	test( 'destroy removes session data', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_to_destroy';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'some_data' );
		$session->close();

		// Destroy the session
		$session2 = Session::create( pdo: $this->pdo );
		$result = $session2->destroy( id: $session_id );
		expect( $result )->toBeTrue();

		// Verify it's gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'gc removes expired sessions', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		// Create a session
		$session_id = 'test_old_session';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'old_data' );
		$session->close();

		// Manually set last_accessed to the past
		$old_time = time() - 3600; // 1 hour ago
		$stmt = $this->pdo->prepare( 'UPDATE sessions SET last_accessed = :time WHERE session_id = :session_id' );
		$stmt->execute( [ ':time' => $old_time, ':session_id' => $session_id ] );

		// Run gc with 30 minute lifetime
		$session2 = Session::create( pdo: $this->pdo );
		$deleted = $session2->gc( max_lifetime: 1800 );

		expect( $deleted )->toBe( 1 );

		// Verify session is gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'gc does not remove recent sessions', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_recent_session';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'recent_data' );
		$session->close();

		// Run gc - session should survive
		$session2 = Session::create( pdo: $this->pdo );
		$deleted = $session2->gc( max_lifetime: 1800 );

		expect( $deleted )->toBe( 0 );

		// Verify session still exists
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 1 );
	} );

	test( 'read can be called multiple times for session_reset support', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_reset';

		// First read acquires lock and returns empty for new session
		$data1 = $session->read( id: $session_id );
		expect( $data1 )->toBe( '' );

		// Write some data
		$session->write( id: $session_id, data: 'original_data' );

		// Second read on same session should work (simulates session_reset)
		$data2 = $session->read( id: $session_id );
		expect( $data2 )->toBe( 'original_data' );

		// Verify we still hold the lock
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		$session->close();
	} );

	test( 'close without write for session_abort support', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_abort';

		// Read acquires lock
		$session->read( id: $session_id );

		// Close without writing (simulates session_abort)
		$result = $session->close();
		expect( $result )->toBeTrue();

		// Verify lock is released
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		// Verify no session data was written
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Session ID Generation', function() {
	test( 'create_sid returns a string', function() {
		$session = Session::create( pdo: $this->pdo );
		$sid = $session->create_sid();

		expect( $sid )->toBeString();
	} );

	test( 'create_sid returns 64 character hex string', function() {
		$session = Session::create( pdo: $this->pdo );
		$sid = $session->create_sid();

		// 32 bytes = 64 hex characters
		expect( strlen( $sid ) )->toBe( 64 );
		// Should only contain hex characters
		expect( preg_match( '/^[a-f0-9]+$/', $sid ) )->toBe( 1 );
	} );

	test( 'create_sid returns unique IDs', function() {
		$session = Session::create( pdo: $this->pdo );

		$ids = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$ids[] = $session->create_sid();
		}

		// All IDs should be unique
		$unique_ids = array_unique( $ids );
		expect( count( $unique_ids ) )->toBe( 100 );
	} );

	test( 'create_sid can be used as session ID', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		// Generate a new session ID
		$session_id = $session->create_sid();

		// Use it to create a session
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'test_data' );
		$session->close();

		// Verify data was stored with generated ID
		$stmt = $this->pdo->prepare( 'SELECT data FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['data'] )->toBe( 'test_data' );
	} );
} );

describe( 'Session Validation and Timestamp', function() {
	test( 'validateId returns false for non-existent session', function() {
		$session = Session::create( pdo: $this->pdo );

		$result = $session->validateId( id: 'non_existent_session_id' );
		expect( $result )->toBeFalse();
	} );

	test( 'validateId returns true for existing session', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'existing_session_for_validate';

		// Create a session
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'some_data' );
		$session->close();

		// Validate the session ID
		$session2 = Session::create( pdo: $this->pdo );
		$result = $session2->validateId( id: $session_id );
		expect( $result )->toBeTrue();
	} );

	test( 'validateId returns false after session is destroyed', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'session_to_validate_then_destroy';

		// Create and then destroy the session
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'temp_data' );
		$session->destroy( id: $session_id );
		$session->close();

		// Validate should return false
		$session2 = Session::create( pdo: $this->pdo );
		$result = $session2->validateId( id: $session_id );
		expect( $result )->toBeFalse();
	} );

	test( 'updateTimestamp updates last_accessed without changing data', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'session_for_timestamp_update';
		$original_data = 'original_session_data';

		// Create a session
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: $original_data );
		$session->close();

		// Get original timestamp
		$stmt = $this->pdo->prepare( 'SELECT last_accessed FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		$original_timestamp = (int) $row['last_accessed'];

		// Wait a moment to ensure timestamp changes
		sleep( 1 );

		// Update timestamp only
		$session2 = Session::create( pdo: $this->pdo );
		$session2->open( path: '', name: 'PHPSESSID' );
		$session2->read( id: $session_id );
		$result = $session2->updateTimestamp( id: $session_id, data: $original_data );
		$session2->close();

		expect( $result )->toBeTrue();

		// Verify timestamp changed but data stayed the same
		$stmt = $this->pdo->prepare( 'SELECT data, last_accessed FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['data'] )->toBe( $original_data );
		expect( (int) $row['last_accessed'] )->toBeGreaterThan( $original_timestamp );
	} );

	test( 'updateTimestamp acquires lock when needed', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'session_for_timestamp_lock_test';

		// Create a session
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'data' );
		$session->close();

		// Call updateTimestamp without prior read (no lock held)
		$session2 = Session::create( pdo: $this->pdo );
		$session2->open( path: '', name: 'PHPSESSID' );
		$result = $session2->updateTimestamp( id: $session_id, data: 'data' );

		// Verify lock was acquired
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		expect( $result )->toBeTrue();

		// Close releases the lock
		$session2->close();

		// Verify lock is released
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );
