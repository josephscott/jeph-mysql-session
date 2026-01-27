<?php
declare( strict_types = 1 );

namespace JEPH\MySQL;

class Session implements \SessionHandlerInterface {
	private \PDO $pdo;

	private string $table_name;

	private string $lock_table_name;

	private ?string $session_id = null;

	private string $lock_token = '';

	private int $lock_timeout;

	private int $lock_max_age;

	private int $lock_retry_interval;

	public function __construct(
		\PDO $pdo,
		string $table_name = 'sessions',
		string $lock_table_name = 'session_locks',
		int $lock_timeout = 10,
		int $lock_max_age = 30,
		int $lock_retry_interval = 100
	) {
		$this->pdo = $pdo;
		$this->table_name = $table_name;
		$this->lock_table_name = $lock_table_name;
		$this->lock_timeout = $lock_timeout;
		$this->lock_max_age = $lock_max_age;
		$this->lock_retry_interval = $lock_retry_interval;
	}

	private function acquire_lock( string $session_id ): bool {
		$this->session_id = $session_id;
		$this->lock_token = bin2hex( random_bytes( 32 ) );
		$now = time();
		$deadline = $now + $this->lock_timeout;

		while ( time() < $deadline ) {
			// Try to insert a new lock
			try {
				$stmt = $this->pdo->prepare(
					"INSERT INTO {$this->lock_table_name} (session_id, lock_token, locked_at) VALUES (:session_id, :lock_token, :locked_at)"
				);
				if ( $stmt === false ) {
					return false;
				}

				$result = $stmt->execute( [
					':session_id' => $session_id,
					':lock_token' => $this->lock_token,
					':locked_at' => $now,
				] );

				if ( $result === true ) {
					// Lock acquired successfully
					return true;
				}
			} catch ( \PDOException $e ) {
				// Check if it failed due to duplicate key (MySQL error 1062)
				if ( isset( $e->errorInfo[1] ) && $e->errorInfo[1] === 1062 ) {
					// Lock exists, fall through to stale check below
				} else {
					// Some other error occurred
					return false;
				}
			}

			// Lock exists - check if it's stale
			$stale_threshold = time() - $this->lock_max_age;
			$stmt = $this->pdo->prepare(
				"UPDATE {$this->lock_table_name} SET lock_token = :lock_token, locked_at = :locked_at WHERE session_id = :session_id AND locked_at < :stale_threshold"
			);
			if ( $stmt === false ) {
				return false;
			}

			$current_time = time();
			$result = $stmt->execute( [
				':lock_token' => $this->lock_token,
				':locked_at' => $current_time,
				':session_id' => $session_id,
				':stale_threshold' => $stale_threshold,
			] );

			if ( $result === false ) {
				return false;
			}

			if ( $stmt->rowCount() === 1 ) {
				// Successfully claimed stale lock
				return true;
			}

			// Lock is held by someone else and not stale, wait and retry
			usleep( $this->lock_retry_interval * 1000 );
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
			"DELETE FROM {$this->lock_table_name} WHERE session_id = :session_id AND lock_token = :lock_token"
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

	public function open( string $path, string $name ): bool {
		return true;
	}

	public function close(): bool {
		return true;
	}

	public function read( string $id ): string|false {
		return '';
	}

	public function write( string $id, string $data ): bool {
		return true;
	}

	public function destroy( string $id ): bool {
		return true;
	}

	public function gc( int $max_lifetime ): int|false {
		return 0;
	}
}
