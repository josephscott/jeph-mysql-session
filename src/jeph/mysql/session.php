<?php
declare( strict_types = 1 );

namespace JEPH\MySQL;

class Session implements \SessionHandlerInterface {
	private \mysqli $mysqli;
	private string $table_name;
	private string $lock_table_name;
	private ?string $session_id = null;

	public function __construct( \mysqli $mysqli, string $table_name = 'sessions', string $lock_table_name = 'session_locks' ) {
		$this->mysqli = $mysqli;
		$this->table_name = $table_name;
		$this->lock_table_name = $lock_table_name;
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
