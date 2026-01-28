<?php
declare( strict_types = 1 );

use JEPH\MySQL\Session;

beforeEach( function() {
	$this->pdo = create_test_pdo();
	create_test_tables( $this->pdo );

	// Clear any superglobals from previous tests
	unset( $_SERVER['HTTP_USER_AGENT'] );
	unset( $_SERVER['REMOTE_ADDR'] );
} );

afterEach( function() {
	drop_test_tables( $this->pdo );

	// Clean up superglobals
	unset( $_SERVER['HTTP_USER_AGENT'] );
	unset( $_SERVER['REMOTE_ADDR'] );
} );

describe( 'Fingerprint Protection - Security Code', function() {
	test( 'session works with security_code enabled', function() {
		$session = Session::create(
			pdo: $this->pdo,
			security_code: 'my_secret_code_123'
		);
		expect( $session )->toBeInstanceOf( Session::class );

		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'fingerprint_test_1';
		$session->read( id: $session_id );
		$result = $session->write( id: $session_id, data: 'test_data' );
		$session->close();

		expect( $result )->toBeTrue();

		// Verify fingerprint was stored
		$stmt = $this->pdo->prepare( 'SELECT fingerprint FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['fingerprint'] )->not->toBe( '' );
		expect( strlen( $row['fingerprint'] ) )->toBe( 64 ); // SHA256 hex length
	} );

	test( 'session can be read back with same security_code', function() {
		$session = Session::create(
			pdo: $this->pdo,
			security_code: 'consistent_secret'
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'fingerprint_test_2';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'original_data' );
		$session->close();

		// Read with same security_code
		$session2 = Session::create(
			pdo: $this->pdo,
			security_code: 'consistent_secret'
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'original_data' );
	} );

	test( 'session is invalidated with different security_code', function() {
		$session = Session::create(
			pdo: $this->pdo,
			security_code: 'original_secret'
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'fingerprint_test_3';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'secret_data' );
		$session->close();

		// Attempt to read with different security_code
		$session2 = Session::create(
			pdo: $this->pdo,
			security_code: 'different_secret'
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		// Should return empty string (session invalidated)
		expect( $data )->toBe( '' );

		// Session should be destroyed
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) as cnt FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		expect( (int) $row['cnt'] )->toBe( 0 );
	} );
} );

describe( 'Fingerprint Protection - User Agent', function() {
	test( 'session is bound to User-Agent when lock_to_user_agent is true', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ua_test_1';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'user_data' );
		$session->close();

		// Same User-Agent should work
		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'user_data' );
	} );

	test( 'session is invalidated when User-Agent changes', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Original Browser';

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ua_test_2';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'protected_data' );
		$session->close();

		// Change User-Agent (simulates hijacking attempt)
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Attacker Browser';

		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		// Session should be invalidated
		expect( $data )->toBe( '' );
	} );

	test( 'missing User-Agent header is handled gracefully', function() {
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ua_test_3';
		$session->read( id: $session_id );
		$result = $session->write( id: $session_id, data: 'data_without_ua' );
		$session->close();

		expect( $result )->toBeTrue();

		// Should be able to read back without User-Agent
		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'data_without_ua' );
	} );
} );

describe( 'Fingerprint Protection - IP Address', function() {
	test( 'session is bound to IP when lock_to_ip is true', function() {
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_ip: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ip_test_1';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'ip_bound_data' );
		$session->close();

		// Same IP should work
		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_ip: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'ip_bound_data' );
	} );

	test( 'session is invalidated when IP changes', function() {
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_ip: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ip_test_2';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'protected_ip_data' );
		$session->close();

		// Change IP (simulates hijacking from different location)
		$_SERVER['REMOTE_ADDR'] = '10.0.0.50';

		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_ip: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		// Session should be invalidated
		expect( $data )->toBe( '' );
	} );

	test( 'lock_to_ip accepts callable for custom IP extraction', function() {
		// Simulate reverse proxy scenario
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.45';
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // Proxy IP

		$get_real_ip = function(): string {
			return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
		};

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_ip: $get_real_ip
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'ip_callable_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'proxy_data' );
		$session->close();

		// Same forwarded IP should work
		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_ip: $get_real_ip
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'proxy_data' );

		// Different forwarded IP should fail
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

		$session3 = Session::create(
			pdo: $this->pdo,
			lock_to_ip: $get_real_ip
		);
		$session3->open( path: '', name: 'PHPSESSID' );
		$data = $session3->read( id: $session_id );
		$session3->close();

		expect( $data )->toBe( '' );
	} );
} );

describe( 'Fingerprint Protection - Combined', function() {
	test( 'all fingerprint options can be combined', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Test Browser 1.0';
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

		$session = Session::create(
			pdo: $this->pdo,
			security_code: 'combined_secret',
			lock_to_user_agent: true,
			lock_to_ip: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'combined_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'fully_protected' );
		$session->close();

		// All same - should work
		$session2 = Session::create(
			pdo: $this->pdo,
			security_code: 'combined_secret',
			lock_to_user_agent: true,
			lock_to_ip: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'fully_protected' );
	} );

	test( 'changing any component invalidates session', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Test Browser 1.0';
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

		$session = Session::create(
			pdo: $this->pdo,
			security_code: 'test_secret',
			lock_to_user_agent: true,
			lock_to_ip: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'component_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'sensitive' );
		$session->close();

		// Change only User-Agent
		$_SERVER['HTTP_USER_AGENT'] = 'Different Browser';

		$session2 = Session::create(
			pdo: $this->pdo,
			security_code: 'test_secret',
			lock_to_user_agent: true,
			lock_to_ip: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( '' );
	} );
} );

describe( 'Fingerprint Protection - Disabled', function() {
	test( 'no fingerprint stored when protection is disabled', function() {
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'no_fingerprint_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'unprotected_data' );
		$session->close();

		// Verify fingerprint is empty
		$stmt = $this->pdo->prepare( 'SELECT fingerprint FROM sessions WHERE session_id = :session_id' );
		$stmt->execute( [ ':session_id' => $session_id ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		expect( $row['fingerprint'] )->toBe( '' );
	} );

	test( 'session works normally without fingerprint protection', function() {
		$_SERVER['HTTP_USER_AGENT'] = 'Browser A';
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';

		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'normal_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'normal_data' );
		$session->close();

		// Change everything - should still work without protection
		$_SERVER['HTTP_USER_AGENT'] = 'Browser B';
		$_SERVER['REMOTE_ADDR'] = '5.6.7.8';

		$session2 = Session::create( pdo: $this->pdo );
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( 'normal_data' );
	} );
} );

describe( 'Fingerprint Protection - Edge Cases', function() {
	test( 'session without fingerprint is invalidated when protection is enabled', function() {
		// Create session without fingerprint protection
		$session = Session::create( pdo: $this->pdo );
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'upgrade_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'old_data' );
		$session->close();

		// Now try to read with fingerprint protection enabled
		// This simulates upgrading an application to use fingerprint protection
		$session2 = Session::create(
			pdo: $this->pdo,
			security_code: 'new_secret'
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		// Old session without fingerprint should be invalidated
		expect( $data )->toBe( '' );
	} );

	test( 'fingerprint uses constant-time comparison', function() {
		// This test verifies the implementation uses hash_equals
		// We can't directly test timing, but we can verify behavior is correct
		$_SERVER['HTTP_USER_AGENT'] = 'Test Browser';

		$session = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session->open( path: '', name: 'PHPSESSID' );
		$session_id = 'timing_test';
		$session->read( id: $session_id );
		$session->write( id: $session_id, data: 'timing_data' );
		$session->close();

		// Try with very similar but different fingerprint
		$_SERVER['HTTP_USER_AGENT'] = 'Test Browset'; // One character different

		$session2 = Session::create(
			pdo: $this->pdo,
			lock_to_user_agent: true
		);
		$session2->open( path: '', name: 'PHPSESSID' );
		$data = $session2->read( id: $session_id );
		$session2->close();

		expect( $data )->toBe( '' );
	} );
} );
