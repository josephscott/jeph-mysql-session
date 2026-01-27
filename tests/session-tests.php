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

describe( 'Session Handler', function() {
	test( 'open returns true', function() {
		$session = new Session( pdo: $this->pdo );
		expect( $session->open( path: '', name: 'PHPSESSID' ) )->toBeTrue();
	} );

	test( 'read returns empty string for new session', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$data = $session->read( id: 'test_session_id_123' );
		expect( $data )->toBe( '' );

		$session->close();
	} );

	test( 'write stores session data', function() {
		$session = new Session( pdo: $this->pdo );
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
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_id_789';
		$test_data = 'user_id|i:42;name|s:4:"John";';

		$session->read( id: $session_id );
		$session->write( id: $session_id, data: $test_data );
		$session->close();

		// Read again in new session
		$session2 = new Session( pdo: $this->pdo );
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( $test_data );
	} );

	test( 'destroy removes session data', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_session_to_destroy';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'some_data' );
		$session->close();

		// Destroy the session
		$session2 = new Session( pdo: $this->pdo );
		$result = $session2->destroy( id: $session_id );
		expect( $result )->toBeTrue();

		// Verify it's gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'gc removes expired sessions', function() {
		$session = new Session( pdo: $this->pdo );
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
		$session2 = new Session( pdo: $this->pdo );
		$deleted = $session2->gc( max_lifetime: 1800 );

		expect( $deleted )->toBe( 1 );

		// Verify session is gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'gc does not remove recent sessions', function() {
		$session = new Session( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );

		$session_id = 'test_recent_session';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'recent_data' );
		$session->close();

		// Run gc - session should survive
		$session2 = new Session( pdo: $this->pdo );
		$deleted = $session2->gc( max_lifetime: 1800 );

		expect( $deleted )->toBe( 0 );

		// Verify session still exists
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 1 );
	} );
} );
