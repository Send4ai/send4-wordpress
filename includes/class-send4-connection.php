<?php
/**
 * The site's connection to Send4, as every part of the plugin sees it.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site is connected, what the last handshake said, and the one
 * way a pair of credentials is put in place.
 *
 * All of it lived in Send4_Admin, the wp-admin screens' controller, which the
 * plugin only builds inside wp-admin. WP-CLI, the Site Health tests and the
 * dashboard's data needed it as well, and reached into that class for it. It
 * is state, not a screen, so it has a class of its own and the controller is
 * left with pages and form handlers.
 */
class Send4_Connection {

	/**
	 * The transient the last handshake result is cached in, and for how long
	 * when it succeeded.
	 */
	const HANDSHAKE_CACHE   = 'send4_handshake';
	const HANDSHAKE_CACHE_S = 300;

	/**
	 * How long a failed handshake is remembered.
	 *
	 * Much shorter than a success, so a recovered API shows up within a minute,
	 * but long enough that clicking between screens during an outage does not
	 * wait on the network once per click.
	 */
	const HANDSHAKE_FAILURE_S = 60;

	/**
	 * The key a remembered failure is stored under inside the handshake cache.
	 * No handshake answer carries it, so the two cannot be mistaken for each
	 * other.
	 */
	const HANDSHAKE_FAILURE_KEY = 'send4_failure';

	/**
	 * Handshake result, cached briefly so opening a screen is cheap.
	 *
	 * Failures are cached too, for less time. Without that, every screen asked
	 * again during an outage and waited out the timeout before it could draw —
	 * and a page drawn from the cache is only fast if the cache ever holds
	 * something.
	 *
	 * An unforced call is a screen being drawn, so it waits less than an
	 * explicit Test connection does.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public static function handshake( $force = false ) {
		if ( ! $force ) {
			$cached = self::cached_handshake();

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$client = new Send4_Api_Client( $force ? Send4_Api_Client::TIMEOUT_INTERACTIVE : Send4_Api_Client::TIMEOUT_SCREEN );
		$result = $client->handshake();

		self::remember_handshake( $result );

		return $result;
	}

	/**
	 * The last handshake result, if one is still fresh, without asking again.
	 *
	 * @return array|WP_Error|null Null when nothing is cached.
	 */
	public static function cached_handshake() {
		$cached = get_transient( self::HANDSHAKE_CACHE );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		if ( ! isset( $cached[ self::HANDSHAKE_FAILURE_KEY ] ) ) {
			return $cached;
		}

		$failure = $cached[ self::HANDSHAKE_FAILURE_KEY ];

		if ( ! is_array( $failure ) || empty( $failure['code'] ) ) {
			return null;
		}

		return new WP_Error(
			(string) $failure['code'],
			isset( $failure['message'] ) ? (string) $failure['message'] : '',
			isset( $failure['data'] ) ? $failure['data'] : ''
		);
	}

	/**
	 * Cache a handshake result for the screens.
	 *
	 * One key for both outcomes, so a failed Test connection replaces a cached
	 * success rather than leaving the screens contradicting the notice.
	 *
	 * @param array|WP_Error $result Handshake result.
	 * @return void
	 */
	private static function remember_handshake( $result ): void {
		if ( ! is_wp_error( $result ) ) {
			set_transient( self::HANDSHAKE_CACHE, $result, self::HANDSHAKE_CACHE_S );
			Send4_Minimum_Version::remember( $result );

			return;
		}

		// Stored as plain values rather than the object, which a transient
		// would have to unserialize back into a class.
		set_transient(
			self::HANDSHAKE_CACHE,
			array(
				self::HANDSHAKE_FAILURE_KEY => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				),
			),
			self::HANDSHAKE_FAILURE_S
		);
	}

	/**
	 * Which of the connection states a screen should describe.
	 *
	 * - disconnected: no credentials; nothing is sent.
	 * - unreachable:  credentials, but Send4 did not answer (or answered with
	 *                 a server error); submissions are queued and retried.
	 * - rejected:     Send4 answered and refused the credentials; submissions
	 *                 are refused too, not queued, so the copy has to differ.
	 * - unchecked:    credentials, and no handshake result to hand.
	 * - connected:    the handshake answered.
	 *
	 * "Rejected" mirrors what Send4_Api_Client treats as a permanent failure
	 * for a submission, so this screen and the delivery path agree about
	 * whether a lead is waiting or lost.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @return string
	 */
	public static function connection_state( $handshake ): string {
		if ( ! Send4_Settings::has_credentials() ) {
			return 'disconnected';
		}

		if ( is_array( $handshake ) ) {
			return 'connected';
		}

		if ( ! is_wp_error( $handshake ) ) {
			return 'unchecked';
		}

		$code = $handshake->get_error_code();
		$data = $handshake->get_error_data();

		if ( in_array( $code, array( 'send4_missing_ability', 'send4_bad_destination', 'send4_not_connected' ), true ) ) {
			return 'rejected';
		}

		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 'send4_token_rejected' === $code && in_array( $status, array( 400, 401 ), true ) ) {
			return 'rejected';
		}

		return 'unreachable';
	}

	/**
	 * What a failing connection means for the site's submissions, in words.
	 *
	 * @param string $state A connection_state() value.
	 * @return string Empty for states that are not failures.
	 */
	public static function outage_message( $state ) {
		if ( 'unreachable' === $state ) {
			return __( 'Send4 could not be reached just now. Form submissions are being queued and will be sent automatically once it answers again.', 'send4' );
		}

		if ( 'rejected' === $state ) {
			return __( 'Send4 refused this site\'s connection. Form submissions are not being delivered until it is fixed: test the connection again, or reconnect.', 'send4' );
		}

		return '';
	}

	/**
	 * Drop the cached handshake, whatever it says.
	 *
	 * @return void
	 */
	public static function forget_handshake(): void {
		delete_transient( self::HANDSHAKE_CACHE );
	}

	/**
	 * Which flows are bound to a form key, from the cached handshake.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @param string              $form_key  Form key.
	 * @return array<int,array>
	 */
	public static function flows_for( $handshake, $form_key ): array {
		if ( ! is_array( $handshake ) || empty( $handshake['flows'] ) ) {
			return array();
		}

		$exact    = array();
		$catchall = array();

		foreach ( $handshake['flows'] as $flow ) {
			$bound = isset( $flow['form_key'] ) ? $flow['form_key'] : null;

			if ( null === $bound || '' === $bound ) {
				$catchall[] = $flow;
				continue;
			}

			if ( $bound === $form_key ) {
				$exact[] = $flow;
			}
		}

		return empty( $exact ) ? $catchall : $exact;
	}

	/**
	 * Prove a credential works, and only then make it this site's.
	 *
	 * The one routine behind every way in — pasting a key, coming back from
	 * the Connect button, and `wp send4 connect` — because "connected" has to
	 * mean the same thing each way: the handshake answered, so the credential
	 * is real AND carries the ability.
	 *
	 * The candidate is tried in memory first. Saving it before the handshake,
	 * as this used to, meant a mistyped secret replaced a working one, and
	 * every submission after that was refused and dead-lettered until somebody
	 * noticed. On failure nothing is written at all.
	 *
	 * That includes where the API is. `wp send4 connect --url-base` used to
	 * save the new destination before trying the pair, so a refused pair left
	 * the site's working credentials pointed at the wrong environment. The
	 * destination is now tried and saved with the pair it belongs to.
	 *
	 * @param string $client_id     Candidate client ID.
	 * @param string $client_secret Candidate client secret.
	 * @param string $base_url      Candidate API root, '' to keep the configured one.
	 *                              The caller has checked it is an allowed address
	 *                              and that no constant pins the destination.
	 * @return array|WP_Error The handshake on success.
	 */
	public static function establish_connection( $client_id, $client_secret, $base_url = '' ) {
		$base_url = (string) $base_url;
		$result   = Send4_Api_Client::with_credentials( $client_id, $client_secret, Send4_Api_Client::TIMEOUT_INTERACTIVE, $base_url )->handshake();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Before the credentials go, exactly as on Disconnect: a row queued
		// before rows carried a client id belongs to the connection this one
		// replaces. Every way of connecting comes through here, and all of them
		// can be used while the site is still connected to another account —
		// left unowned, that row would be delivered to the new one.
		if ( Send4_Queue::table_exists() ) {
			Send4_Queue::adopt_unowned( Send4_Queue::current_client_id() );
		}

		if ( '' !== $base_url ) {
			update_option( Send4_Settings::BASE_URL_OPTION, untrailingslashit( $base_url ), false );
		}

		Send4_Settings::update_connection(
			array(
				'client_id'     => (string) $client_id,
				'client_secret' => (string) $client_secret,
				'client_name'   => isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '',
				'connected_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// The cached token belongs to whatever credential was stored before;
		// sending it on behalf of this one would be wrong either way.
		Send4_Api_Client::forget_token();
		self::remember_handshake( $result );

		// Tell the workspace what forms this site has, so its flow builder can
		// offer them straight away rather than after the first submission.
		// Forgotten and forced, because a reconnect may be a different
		// workspace that has never been told anything about this site. A
		// failure here does not undo the connection — the daily sweep retries.
		Send4_Forms_Sync::forget();
		Send4_Forms_Sync::maybe_push( true );

		return $result;
	}
}
