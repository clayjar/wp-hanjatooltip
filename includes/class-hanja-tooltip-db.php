<?php
/**
 * Dictionary connection that fails closed.
 *
 * @package Hanja_Tooltip
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'wpdb', false ) && ! class_exists( 'Hanja_Tooltip_DB', false ) ) :

/**
 * A normal wpdb constructor calls db_connect( true ), which can load
 * db-error.php or wp_die() when the host is wrong. Callers must return
 * an error instead.
 */
class Hanja_Tooltip_DB extends wpdb {

	/**
	 * Open the dictionary database without aborting the request.
	 *
	 * @param string $dbuser     Database user.
	 * @param string $dbpassword Database password.
	 * @param string $dbname     Database name.
	 * @param string $dbhost     Host, optionally host:port.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		$this->hide_errors();
		$this->dbuser     = $dbuser;
		$this->dbpassword = $dbpassword;
		$this->dbname     = $dbname;
		$this->dbhost     = $dbhost;
		$this->db_connect( false );
	}

	/**
	 * Connect without aborting the request, and keep the server message.
	 *
	 * wpdb::db_connect() drops the handle before it stores a connection error.
	 *
	 * @param bool $allow_bail Unused. A failed connection must return an error instead of dying.
	 * @return bool
	 */
	public function db_connect( $allow_bail = true ) {
		unset( $allow_bail );

		$connected = parent::db_connect( false );
		if ( $connected || '' !== $this->last_error ) {
			return $connected;
		}

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- wpdb does not retain this message.
		$message = mysqli_connect_error();
		if ( is_string( $message ) && '' !== $message ) {
			$this->last_error = $message;
		}

		return false;
	}

	/**
	 * Use utf8mb4 so CJK ideographs round-trip.
	 */
	public function init_charset() {
		parent::init_charset();
		$this->charset = 'utf8mb4';
		$this->collate = 'utf8mb4_bin';
	}
}

endif;
