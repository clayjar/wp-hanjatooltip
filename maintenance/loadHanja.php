<?php
/**
 * Load single-character hanja from a libhangul hanja.txt file.
 *
 * Usage: bash maintenance/loadHanja data/hanja.txt
 *
 * The shell command loads WordPress, then includes this file. Connection
 * settings are DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.
 * A .env file in the plugin root fills any that are unset.
 *
 * @package Hanja_Tooltip
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' === PHP_SAPI ) {
		file_put_contents( 'php://stderr', "Usage: bash maintenance/loadHanja data/hanja.txt\n" );
	}
	exit( 'cli' === PHP_SAPI ? 1 : 0 ); // Exit if accessed directly.
}

if ( 'cli' === PHP_SAPI ) {
	$dictionary = getenv( 'HANJA_LOAD_FILE' );
	if ( is_string( $dictionary ) && '' !== $dictionary ) {
		if ( ! function_exists( 'mb_strlen' ) ) {
			file_put_contents( 'php://stderr', "The mbstring extension is required.\n" );
			exit( 1 );
		}
		$envPath = dirname( __DIR__ ) . '/.env';
		if ( is_readable( $envPath ) ) {
			loadEnv( $envPath );
		}
		if ( ! is_readable( $dictionary ) ) {
			file_put_contents( 'php://stderr', "Usage: bash maintenance/loadHanja data/hanja.txt\n" );
			exit( 1 );
		}
		main( $dictionary );
	}
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
		file_put_contents( 'php://stderr', "Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.\n" );
		exit( 1 );
	}
	if ( !ctype_digit( $port ) ) {
		file_put_contents( 'php://stderr', "DB_PORT must be a number.\n" );
		exit( 1 );
	}

	$rows = loadEntries( $file );
	loadDictionaryClass();

	// Dictionary connection. It may be a different database from wp-config.php.
	$wpdb = new Hanja_Tooltip_DB( $user, $password, $database, dictionaryHost( $host, (int) $port ) );
	if ( ! $wpdb->ready ) {
		databaseError( connectionMessage( $wpdb ) );
	}

	// No placeholders: CREATE, TRUNCATE, and the transaction statements take no input.
	checkStatement(
		$wpdb,
		$wpdb->query(
			'CREATE TABLE IF NOT EXISTS hanja (
				hanja CHAR(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
				definition TEXT NOT NULL,
				PRIMARY KEY (hanja)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
		)
	);
	checkStatement( $wpdb, $wpdb->query( 'TRUNCATE TABLE hanja' ) );
	checkStatement( $wpdb, $wpdb->query( 'START TRANSACTION' ) );

	foreach ( $rows as $char => $text ) {
		$inserted = $wpdb->insert(
			'hanja',
			array(
				'hanja'      => $char,
				'definition' => $text,
			),
			array( '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			databaseError( $wpdb->last_error );
		}
	}

	checkStatement( $wpdb, $wpdb->query( 'COMMIT' ) );
	$wpdb->close();

	echo 'Inserted ' . count( $rows ) . " hanja.\n";
}

/**
 * Load the dictionary connection class after WordPress has defined wpdb.
 */
function loadDictionaryClass(): void {
	if ( ! class_exists( 'wpdb', false ) ) {
		file_put_contents( 'php://stderr', "WordPress did not load wpdb.\n" );
		exit( 1 );
	}

	require_once dirname( __DIR__ ) . '/includes/class-hanja-tooltip-db.php';
	if ( ! class_exists( 'Hanja_Tooltip_DB', false ) ) {
		file_put_contents( 'php://stderr', "Could not load the dictionary database class.\n" );
		exit( 1 );
	}
}

/**
 * Host string wpdb accepts. localhost stays a socket; other hosts gain the port.
 */
function dictionaryHost( string $host, int $port ): string {
	if ( $port <= 0 || hostIncludesPort( $host ) || 'localhost' === strtolower( $host ) ) {
		return $host;
	}
	return $host . ':' . $port;
}

/**
 * Whether a wpdb host string already names a port or a socket.
 */
function hostIncludesPort( string $host ): bool {
	if ( false !== strpos( $host, ':/' ) ) {
		return true;
	}
	if ( isset( $host[0] ) && '[' === $host[0] ) {
		return false !== strpos( $host, ']:' );
	}
	return 1 === substr_count( $host, ':' );
}

/**
 * Stop when a statement failed.
 *
 * @param wpdb  $wpdb   Open dictionary connection.
 * @param mixed $result Return value from wpdb.
 */
function checkStatement( wpdb $wpdb, $result ): void {
	if ( false === $result ) {
		databaseError( $wpdb->last_error );
	}
}

/**
 * Print a database error and stop.
 *
 * @param mixed $message Server message, when one is available.
 */
function databaseError( $message ): void {
	if ( ! is_string( $message ) || '' === $message ) {
		$message = 'unknown database error';
	}
	file_put_contents( 'php://stderr', 'Database error: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Connection failure text without the password.
 *
 * @param wpdb $wpdb Connection that failed.
 */
function connectionMessage( wpdb $wpdb ): string {
	if ( is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
		return $wpdb->last_error;
	}
	if ( is_wp_error( $wpdb->error ) ) {
		return wp_strip_all_tags( $wpdb->error->get_error_message() );
	}
	if ( is_string( $wpdb->error ) && '' !== $wpdb->error ) {
		return wp_strip_all_tags( $wpdb->error );
	}
	return 'Could not connect to the dictionary database.';
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
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( $lines === false ) {
		file_put_contents( 'php://stderr', "Cannot read {$path}.\n" );
		exit( 1 );
	}
	$merged = [];
	foreach ( $lines as $line ) {
		$raw = rtrim( $line, "\r" );
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
	return $merged;
}
