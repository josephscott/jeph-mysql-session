<?php
declare( strict_types = 1 );

use JEPH\MySQL\Session;

/**
 * Integration tests that use actual PHP session functions (session_start, etc.)
 * to verify the handler works correctly in real-world scenarios.
 */
beforeEach( function() {
	$this->pdo = create_test_pdo();
	create_test_tables( $this->pdo );

	// Ensure no session is active from previous tests
	if ( session_status() === PHP_SESSION_ACTIVE ) {
		session_write_close();
	}

	// Reset session state
	$_SESSION = [];
} );

afterEach( function() {
	// Clean up any active session
	if ( session_status() === PHP_SESSION_ACTIVE ) {
		session_write_close();
	}

	// Reset session configuration to defaults
	session_name( 'PHPSESSID' );

	drop_test_tables( $this->pdo );
} );

describe( 'Integration - Basic Session Operations', function() {
	test( 'session_start works with handler', function() {
		$handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler, false );

		// Generate a unique session ID for this test
		session_id( 'integration_basic_' . bin2hex( random_bytes( 8 ) ) );

		$result = session_start();
		expect( $result )->toBeTrue();
		expect( session_status() )->toBe( PHP_SESSION_ACTIVE );

		// Set some session data
		$_SESSION['test_key'] = 'test_value';
		$_SESSION['user_id'] = 42;

		session_write_close();
		expect( session_status() )->toBe( PHP_SESSION_NONE );
	} );

	test( 'session data persists across session_start calls', function() {
		$session_id = 'integration_persist_' . bin2hex( random_bytes( 8 ) );

		// First session: write data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['username'] = 'testuser';
		$_SESSION['logged_in'] = true;

		session_write_close();

		// Clear $_SESSION to simulate new request
		$_SESSION = [];

		// Second session: read data
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['username'] )->toBe( 'testuser' );
		expect( $_SESSION['logged_in'] )->toBeTrue();

		session_write_close();
	} );

	test( 'session_destroy removes session data', function() {
		$session_id = 'integration_destroy_' . bin2hex( random_bytes( 8 ) );

		// Create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['data'] = 'to_be_destroyed';
		session_write_close();

		// Destroy the session
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();
		session_destroy();

		// Verify data is gone from database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Integration - read_and_close Option', function() {
	test( 'session_start with read_and_close reads data and closes immediately', function() {
		$session_id = 'integration_read_close_' . bin2hex( random_bytes( 8 ) );

		// First: create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['value'] = 'readable';
		session_write_close();

		$_SESSION = [];

		// Second: read with read_and_close
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		// Data should be available
		expect( $_SESSION['value'] )->toBe( 'readable' );

		// Session should already be closed
		expect( session_status() )->toBe( PHP_SESSION_NONE );

		// Lock should be released (verify by checking lock table)
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );

	test( 'read_and_close does not save modifications', function() {
		$session_id = 'integration_read_close_nosave_' . bin2hex( random_bytes( 8 ) );

		// Create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['original'] = 'value';
		session_write_close();

		$_SESSION = [];

		// Read with read_and_close and try to modify
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		// Modify $_SESSION (but session is already closed)
		$_SESSION['original'] = 'modified';
		$_SESSION['new_key'] = 'new_value';

		$_SESSION = [];

		// Verify original data is unchanged in database
		$handler3 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler3, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['original'] )->toBe( 'value' );
		expect( isset( $_SESSION['new_key'] ) )->toBeFalse();

		session_write_close();
	} );

	test( 'read_and_close allows another session to acquire lock immediately', function() {
		$session_id = 'integration_read_close_lock_' . bin2hex( random_bytes( 8 ) );

		// Create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['data'] = 'test';
		session_write_close();

		$_SESSION = [];

		// Open with read_and_close
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		// Immediately try to open same session normally (should not block)
		$handler3 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler3, false );

		// Need to reset session_id since previous session_start changed state
		session_id( $session_id );

		$start_time = microtime( true );
		session_start();
		$elapsed = microtime( true ) - $start_time;

		// Should acquire lock almost immediately (not waiting for timeout)
		expect( $elapsed )->toBeLessThan( 1.0 );
		expect( $_SESSION['data'] )->toBe( 'test' );

		session_write_close();
	} );
} );

describe( 'Integration - Read-Only Handler Mode', function() {
	test( 'read_only handler reads data without acquiring lock', function() {
		$session_id = 'integration_readonly_' . bin2hex( random_bytes( 8 ) );

		// Create session with data using normal handler
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['user_id'] = 123;
		session_write_close();

		$_SESSION = [];

		// Read with read_only handler
		$handler2 = Session::create( pdo: $this->pdo, read_only: true );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		// Data should be readable
		expect( $_SESSION['user_id'] )->toBe( 123 );

		// No lock should exist
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );

		session_write_close();
	} );

	test( 'read_only handler does not save session modifications', function() {
		$session_id = 'integration_readonly_nosave_' . bin2hex( random_bytes( 8 ) );

		// Create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['counter'] = 1;
		session_write_close();

		$_SESSION = [];

		// Modify with read_only handler
		$handler2 = Session::create( pdo: $this->pdo, read_only: true );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		// Try to modify
		$_SESSION['counter'] = 999;
		$_SESSION['new_field'] = 'ignored';

		session_write_close();

		$_SESSION = [];

		// Verify original data unchanged
		$handler3 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler3, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['counter'] )->toBe( 1 );
		expect( isset( $_SESSION['new_field'] ) )->toBeFalse();

		session_write_close();
	} );

	test( 'read_only handler allows concurrent access without blocking', function() {
		$session_id = 'integration_readonly_concurrent_' . bin2hex( random_bytes( 8 ) );

		// Create session with data
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['data'] = 'shared';
		session_write_close();

		$_SESSION = [];

		// Open normal session (holds lock)
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		// Verify lock is held
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// read_only access should still work even though lock is held
		// (because read_only doesn't try to acquire lock)
		$handler3 = Session::create( pdo: $this->pdo, read_only: true );
		$handler3->open( '', 'PHPSESSID' );

		$start_time = microtime( true );
		$data = $handler3->read( $session_id );
		$elapsed = microtime( true ) - $start_time;

		// Should read immediately without waiting
		expect( $elapsed )->toBeLessThan( 0.5 );
		expect( $data )->toContain( 'shared' );

		$handler3->close();
		session_write_close();
	} );
} );

describe( 'Integration - Read-Only to Read-Write Promotion', function() {
	test( 'can promote from read_and_close to full read-write session', function() {
		$session_id = 'integration_promote_' . bin2hex( random_bytes( 8 ) );

		// Step 1: Create initial session with data (simulates previous login)
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['user_id'] = 123;
		$_SESSION['username'] = 'testuser';
		$_SESSION['login_count'] = 5;

		session_write_close();
		$_SESSION = [];

		// Step 2: Use read_and_close to quickly check session (e.g., auth check on API)
		$ro_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $ro_handler, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		// Verify we can read the data
		expect( $_SESSION['user_id'] )->toBe( 123 );
		expect( $_SESSION['username'] )->toBe( 'testuser' );
		expect( $_SESSION['login_count'] )->toBe( 5 );

		// Session is already closed after read_and_close
		expect( session_status() )->toBe( PHP_SESSION_NONE );

		// Try to modify (won't be saved since session is closed)
		$_SESSION['login_count'] = 999;

		// Verify lock was released after read_and_close
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		$_SESSION = [];

		// Step 3: Promote to full read-write session (user wants to perform action)
		$rw_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $rw_handler, false );
		session_id( $session_id );
		session_start();

		// Verify original data is intact (read_and_close changes were not saved)
		expect( $_SESSION['user_id'] )->toBe( 123 );
		expect( $_SESSION['username'] )->toBe( 'testuser' );
		expect( $_SESSION['login_count'] )->toBe( 5 ); // Not 999

		// Verify lock is now held
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		// Now make real modifications
		$_SESSION['login_count'] = 6;
		$_SESSION['last_action'] = 'promoted_session';

		session_write_close();
		$_SESSION = [];

		// Step 4: Verify modifications were saved using read_and_close
		$verify_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $verify_handler, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['user_id'] )->toBe( 123 );
		expect( $_SESSION['username'] )->toBe( 'testuser' );
		expect( $_SESSION['login_count'] )->toBe( 6 ); // Updated
		expect( $_SESSION['last_action'] )->toBe( 'promoted_session' ); // New field
	} );

	test( 'promotion pattern with read_and_close for conditional write', function() {
		$session_id = 'integration_conditional_' . bin2hex( random_bytes( 8 ) );

		// Setup: Create session with user data
		$setup_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $setup_handler, false );
		session_id( $session_id );
		session_start();

		$_SESSION['user_id'] = 456;
		$_SESSION['cart_items'] = 3;

		session_write_close();
		$_SESSION = [];

		// Scenario: API endpoint that reads session, then conditionally writes

		// Phase 1: Quick read with read_and_close (no lingering lock)
		$check_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $check_handler, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		$user_id = $_SESSION['user_id'] ?? null;
		$cart_items = $_SESSION['cart_items'] ?? 0;

		expect( $user_id )->toBe( 456 );
		expect( $cart_items )->toBe( 3 );

		// Session is already closed
		expect( session_status() )->toBe( PHP_SESSION_NONE );

		// Simulate: user wants to add item to cart (requires write)
		$needs_write = true; // In real code: based on request

		$_SESSION = [];

		// Phase 2: If write needed, reopen with full access
		if ( $needs_write ) {
			$write_handler = Session::create( pdo: $this->pdo );
			session_set_save_handler( $write_handler, false );
			session_id( $session_id );
			session_start();

			// Verify data is still accessible
			expect( $_SESSION['user_id'] )->toBe( 456 );
			expect( $_SESSION['cart_items'] )->toBe( 3 );

			// Perform the write operation
			$_SESSION['cart_items'] = 4;

			session_write_close();
		}

		$_SESSION = [];

		// Verify the write succeeded using read_and_close
		$final_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $final_handler, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['cart_items'] )->toBe( 4 );
	} );

	test( 'multiple read_and_close checks before promotion', function() {
		$session_id = 'integration_multi_ro_' . bin2hex( random_bytes( 8 ) );

		// Setup session
		$setup = Session::create( pdo: $this->pdo );
		session_set_save_handler( $setup, false );
		session_id( $session_id );
		session_start();

		$_SESSION['visits'] = 0;
		$_SESSION['user'] = 'visitor';

		session_write_close();
		$_SESSION = [];

		// Simulate multiple AJAX requests using read_and_close
		for ( $i = 0; $i < 3; $i++ ) {
			$ro = Session::create( pdo: $this->pdo );
			session_set_save_handler( $ro, false );
			session_id( $session_id );

			session_start( [
				'read_and_close' => true,
			] );

			// Each read_and_close request sees the same data
			expect( $_SESSION['visits'] )->toBe( 0 );
			expect( $_SESSION['user'] )->toBe( 'visitor' );

			// Session automatically closed
			expect( session_status() )->toBe( PHP_SESSION_NONE );

			$_SESSION = [];
		}

		// Now user logs in - promote to write session
		$login_handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $login_handler, false );
		session_id( $session_id );
		session_start();

		$_SESSION['visits'] = 1;
		$_SESSION['user'] = 'authenticated_user';
		$_SESSION['logged_in_at'] = time();

		session_write_close();
		$_SESSION = [];

		// Subsequent read_and_close requests see updated data
		$final_ro = Session::create( pdo: $this->pdo );
		session_set_save_handler( $final_ro, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['visits'] )->toBe( 1 );
		expect( $_SESSION['user'] )->toBe( 'authenticated_user' );
		expect( isset( $_SESSION['logged_in_at'] ) )->toBeTrue();
	} );

	test( 'read_and_close promotion does not interfere with other sessions', function() {
		$session_a = 'integration_session_a_' . bin2hex( random_bytes( 8 ) );
		$session_b = 'integration_session_b_' . bin2hex( random_bytes( 8 ) );

		// Setup two different sessions
		$setup_a = Session::create( pdo: $this->pdo );
		session_set_save_handler( $setup_a, false );
		session_id( $session_a );
		session_start();
		$_SESSION['owner'] = 'user_a';
		$_SESSION['value'] = 100;
		session_write_close();
		$_SESSION = [];

		$setup_b = Session::create( pdo: $this->pdo );
		session_set_save_handler( $setup_b, false );
		session_id( $session_b );
		session_start();
		$_SESSION['owner'] = 'user_b';
		$_SESSION['value'] = 200;
		session_write_close();
		$_SESSION = [];

		// read_and_close on session A
		$ro_a = Session::create( pdo: $this->pdo );
		session_set_save_handler( $ro_a, false );
		session_id( $session_a );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['owner'] )->toBe( 'user_a' );
		expect( $_SESSION['value'] )->toBe( 100 );
		$_SESSION = [];

		// Promote session B to read-write
		$rw_b = Session::create( pdo: $this->pdo );
		session_set_save_handler( $rw_b, false );
		session_id( $session_b );
		session_start();
		$_SESSION['value'] = 250;
		session_write_close();
		$_SESSION = [];

		// Verify session A is unchanged using read_and_close
		$verify_a = Session::create( pdo: $this->pdo );
		session_set_save_handler( $verify_a, false );
		session_id( $session_a );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['owner'] )->toBe( 'user_a' );
		expect( $_SESSION['value'] )->toBe( 100 ); // Unchanged
		$_SESSION = [];

		// Verify session B was updated using read_and_close
		$verify_b = Session::create( pdo: $this->pdo );
		session_set_save_handler( $verify_b, false );
		session_id( $session_b );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['owner'] )->toBe( 'user_b' );
		expect( $_SESSION['value'] )->toBe( 250 ); // Updated
	} );

	test( 'compare read_and_close vs read_only handler behavior', function() {
		$session_id = 'integration_compare_' . bin2hex( random_bytes( 8 ) );

		// Setup session
		$setup = Session::create( pdo: $this->pdo );
		session_set_save_handler( $setup, false );
		session_id( $session_id );
		session_start();
		$_SESSION['data'] = 'original';
		session_write_close();
		$_SESSION = [];

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM session_locks WHERE session_id = :session_id' );

		// Test 1: read_and_close - acquires lock briefly, then releases
		$handler1 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler1, false );
		session_id( $session_id );

		session_start( [
			'read_and_close' => true,
		] );

		expect( $_SESSION['data'] )->toBe( 'original' );
		expect( session_status() )->toBe( PHP_SESSION_NONE ); // Already closed

		// Lock should be released
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		$_SESSION = [];

		// Test 2: read_only handler - never acquires lock
		$handler2 = Session::create( pdo: $this->pdo, read_only: true );
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['data'] )->toBe( 'original' );
		expect( session_status() )->toBe( PHP_SESSION_ACTIVE ); // Still active

		// Lock should never have been acquired
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );

		session_write_close();
		$_SESSION = [];

		// Test 3: Normal session - holds lock until close
		$handler3 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler3, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['data'] )->toBe( 'original' );
		expect( session_status() )->toBe( PHP_SESSION_ACTIVE );

		// Lock should be held
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 1 );

		session_write_close();

		// Lock released after close
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Integration - Custom Session Name', function() {
	test( 'session works with custom session name', function() {
		$session_id = 'integration_custom_name_' . bin2hex( random_bytes( 8 ) );

		$handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler, false );
		session_name( 'MY_CUSTOM_SESSION' );
		session_id( $session_id );
		session_start();

		$_SESSION['app'] = 'custom_app';
		session_write_close();

		$_SESSION = [];

		// Read back with same custom name
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_name( 'MY_CUSTOM_SESSION' );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['app'] )->toBe( 'custom_app' );

		session_write_close();
	} );
} );

describe( 'Integration - Fingerprint Protection', function() {
	test( 'fingerprint protection works with session_start', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Integration Test Browser';
		$session_id = 'integration_fingerprint_' . bin2hex( random_bytes( 8 ) );

		// Create session with fingerprint
		$handler1 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['secret'] = 'protected_value';
		session_write_close();

		$_SESSION = [];

		// Read back with same User-Agent
		$handler2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		expect( $_SESSION['secret'] )->toBe( 'protected_value' );

		session_write_close();
	} );

	test( 'fingerprint mismatch invalidates session via session_start', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Original Browser';
		$session_id = 'integration_fingerprint_fail_' . bin2hex( random_bytes( 8 ) );

		// Create session with fingerprint
		$handler1 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		session_set_save_handler( $handler1, false );
		session_id( $session_id );
		session_start();

		$_SESSION['secret'] = 'should_not_see_this';
		session_write_close();

		$_SESSION = [];

		// Change User-Agent (simulate hijacking)
		$_SERVER['HTTP_USER_AGENT'] = 'Attacker Browser';

		// Try to read with different User-Agent
		$handler2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		session_set_save_handler( $handler2, false );
		session_id( $session_id );
		session_start();

		// Session should be empty (invalidated)
		expect( isset( $_SESSION['secret'] ) )->toBeFalse();

		session_write_close();
	} );
} );

describe( 'Integration - Session Regeneration', function() {
	test( 'session_regenerate_id preserves data', function() {
		$original_id = 'integration_regen_' . bin2hex( random_bytes( 8 ) );

		$handler = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler, false );
		session_id( $original_id );
		session_start();

		$_SESSION['user_id'] = 456;
		$_SESSION['keep_this'] = 'preserved';

		// Regenerate the session ID
		session_regenerate_id( delete_old_session: true );
		$new_id = session_id();

		expect( $new_id )->not->toBe( $original_id );

		// Data should still be present
		expect( $_SESSION['user_id'] )->toBe( 456 );
		expect( $_SESSION['keep_this'] )->toBe( 'preserved' );

		session_write_close();

		$_SESSION = [];

		// Verify data persists with new ID
		$handler2 = Session::create( pdo: $this->pdo );
		session_set_save_handler( $handler2, false );
		session_id( $new_id );
		session_start();

		expect( $_SESSION['user_id'] )->toBe( 456 );
		expect( $_SESSION['keep_this'] )->toBe( 'preserved' );

		session_write_close();

		// Verify old session is gone
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $original_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );
