#!/usr/bin/env php
<?php

/**
 * Load single-character hanja from a libhangul hanja.txt file.
 *
 * Usage: php maintenance/loadHanja.php data/hanja.txt
 *
 * Connection settings are DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and
 * DB_PASSWORD. A .env file in the plugin root fills any that are unset.
 */

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	if ( !function_exists( 'mb_strlen' ) ) {
		fwrite( STDERR, "The mbstring extension is required.\n" );
		exit( 1 );
	}
	$envPath = dirname( __DIR__ ) . '/.env';
	if ( is_readable( $envPath ) ) {
		loadEnv( $envPath );
	}
	$file = $argv[1] ?? '';
	if ( $file === '' || !is_readable( $file ) ) {
		fwrite( STDERR, "Usage: php maintenance/loadHanja.php data/hanja.txt\n" );
		exit( 1 );
	}
	main( $file );
}

/**
 * Create the table, replace its rows, and print how many were inserted.
 */
function main( string $file ): void {
	$host = envValue( 'DB_HOST' );
	$port = envValue( 'DB_PORT' );
	$database = envValue( 'DB_DATABASE' );
	$user = envValue( 'DB_USERNAME' );
	$password = getenv( 'DB_PASSWORD' );
	if ( $host === null || $port === null || $database === null || $user === null || $password === false ) {
		fwrite( STDERR, "Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.\n" );
		exit( 1 );
	}
	if ( !ctype_digit( $port ) ) {
		fwrite( STDERR, "DB_PORT must be a number.\n" );
		exit( 1 );
	}

	$rows = loadEntries( $file );
	$previous = mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	try {
		$db = new mysqli( $host, $user, $password, $database, (int)$port );
		$db->set_charset( 'utf8mb4' );
		$db->query(
			'CREATE TABLE IF NOT EXISTS hanja (
				hanja CHAR(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
				definition TEXT NOT NULL,
				PRIMARY KEY (hanja)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
		);
		$db->query( 'TRUNCATE TABLE hanja' );
		$db->begin_transaction();
		try {
			$stmt = $db->prepare( 'INSERT INTO hanja (hanja, definition) VALUES (?, ?)' );
			$char = '';
			$text = '';
			$stmt->bind_param( 'ss', $char, $text );
			foreach ( $rows as $key => $value ) {
				$char = $key;
				$text = $value;
				$stmt->execute();
			}
			$db->commit();
			$stmt->close();
		} catch ( mysqli_sql_exception $e ) {
			$db->rollback();
			throw $e;
		}
		$db->close();
	} catch ( mysqli_sql_exception $e ) {
		fwrite( STDERR, "Database error: " . $e->getMessage() . "\n" );
		exit( 1 );
	} finally {
		mysqli_report( $previous );
	}

	echo 'Inserted ' . count( $rows ) . " hanja.\n";
}

/**
 * Fill unset environment variables from a KEY=VALUE file.
 */
function loadEnv( string $path ): void {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( $lines === false ) {
		return;
	}
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line === '' || str_starts_with( $line, '#' ) || !str_contains( $line, '=' ) ) {
			continue;
		}
		[ $key, $value ] = explode( '=', $line, 2 );
		$key = trim( $key );
		$value = trim( $value );
		$length = strlen( $value );
		if ( $length >= 2 ) {
			$quote = $value[0];
			if ( ( $quote === '"' || $quote === "'" ) && $value[$length - 1] === $quote ) {
				$value = substr( $value, 1, -1 );
			}
		}
		if ( getenv( $key ) === false ) {
			putenv( $key . '=' . $value );
		}
	}
}

/**
 * Return a non-empty environment value, or null when it is missing or blank.
 */
function envValue( string $key ): ?string {
	$value = getenv( $key );
	if ( $value === false || $value === '' ) {
		return null;
	}
	return $value;
}

/**
 * Return hanja => definition rows, one per character, in file order.
 *
 * @return array<string,string>
 */
function loadEntries( string $path ): array {
	$handle = fopen( $path, 'rb' );
	if ( $handle === false ) {
		fwrite( STDERR, "Cannot read {$path}.\n" );
		exit( 1 );
	}
	$merged = [];
	while ( ( $line = fgets( $handle ) ) !== false ) {
		$raw = rtrim( $line, "\r\n" );
		if ( trim( $raw ) === '' || str_starts_with( ltrim( $raw ), '#' ) ) {
			continue;
		}
		$parts = explode( ':', $raw, 3 );
		if ( count( $parts ) < 3 ) {
			continue;
		}
		[ $entry, $hanja, $definition ] = $parts;
		if ( mb_strlen( $hanja, 'UTF-8' ) !== 1 ) {
			continue;
		}
		$text = trim( $definition ) === '' ? $entry : $definition;
		if ( isset( $merged[$hanja] ) ) {
			$merged[$hanja] .= ' / ' . $text;
		} else {
			$merged[$hanja] = $text;
		}
	}
	fclose( $handle );
	return $merged;
}
