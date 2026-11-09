<?php
/**
 * Option storage. Every read/write of plugin state goes through here.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors over the two options the plugin owns.
 */
class Send4_Settings {

	const CONNECTION_OPTION = 'send4_connection';
	const FORMS_OPTION      = 'send4_forms';
	const STATUS_OPTION     = 'send4_form_status';
	const BASE_URL_OPTION   = 'send4_api_base_url';
	const DEFAULT_BASE_URL  = 'https://app.send4.ai';

	/**
	 * The per-form keys that describe the last delivery rather than configure
	 * the form. They live in STATUS_OPTION, never in FORMS_OPTION (#0696).
	 */
	const STATUS_KEYS = array( 'last_status', 'last_at', 'last_error' );

	/**
	 * Connection settings: client id, secret, and what the last handshake said.
	 *
	 * @return array{client_id:string,client_secret:string,client_name:string,connected_at:string,connect_id:string,connect_secret:string}
	 */
	public static function connection() {
		$stored = get_option( self::CONNECTION_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'client_name'    => '',
				'connected_at'   => '',
				// Only live between starting a connection and redeeming it.
				'connect_id'     => '',
				'connect_secret' => '',
			)
		);
	}

	/**
	 * Persist connection settings, merging over what is stored.
	 *
	 * @param array $values Partial connection settings.
	 * @return void
	 */
	public static function update_connection( array $values ) {
		update_option( self::CONNECTION_OPTION, array_merge( self::connection(), $values ), false );
	}

	/**
	 * Forget the credentials and everything derived from them.
	 *
	 * @return void
	 */
	public static function disconnect() {
		delete_option( self::CONNECTION_OPTION );
		Send4_Api_Client::forget_token();
		Send4_Minimum_Version::forget();
	}

	/**
	 * Could this be a Send4 API client secret?
	 *
	 * Send4 issues `secret_` and 48 letters and digits. The check is a little
	 * wider than that, letters, digits, "_" and "-", 16 to 255 of them, so a
	 * future Send4 key never locks out a site still running this version.
	 * What it never admits is whitespace, markup or anything else
	 * sanitize_text_field() would change.
	 *
	 * @param mixed $secret Candidate secret.
	 * @return bool
	 */
	public static function is_client_secret( $secret ) {
		return is_string( $secret ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{16,255}\z/', $secret );
	}

	/**
	 * True when both halves of the credential are present.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		$connection = self::connection();

		return '' !== $connection['client_id'] && '' !== $connection['client_secret'];
	}

	/**
	 * The API root, without a trailing slash.
	 *
	 * Constant wins over option so a container can pin it without touching the
	 * database, and the settings screen shows (read-only) where it points.
	 *
	 * @return string
	 */
	public static function base_url() {
		if ( defined( 'SEND4_API_BASE_URL' ) && '' !== SEND4_API_BASE_URL ) {
			return untrailingslashit( SEND4_API_BASE_URL );
		}

		$option = get_option( self::BASE_URL_OPTION, '' );

		if ( is_string( $option ) && '' !== $option ) {
			return untrailingslashit( $option );
		}

		return self::DEFAULT_BASE_URL;
	}

	/**
	 * True when the base URL is pinned by a constant and must not be edited.
	 *
	 * @return bool
	 */
	public static function base_url_is_locked() {
		return defined( 'SEND4_API_BASE_URL' ) && '' !== SEND4_API_BASE_URL;
	}

	/**
	 * Is the configured base URL somewhere we are willing to send a bearer token?
	 *
	 * The submission POST is made by the server, on an anonymous visitor's
	 * request, carrying a credential. A writable destination would therefore be
	 * an exfiltration and SSRF primitive for any other plugin's option-write
	 * bug, so the destination is allow-listed rather than merely validated.
	 *
	 * @param string|null $url URL to check, defaults to the configured one.
	 * @return bool
	 */
	public static function base_url_is_allowed( $url = null ) {
		$url   = null === $url ? self::base_url() : (string) $url;
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return self::is_send4_host( $host );
		}

		// Plain HTTP is for development only, where there is no TLS to verify.
		return 'http' === $scheme && self::is_development_host( $host );
	}

	/**
	 * Is this somewhere we are willing to send the administrator's browser?
	 *
	 * The Connect button ends in a redirect to whatever authorize_url the API
	 * answered with. No credential travels with the browser, so the concern is
	 * not exfiltration but where the administrator lands: a redirect to an
	 * arbitrary host is a phishing primitive, and the API is trusted with the
	 * submissions, not with the administrator's browser. So the destination
	 * is checked the way the API destination is, rather than taken on faith.
	 *
	 * Broader than base_url_is_allowed() on purpose. A development Laravel app
	 * is reached by the container over plain HTTP, but sends the browser to its
	 * own https://*.local address, and that has to keep working. Userinfo is
	 * refused outright: "https://app.send4.ai@evil.com" parses one way in PHP
	 * and another in a browser, and a check that reads it differently from
	 * the thing that follows it is not a check.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function authorize_url_is_allowed( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return self::is_send4_host( $host ) || self::is_development_host( $host );
		}

		return 'http' === $scheme && self::is_development_host( $host );
	}

	/**
	 * Either send4.ai itself or one of its subdomains. The match is on a label
	 * boundary, so notsend4.ai and send4.ai.evil.com both fail.
	 *
	 * @param string $host Lower-cased hostname.
	 * @return bool
	 */
	private static function is_send4_host( $host ) {
		return 'send4.ai' === $host || self::host_ends_with( $host, '.send4.ai' );
	}

	/**
	 * A developer's own machine, as a browser or a container would name it.
	 *
	 * @param string $host Lower-cased hostname.
	 * @return bool
	 */
	private static function is_development_host( $host ) {
		$local = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );

		return in_array( $host, $local, true ) || self::host_ends_with( $host, '.local' );
	}

	/**
	 * Suffix match on a hostname.
	 *
	 * @param string $host   Hostname.
	 * @param string $suffix Suffix including the leading dot.
	 * @return bool
	 */
	private static function host_ends_with( $host, $suffix ) {
		$length = strlen( $suffix );

		return strlen( $host ) > $length && substr( $host, -$length ) === $suffix;
	}

	/**
	 * Per-form configuration, keyed by form key.
	 *
	 * Configuration only: the last delivery's outcome is in form_statuses().
	 * An entry written before #0696 may still carry last_* keys until the next
	 * admin save moves them out; nothing should read them from here.
	 *
	 * @return array<string,array>
	 */
	public static function forms() {
		$stored = get_option( self::FORMS_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * True when this form has configuration stored, detected or not.
	 *
	 * @param string $form_key Form key.
	 * @return bool
	 */
	public static function has_form( $form_key ) {
		$forms = self::forms();

		return isset( $forms[ $form_key ] );
	}

	/**
	 * One form's state, with defaults filled in.
	 *
	 * @param string $form_key Form key.
	 * @return array{enabled:bool,mapping:array,name:string,last_status:string,last_at:string,last_error:string,validate_phone:bool,phone_field:array{picker:bool,default_country:string}}
	 */
	public static function form( $form_key ) {
		$forms    = self::forms();
		$stored   = isset( $forms[ $form_key ] ) && is_array( $forms[ $form_key ] ) ? $forms[ $form_key ] : array();
		$statuses = self::form_statuses();

		// Merged in for the screens that show both side by side. update_form()
		// strips it out again, so reading a form and saving it back can never
		// carry a delivery status into the configuration option.
		$form = wp_parse_args(
			array_merge(
				self::without_status( $stored ),
				isset( $statuses[ $form_key ] ) ? $statuses[ $form_key ] : array()
			),
			array(
				'enabled'        => false,
				'mapping'        => array(),
				'name'           => '',
				'last_status'    => '',
				'last_at'        => '',
				'last_error'     => '',
				// Off by default, deliberately (#0688): a false rejection loses
				// a lead outright, which is worse than a badly typed number.
				'validate_phone' => false,
				'phone_field'    => array(),
			)
		);

		$form['mapping'] = wp_parse_args(
			is_array( $form['mapping'] ) ? $form['mapping'] : array(),
			array(
				'phone' => '',
				'name'  => '',
				'email' => '',
			)
		);

		$form['validate_phone'] = ! empty( $form['validate_phone'] );

		// Nested the way mapping is, so the whole front-end half of a form's
		// settings travels together.
		$phone_field = wp_parse_args(
			is_array( $form['phone_field'] ) ? $form['phone_field'] : array(),
			array(
				'picker'          => false,
				'default_country' => '',
			)
		);

		$form['phone_field'] = array(
			'picker'          => ! empty( $phone_field['picker'] ),
			'default_country' => is_string( $phone_field['default_country'] ) ? $phone_field['default_country'] : '',
		);

		return $form;
	}

	/**
	 * Merge values into one form's configuration.
	 *
	 * Only admin actions (the settings screens and the CLI) call this. The
	 * delivery path must not: it rewrites the whole option from whatever copy
	 * it read, and a delivery holds that copy across a blocking HTTP call — a
	 * queue drain across many — so writing from it would silently revert a form
	 * an admin disabled, or a mapping they saved, in the meantime (#0696).
	 *
	 * @param string $form_key Form key.
	 * @param array  $values   Partial form configuration. Status keys are ignored.
	 * @return void
	 */
	public static function update_form( $form_key, array $values ) {
		$entry = self::without_status( array_merge( self::form( $form_key ), $values ) );
		$forms = self::move_legacy_statuses( self::forms() );

		$forms[ $form_key ] = $entry;

		update_option( self::FORMS_OPTION, $forms, false );

		// The forms report that follows a save is built from this request's
		// detected forms, which include a form past the listing ceiling only
		// once it is set up. It just was.
		Send4_Adapters::form_was_configured( $form_key );
	}

	/**
	 * The last delivery outcome per form, keyed by form key.
	 *
	 * Falls back to the last_* keys a pre-#0696 install kept inside each form's
	 * configuration, for as long as they are still there, so the screens do
	 * not go blank between upgrading and the next admin save.
	 *
	 * @return array<string,array{last_status:string,last_at:string,last_error:string}>
	 */
	public static function form_statuses() {
		$statuses = self::normalise_statuses( get_option( self::STATUS_OPTION, array() ) );

		foreach ( self::forms() as $form_key => $stored ) {
			$legacy = self::legacy_status( $stored );

			if ( null !== $legacy && ! isset( $statuses[ $form_key ] ) ) {
				$statuses[ $form_key ] = $legacy;
			}
		}

		return $statuses;
	}

	/**
	 * True when the form is enabled AND has the phone mapping the API requires.
	 *
	 * @param string $form_key Form key.
	 * @return bool
	 */
	public static function form_is_active( $form_key ) {
		$form = self::form( $form_key );

		return ! empty( $form['enabled'] ) && '' !== $form['mapping']['phone'];
	}

	/**
	 * True when this form's mapped phone field must hold a textable number
	 * before the form plugin accepts the submission (#0688).
	 *
	 * The one predicate every validation binding goes through, so "a form that
	 * is off never validates" and "a form with no phone mapping never
	 * validates" hold by construction rather than by each binding remembering.
	 *
	 * @param string $form_key Form key.
	 * @return bool
	 */
	public static function form_validates_phone( $form_key ) {
		return self::form_is_active( $form_key ) && self::form( $form_key )['validate_phone'];
	}

	/**
	 * True when the country picker should be attached to this form's phone
	 * field on the front end.
	 *
	 * @param string $form_key Form key.
	 * @return bool
	 */
	public static function form_uses_phone_picker( $form_key ) {
		return self::form_is_active( $form_key ) && self::form( $form_key )['phone_field']['picker'];
	}

	/**
	 * Record the outcome of the most recent delivery attempt for a form.
	 *
	 * @param string $form_key Form key.
	 * @param string $status   One of delivered|duplicate|unrouted|queued|failed.
	 * @param string $error    Human readable error, empty on success.
	 * @return void
	 */
	public static function record_delivery( $form_key, $status, $error = '' ) {
		// Its own option, re-read from the database right here: the worst a
		// concurrent delivery can do is overwrite this status line, and it
		// never touches a form's configuration (#0696).
		$statuses = self::fresh_statuses();

		$statuses[ (string) $form_key ] = array(
			'last_status' => (string) $status,
			'last_at'     => gmdate( 'Y-m-d H:i:s' ),
			'last_error'  => (string) $error,
		);

		update_option( self::STATUS_OPTION, $statuses, false );

		// Every outcome in Send4_Delivery funnels through here, so this is the
		// one place the dashboard's counters need to be fed from.
		Send4_Stats::record( $status, (string) $form_key );
	}

	/**
	 * The status option as it is in the database now, not as this request
	 * first saw it.
	 *
	 * The option is not autoloaded, so it is cached under its own key — or,
	 * when it did not exist yet, as an entry in `notoptions`, which would make
	 * get_option() answer "missing" without asking the database. Both are
	 * dropped so a long-running request (a queue drain) merges into what other
	 * requests have written since, rather than its own stale copy.
	 *
	 * @return array<string,array{last_status:string,last_at:string,last_error:string}>
	 */
	private static function fresh_statuses() {
		wp_cache_delete( self::STATUS_OPTION, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ self::STATUS_OPTION ] ) ) {
			unset( $notoptions[ self::STATUS_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return self::normalise_statuses( get_option( self::STATUS_OPTION, array() ) );
	}

	/**
	 * Keep only well-formed status entries.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array<string,array{last_status:string,last_at:string,last_error:string}>
	 */
	private static function normalise_statuses( $stored ) {
		$statuses = array();

		if ( ! is_array( $stored ) ) {
			return $statuses;
		}

		foreach ( $stored as $form_key => $status ) {
			if ( ! is_array( $status ) ) {
				continue;
			}

			$statuses[ (string) $form_key ] = array(
				'last_status' => isset( $status['last_status'] ) ? (string) $status['last_status'] : '',
				'last_at'     => isset( $status['last_at'] ) ? (string) $status['last_at'] : '',
				'last_error'  => isset( $status['last_error'] ) ? (string) $status['last_error'] : '',
			);
		}

		return $statuses;
	}

	/**
	 * A pre-#0696 status stored inside a form's configuration, if there is one.
	 *
	 * @param mixed $stored One form's stored configuration.
	 * @return array{last_status:string,last_at:string,last_error:string}|null
	 */
	private static function legacy_status( $stored ) {
		if ( ! is_array( $stored ) || empty( $stored['last_status'] ) ) {
			return null;
		}

		return array(
			'last_status' => (string) $stored['last_status'],
			'last_at'     => isset( $stored['last_at'] ) ? (string) $stored['last_at'] : '',
			'last_error'  => isset( $stored['last_error'] ) ? (string) $stored['last_error'] : '',
		);
	}

	/**
	 * Move every pre-#0696 status out of the forms option, into its own.
	 *
	 * Runs on an admin save, the one moment the forms option is rewritten
	 * anyway. A status already recorded in the new option is newer than the
	 * legacy one and wins; the legacy copy is dropped either way, so a stale
	 * outcome cannot resurface later.
	 *
	 * @param array<string,mixed> $forms The forms option.
	 * @return array<string,mixed> The forms option without any status keys.
	 */
	private static function move_legacy_statuses( array $forms ) {
		$moved = array();

		foreach ( $forms as $form_key => $stored ) {
			if ( ! is_array( $stored ) ) {
				continue;
			}

			$legacy = self::legacy_status( $stored );

			if ( null !== $legacy ) {
				$moved[ (string) $form_key ] = $legacy;
			}

			$forms[ $form_key ] = self::without_status( $stored );
		}

		if ( array() !== $moved ) {
			$statuses = self::fresh_statuses();

			update_option( self::STATUS_OPTION, array_merge( $moved, $statuses ), false );
		}

		return $forms;
	}

	/**
	 * One form's state without the delivery status keys.
	 *
	 * @param array $form Form state.
	 * @return array
	 */
	private static function without_status( array $form ) {
		return array_diff_key( $form, array_flip( self::STATUS_KEYS ) );
	}
}
