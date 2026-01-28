<?php
declare( strict_types = 1 );

namespace JEPH\MySQL;

use PDO;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;
use function bin2hex;
use function hash;
use function hash_equals;
use function is_callable;
use function preg_match;
use function random_bytes;
use function time;
use function usleep;

class Session implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface {
	private PDO $pdo;

	private string $table_name;

	private string $lock_table_name;

	private ?string $session_id = null;

	private string $lock_token = '';

	private int $lock_timeout;

	private int $lock_max_age;

	private int $lock_retry_interval;

	private string $security_code;

	private bool $lock_to_user_agent;

	/** @var bool|callable */
	private mixed $lock_to_ip;

	private bool $read_only;

	/**
	 * Create a new Session handler instance.
	 *
	 * @param PDO $pdo Database connection
	 * @param string $table_name Session data table name
	 * @param string $lock_table_name Lock table name
	 * @param int $lock_timeout Seconds to wait for lock acquisition
	 * @param int $lock_max_age Seconds before a lock is considered stale
	 * @param int $lock_retry_interval Milliseconds between lock retry attempts
	 * @param string $security_code Secret string for session fingerprint (hijacking protection)
	 * @param bool $lock_to_user_agent Bind session to User-Agent header
	 * @param bool|callable $lock_to_ip Bind session to IP address (true uses REMOTE_ADDR, callable for custom)
	 * @param bool $read_only Open session in read-only mode (no locks, no writes)
	 * @return self|false Returns false if validation fails
	 */
	public static function create(
		PDO $pdo,
		string $table_name = 'sessions',
		string $lock_table_name = 'session_locks',
		int $lock_timeout = 10,
		int $lock_max_age = 30,
		int $lock_retry_interval = 100,
		string $security_code = '',
		bool $lock_to_user_agent = false,
		bool|callable $lock_to_ip = false,
		bool $read_only = false
	): self|false {
		// Validate table names to prevent SQL injection
		// Only allow alphanumeric characters and underscores
		if ( self::is_valid_table_name( $table_name ) === false ) {
			return false;
		}
		if ( self::is_valid_table_name( $lock_table_name ) === false ) {
			return false;
		}

		// Validate numeric parameters must be positive
		if ( $lock_timeout < 1 ) {
			return false;
		}
		if ( $lock_max_age < 1 ) {
			return false;
		}
		if ( $lock_retry_interval < 1 ) {
			return false;
		}

		return new self(
			pdo: $pdo,
			table_name: $table_name,
			lock_table_name: $lock_table_name,
			lock_timeout: $lock_timeout,
			lock_max_age: $lock_max_age,
			lock_retry_interval: $lock_retry_interval,
			security_code: $security_code,
			lock_to_user_agent: $lock_to_user_agent,
			lock_to_ip: $lock_to_ip,
			read_only: $read_only
		);
	}

	private function __construct(
		PDO $pdo,
		string $table_name,
		string $lock_table_name,
		int $lock_timeout,
		int $lock_max_age,
		int $lock_retry_interval,
		string $security_code,
		bool $lock_to_user_agent,
		bool|callable $lock_to_ip,
		bool $read_only
	) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->table_name = $table_name;
		$this->lock_table_name = $lock_table_name;
		$this->lock_timeout = $lock_timeout;
		$this->lock_max_age = $lock_max_age;
		$this->lock_retry_interval = $lock_retry_interval;
		$this->security_code = $security_code;
		$this->lock_to_user_agent = $lock_to_user_agent;
		$this->lock_to_ip = $lock_to_ip;
		$this->read_only = $read_only;
	}

	/**
	 * Check if the session is in read-only mode.
	 *
	 * @return bool True if session is read-only
	 */
	public function is_read_only(): bool {
		return $this->read_only;
	}

	/**
	 * Check if fingerprint protection is enabled.
	 *
	 * @return bool True if any fingerprint option is configured
	 */
	private function fingerprint_enabled(): bool {
		return $this->security_code !== '' || $this->lock_to_user_agent || $this->lock_to_ip !== false;
	}

	/**
	 * Calculate the session fingerprint hash.
	 *
	 * The fingerprint combines:
	 * - User-Agent header (if lock_to_user_agent is true)
	 * - Client IP address (if lock_to_ip is true or callable)
	 * - Security code (always included if set)
	 *
	 * @return string SHA256 hash of the fingerprint components
	 */
	private function calculate_fingerprint(): string {
		$components = '';

		// Add User-Agent if configured
		if ( $this->lock_to_user_agent ) {
			$components .= $_SERVER['HTTP_USER_AGENT'] ?? '';
		}

		// Add IP address if configured
		if ( $this->lock_to_ip !== false ) {
			if ( is_callable( $this->lock_to_ip ) ) {
				// Use callable to get IP (useful for reverse proxies)
				$components .= ( $this->lock_to_ip )();
			} else {
				// Use REMOTE_ADDR directly
				$components .= $_SERVER['REMOTE_ADDR'] ?? '';
			}
		}

		// Always add security code
		$components .= $this->security_code;

		return hash( algo: 'sha256', data: $components );
	}

	private static function is_valid_table_name( string $name ): bool {
		// Table names must be non-empty and contain only alphanumeric characters and underscores
		// This prevents SQL injection when table names are interpolated into queries
		if ( $name === '' ) {
			return false;
		}

		return preg_match( '/^[a-zA-Z0-9_]+$/', $name ) === 1;
	}

	public function create_sid(): string {
		// Generate a cryptographically secure session ID
		// 32 bytes = 64 hex characters, matching PHP's default session ID length
		return bin2hex( random_bytes( 32 ) );
	}

	public function validateId( string $id ): bool {
		// Check if a session with this ID exists in the database
		// Called when session.use_strict_mode is enabled
		// Returns true if session exists, false to generate a new ID
		// Using EXISTS for efficiency - it short-circuits after finding the first row
		$stmt = $this->pdo->prepare(
			"SELECT EXISTS(SELECT 1 FROM `{$this->table_name}` WHERE session_id = :session_id) as found"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':session_id' => $id,
		] );
		if ( $result === false ) {
			return false;
		}

		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( $row === false ) {
			return false;
		}

		return (int) $row['found'] === 1;
	}

	public function updateTimestamp( string $id, string $data ): bool {
		// Update only the timestamp without rewriting session data
		// Called when session.lazy_write is enabled and data hasn't changed
		// This is more efficient than a full write

		// In read-only mode, don't update the timestamp
		if ( $this->read_only ) {
			return true;
		}

		// Check if we need to acquire a lock for this session ID
		if ( $this->session_id !== $id ) {
			// Release old lock if we have one
			$this->release_lock();

			// Acquire lock for this session ID
			$lock_acquired = $this->acquire_lock( $id );
			if ( $lock_acquired === false ) {
				return false;
			}
		}

		$now = time();

		$stmt = $this->pdo->prepare(
			"UPDATE `{$this->table_name}` SET last_accessed = :last_accessed WHERE session_id = :session_id"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':last_accessed' => $now,
			':session_id' => $id,
		] );

		return $result;
	}

	private function acquire_lock( string $session_id ): bool {
		$this->session_id = $session_id;
		$this->lock_token = bin2hex( random_bytes( 32 ) );
		$deadline = time() + $this->lock_timeout;

		// Prepare statements once outside the loop for better performance
		$insert_stmt = $this->pdo->prepare(
			"INSERT INTO `{$this->lock_table_name}` (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)"
		);
		if ( $insert_stmt === false ) {
			return false;
		}

		$update_stmt = $this->pdo->prepare(
			"UPDATE `{$this->lock_table_name}` SET lock_token = :lock_token, locked_at = :locked_at WHERE session_id = :session_id AND locked_at < :stale_threshold"
		);
		if ( $update_stmt === false ) {
			return false;
		}

		$now = time();
		while ( $now < $deadline ) {
			// Try to insert a new lock
			$result = $insert_stmt->execute( [
				':session_id' => $session_id,
				':lock_token' => $this->lock_token,
				':locked_at' => $now,
			] );

			if ( $result === true ) {
				// Lock acquired successfully
				return true;
			}

			// Check if it failed due to duplicate key (MySQL error 1062)
			$error_info = $insert_stmt->errorInfo();
			if ( ! isset( $error_info[1] ) || (int) $error_info[1] !== 1062 ) {
				// Some other error occurred
				return false;
			}

			// Lock exists - check if it's stale
			$result = $update_stmt->execute( [
				':lock_token' => $this->lock_token,
				':locked_at' => $now,
				':session_id' => $session_id,
				':stale_threshold' => $now - $this->lock_max_age,
			] );

			if ( $result === false ) {
				return false;
			}

			if ( $update_stmt->rowCount() === 1 ) {
				// Successfully claimed stale lock
				return true;
			}

			// Lock is held by someone else and not stale, wait and retry
			usleep( $this->lock_retry_interval * 1000 );
			$now = time();
		}

		// Timeout reached
		$this->session_id = null;
		$this->lock_token = '';
		return false;
	}

	private function release_lock(): bool {
		if ( $this->session_id === null || $this->lock_token === '' ) {
			return true;
		}

		$stmt = $this->pdo->prepare(
			"DELETE FROM `{$this->lock_table_name}` WHERE session_id = :session_id AND lock_token = :lock_token"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':session_id' => $this->session_id,
			':lock_token' => $this->lock_token,
		] );

		$this->session_id = null;
		$this->lock_token = '';

		return $result;
	}

	/**
	 * Refresh the current lock's timestamp to prevent it from becoming stale.
	 *
	 * Use this in long-running scripts to extend the lock before it exceeds lock_max_age.
	 * Should be called periodically (e.g., every lock_max_age / 2 seconds) in long operations.
	 *
	 * @return bool True if lock was refreshed, false if no lock held or refresh failed
	 */
	public function refresh_lock(): bool {
		if ( $this->session_id === null || $this->lock_token === '' ) {
			return false;
		}

		$stmt = $this->pdo->prepare(
			"UPDATE `{$this->lock_table_name}` SET locked_at = :locked_at WHERE session_id = :session_id AND lock_token = :lock_token"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':locked_at' => time(),
			':session_id' => $this->session_id,
			':lock_token' => $this->lock_token,
		] );

		if ( $result === false ) {
			return false;
		}

		// Verify the lock was actually updated (it still belongs to us)
		return $stmt->rowCount() === 1;
	}

	public function open( string $path, string $name ): bool {
		return true;
	}

	public function close(): bool {
		return $this->release_lock();
	}

	public function read( string $id ): string|false {
		// In read-only mode, skip lock acquisition entirely
		// This allows concurrent reads without blocking
		if ( $this->read_only === false ) {
			// Only acquire lock if we don't already hold one for this session
			// This handles session_reset() which re-reads the same session
			if ( $this->session_id !== $id ) {
				$lock_acquired = $this->acquire_lock( $id );
				if ( $lock_acquired === false ) {
					return false;
				}
			}
		}

		$stmt = $this->pdo->prepare(
			"SELECT data, fingerprint FROM `{$this->table_name}` WHERE session_id = :session_id"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':session_id' => $id,
		] );
		if ( $result === false ) {
			return false;
		}

		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( $row === false ) {
			// No existing session, return empty string (not an error)
			return '';
		}

		// Validate fingerprint if protection is enabled
		// Note: In read-only mode, we still validate but won't destroy on mismatch
		// since we can't write. Just return empty string.
		if ( $this->fingerprint_enabled() ) {
			$stored_fingerprint = $row['fingerprint'] ?? '';
			$current_fingerprint = $this->calculate_fingerprint();

			// Use constant-time comparison to prevent timing attacks
			if ( $stored_fingerprint === '' || hash_equals( $stored_fingerprint, $current_fingerprint ) === false ) {
				// Fingerprint mismatch - possible session hijacking attempt
				if ( $this->read_only ) {
					// In read-only mode, just return empty string
					return '';
				}
				// Destroy the session and return empty string to create a new one
				$this->destroy( id: $id );
				return '';
			}
		}

		return $row['data'];
	}

	public function write( string $id, string $data ): bool {
		// In read-only mode, don't write any changes
		// Return true to indicate success (from PHP's perspective, the write "worked")
		if ( $this->read_only ) {
			return true;
		}

		// Check if we need to acquire a lock for this session ID
		// This handles session_regenerate_id() which writes to a new ID
		if ( $this->session_id !== $id ) {
			// Release old lock if we have one
			$this->release_lock();

			// Acquire lock for new session ID
			$lock_acquired = $this->acquire_lock( $id );
			if ( $lock_acquired === false ) {
				return false;
			}
		}

		$now = time();
		$fingerprint = $this->fingerprint_enabled() ? $this->calculate_fingerprint() : '';

		$stmt = $this->pdo->prepare(
			"INSERT INTO `{$this->table_name}` (session_id, data, fingerprint, last_accessed) VALUES (:session_id, :data, :fingerprint, :last_accessed) ON DUPLICATE KEY UPDATE data = :data_update, last_accessed = :last_accessed_update"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':session_id' => $id,
			':data' => $data,
			':fingerprint' => $fingerprint,
			':last_accessed' => $now,
			':data_update' => $data,
			':last_accessed_update' => $now,
		] );

		return $result;
	}

	public function destroy( string $id ): bool {
		// Use transaction to ensure atomic deletion of session data and lock
		$in_transaction = $this->pdo->inTransaction();
		if ( $in_transaction === false ) {
			$began = $this->pdo->beginTransaction();
			if ( $began === false ) {
				return false;
			}
		}

		// Delete session data
		$stmt = $this->pdo->prepare(
			"DELETE FROM `{$this->table_name}` WHERE session_id = :session_id"
		);
		if ( $stmt === false ) {
			if ( $in_transaction === false ) {
				$this->pdo->rollBack();
			}
			return false;
		}

		$result = $stmt->execute( [
			':session_id' => $id,
		] );
		if ( $result === false ) {
			if ( $in_transaction === false ) {
				$this->pdo->rollBack();
			}
			return false;
		}

		// Only delete the lock if we own it (have the matching lock_token)
		// This prevents destroying another process's lock, which could cause race conditions
		// If we don't own the lock, leave it - the owner will release it or GC will clean it up
		if ( $this->session_id === $id && $this->lock_token !== '' ) {
			$stmt = $this->pdo->prepare(
				"DELETE FROM `{$this->lock_table_name}` WHERE session_id = :session_id AND lock_token = :lock_token"
			);
			if ( $stmt === false ) {
				if ( $in_transaction === false ) {
					$this->pdo->rollBack();
				}
				return false;
			}

			$result = $stmt->execute( [
				':session_id' => $id,
				':lock_token' => $this->lock_token,
			] );
			if ( $result === false ) {
				if ( $in_transaction === false ) {
					$this->pdo->rollBack();
				}
				return false;
			}

			$this->session_id = null;
			$this->lock_token = '';
		}

		if ( $in_transaction === false ) {
			$this->pdo->commit();
		}

		return true;
	}

	public function gc( int $max_lifetime ): int|false {
		$threshold = time() - $max_lifetime;

		// Delete expired sessions
		$stmt = $this->pdo->prepare(
			"DELETE FROM `{$this->table_name}` WHERE last_accessed < :threshold"
		);
		if ( $stmt === false ) {
			return false;
		}

		$result = $stmt->execute( [
			':threshold' => $threshold,
		] );
		if ( $result === false ) {
			return false;
		}

		$deleted_sessions = $stmt->rowCount();

		// Also clean up stale locks
		$lock_threshold = time() - $this->lock_max_age;
		$stmt = $this->pdo->prepare(
			"DELETE FROM `{$this->lock_table_name}` WHERE locked_at < :threshold"
		);
		if ( $stmt === false ) {
			return $deleted_sessions;
		}

		$stmt->execute( [
			':threshold' => $lock_threshold,
		] );

		return $deleted_sessions;
	}
}
