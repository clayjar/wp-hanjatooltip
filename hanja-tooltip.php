<?php
/**
 * Plugin Name:       Hanja Tooltip
 * Description:       Tags Han ideographs and shows a dictionary tooltip on click or tap.
 * Version:           1.0.0
 * Author:            Michael Han
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hanja-tooltip
 * Requires at least: 6.5
 * Requires PHP:      7.4
 *
 * @package Hanja_Tooltip
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Han ideographs in post content and serves dictionary lookups.
 */
class Hanja_Tooltip {

	const VERSION = '1.0.0';

	/**
	 * Register content, asset, and REST hooks.
	 */
	public function __construct() {
		$this->register_bundled_translations();

		add_filter( 'the_content', array( $this, 'wrap_hanja_characters' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_api_route' ) );
		add_filter( 'rest_send_nocache_headers', array( $this, 'allow_public_cache' ) );
	}

	/**
	 * Wrap each Han ideograph that sits outside protected regions.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function wrap_hanja_characters( $content ) {
		if ( is_admin() || ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		return self::tag_html( $content );
	}

	/**
	 * Return HTML with one span per Han ideograph outside protected regions.
	 *
	 * @param string $text HTML.
	 * @return string
	 */
	public static function tag_html( $text ) {
		$slots     = array();
		$protected = self::shield( $text, $slots );
		if ( null === $protected ) {
			return $text;
		}

		$tagged = preg_replace_callback(
			'/(<[^>]+>)|(\p{Han})/u',
			static function ( array $matches ) {
				if ( '' !== $matches[1] ) {
					return $matches[1];
				}
				$char = htmlspecialchars( $matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				return '<span class="hanja-word" data-char="' . $char . '">' . $char . '</span>';
			},
			$protected
		);
		if ( null === $tagged ) {
			return $text;
		}

		$tokens = array_keys( $slots );
		for ( $i = count( $tokens ) - 1; $i >= 0; $i-- ) {
			$token  = $tokens[ $i ];
			$tagged = str_replace( $token, $slots[ $token ], $tagged );
		}
		return $tagged;
	}

	/**
	 * Replace protected regions with placeholders.
	 *
	 * @param string               $text  HTML.
	 * @param array<string,string> $slots Placeholder map, updated in place.
	 * @return string|null Null when a pattern fails.
	 */
	private static function shield( $text, array &$slots ) {
		$patterns = array(
			'/<script\b[^>]*>.*?<\/script>/isu',
			'/<style\b[^>]*>.*?<\/style>/isu',
			'/<textarea\b[^>]*>.*?<\/textarea>/isu',
			'/<a\b[^>]*>.*?<\/a>/isu',
			'/<span\b[^>]*\bclass=(["\'])[^"\']*\bhanja-word\b[^"\']*\1[^>]*>.*?<\/span>/isu',
		);
		foreach ( $patterns as $pattern ) {
			$text = preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( &$slots ) {
					$token           = "\x01HANJA" . count( $slots ) . "\x01";
					$slots[ $token ] = $matches[0];
					return $token;
				},
				$text
			);
			if ( null === $text ) {
				return null;
			}
		}
		return $text;
	}

	/**
	 * Load the tooltip stylesheet and script.
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'hanja-tooltip',
			plugins_url( 'assets/hanja-tooltip.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'hanja-tooltip',
			plugins_url( 'assets/hanja-tooltip.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			'hanja-tooltip',
			'HanjaTooltipConfig',
			array(
				'apiUrl'   => esc_url_raw( rest_url( 'hanja/v1/lookup' ) ),
				'notFound' => __( 'Not found', 'hanja-tooltip' ),
			)
		);
	}

	/**
	 * Register GET /wp-json/hanja/v1/lookup?char=韓.
	 */
	public function register_api_route() {
		register_rest_route(
			'hanja/v1',
			'/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_api_lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'char' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_char' ),
					),
				),
			)
		);
	}

	/**
	 * Accept exactly one Unicode character.
	 *
	 * @param mixed $value Raw parameter.
	 * @return true|WP_Error
	 */
	public function validate_char( $value ) {
		if ( self::is_single_character( $value ) ) {
			return true;
		}

		return new WP_Error(
			'hanja_tooltip_badchar',
			__( 'The char parameter must be exactly one character.', 'hanja-tooltip' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Look up one character in the hanja table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_api_lookup( WP_REST_Request $request ) {
		$char = (string) $request->get_param( 'char' );
		if ( ! self::is_single_character( $char ) ) {
			return $this->validate_char( $char );
		}

		$config = $this->dictionary_config();
		if ( null === $config ) {
			return new WP_Error(
				'hanja_tooltip_config',
				__( 'The hanja dictionary database is not configured.', 'hanja-tooltip' ),
				array( 'status' => 500 )
			);
		}

		if ( ! class_exists( 'Hanja_Tooltip_DB', false ) ) {
			return $this->db_unavailable();
		}

		$db = new Hanja_Tooltip_DB( $config['user'], $config['password'], $config['name'], $config['host'] );
		try {
			if ( ! $db->ready ) {
				$this->log_db_failure( $db );
				return $this->db_unavailable();
			}

			$row = $db->get_row(
				$db->prepare( 'SELECT definition FROM hanja WHERE hanja = %s LIMIT 1', $char ),
				ARRAY_A
			);
			if ( ! $db->ready || '' !== $db->last_error ) {
				$this->log_db_failure( $db );
				return $this->db_unavailable();
			}
		} finally {
			$db->close();
		}

		if ( is_array( $row ) && array_key_exists( 'definition', $row ) ) {
			return $this->lookup_response( $char, (string) $row['definition'], true );
		}

		return $this->lookup_response( $char, '', false );
	}

	/**
	 * Keep the public dictionary response cacheable for logged-in readers too.
	 *
	 * @param bool $send Whether WordPress wants to send no-cache headers.
	 * @return bool
	 */
	public function allow_public_cache( $send ) {
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		if ( function_exists( 'untrailingslashit' ) ) {
			$route = untrailingslashit( $route );
		}
		if ( '/hanja/v1/lookup' === $route ) {
			return false;
		}
		return $send;
	}

	/**
	 * Connection settings from HANJA_DB_* or the WordPress database constants.
	 *
	 * @return array{host:string,user:string,password:string,name:string}|null
	 */
	private function dictionary_config() {
		$name         = defined( 'HANJA_DB_NAME' ) ? (string) HANJA_DB_NAME : '';
		$user         = defined( 'HANJA_DB_USER' ) ? (string) HANJA_DB_USER : '';
		$password_set = defined( 'HANJA_DB_PASSWORD' );
		$password     = $password_set ? (string) HANJA_DB_PASSWORD : '';
		$host_set     = defined( 'HANJA_DB_HOST' ) && '' !== (string) HANJA_DB_HOST;
		$host         = $host_set ? (string) HANJA_DB_HOST : '127.0.0.1';
		$port_set     = defined( 'HANJA_DB_PORT' );
		$port         = $port_set ? (int) HANJA_DB_PORT : 3306;

		if ( '' === $name || '' === $user ) {
			if ( '' === $name && defined( 'DB_NAME' ) ) {
				$name = (string) DB_NAME;
			}
			if ( '' === $user && defined( 'DB_USER' ) ) {
				$user = (string) DB_USER;
			}
			if ( ! $password_set && defined( 'DB_PASSWORD' ) ) {
				$password = (string) DB_PASSWORD;
			}
			if ( ! $host_set && defined( 'DB_HOST' ) ) {
				$host = (string) DB_HOST;
			}
			if ( ! $host_set && ! $port_set ) {
				$port = 0;
			}
		}

		if ( '' === $name || '' === $user || '' === $host ) {
			return null;
		}

		if ( $port > 0 && ! self::host_includes_port( $host ) ) {
			$host .= ':' . $port;
		}

		return array(
			'host'     => $host,
			'user'     => $user,
			'password' => $password,
			'name'     => $name,
		);
	}

	/**
	 * Whether a wpdb host string already names a port or a socket.
	 *
	 * @param string $host Host from configuration.
	 * @return bool
	 */
	private static function host_includes_port( $host ) {
		if ( false !== strpos( $host, ':/' ) ) {
			return true;
		}
		if ( isset( $host[0] ) && '[' === $host[0] ) {
			return false !== strpos( $host, ']:' );
		}
		return 1 === substr_count( $host, ':' );
	}

	/**
	 * Whether the value is one Unicode character.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private static function is_single_character( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^.$/u', $value );
	}

	/**
	 * JSON body for a completed lookup.
	 *
	 * @param string $char       Character that was requested.
	 * @param string $definition Dictionary text, or empty when missing.
	 * @param bool   $found      Whether a row existed.
	 * @return WP_REST_Response
	 */
	private function lookup_response( $char, $definition, $found ) {
		$response = new WP_REST_Response(
			array(
				'char'       => $char,
				'definition' => $definition,
				'found'      => $found,
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * Generic failure for a connection or query error.
	 *
	 * @return WP_Error
	 */
	private function db_unavailable() {
		return new WP_Error(
			'hanja_tooltip_dberror',
			__( 'The hanja dictionary is unavailable.', 'hanja-tooltip' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Record a database failure without the password.
	 *
	 * Query failures are already written by wpdb. Connection failures are not,
	 * because db_connect( false ) returns without logging.
	 *
	 * @param wpdb $db Connection that failed.
	 */
	private function log_db_failure( $db ) {
		$detail = is_string( $db->last_error ) ? $db->last_error : '';
		if ( '' === $detail ) {
			$detail         = 'dictionary database is unavailable';
			$db->last_error = $detail;
		}

		if ( $db->ready ) {
			return;
		}

		if ( ! is_string( $db->last_query ) || '' === $db->last_query ) {
			$db->last_query = 'connect';
		}
		$db->print_error( $detail );
	}

	/**
	 * Let WordPress find translations shipped with the plugin.
	 *
	 * Language packs in wp-content/languages/plugins are loaded automatically.
	 * The custom path is the fallback for the bundled languages directory.
	 */
	private function register_bundled_translations() {
		if ( ! isset( $GLOBALS['wp_textdomain_registry'] ) || ! is_object( $GLOBALS['wp_textdomain_registry'] ) ) {
			return;
		}
		if ( ! method_exists( $GLOBALS['wp_textdomain_registry'], 'set_custom_path' ) ) {
			return;
		}
		$GLOBALS['wp_textdomain_registry']->set_custom_path( 'hanja-tooltip', dirname( __FILE__ ) . '/languages' );
	}
}

if ( class_exists( 'wpdb', false ) ) :

/**
 * Dictionary connection that fails closed.
 *
 * A normal wpdb constructor calls db_connect( true ), which can load
 * db-error.php or wp_die() when the host is wrong. Lookups must return
 * a REST error instead.
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
	 * @param bool $allow_bail Unused. A failed lookup must return an error instead of dying.
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

new Hanja_Tooltip();
