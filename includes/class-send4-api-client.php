<?php
/**
 * Everything that talks to app.send4.ai.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token exchange, handshake and submission POSTs.
 *
 * The token is cached in a transient and only re-exchanged when it is about to
 * expire or a call comes back 401. Sign-in is rate limited to ten attempts a
 * minute, and a token lives 24 hours, so a site that re-authenticated on every
 * request would spend its budget on nothing.
 *
 * The cache is shared by every request the site serves, so a cached token says
 * whose it is: the credential it was exchanged for and the API it was exchanged
 * at. One that answers to anything else is ignored, never sent. See binding().
 *
 * Send4 no longer evicts a client's previous token when a new one is issued, so
 * two sites sharing one API client no longer log each other out — but a key per
 * site is still what you want, because the credential is bound to the site_url
 * it was minted for.
 */
class Send4_Api_Client {

	const TOKEN_TRANSIENT = 'send4_access_token';

	/**
	 * Transient holding when sign-in may be tried again, after Send4 answered
	 * one with a Retry-After. See exchange_token().
	 */
	const SIGN_IN_COOLDOWN = 'send4_sign_in_cooldown';

	/** Re-exchange this many seconds before the token actually expires. */
	const TOKEN_SAFETY_MARGIN = 300;

	/** Fallback lifetime when the API does not say how long the token lives. */
	const TOKEN_FALLBACK_TTL = 3600;

	/** Timeout for a call made while a visitor waits on a form submit. */
	const TIMEOUT_VISITOR = 3;

	/** Timeout for a call made from wp-admin or WP-CLI. */
	const TIMEOUT_INTERACTIVE = 10;

	/**
	 * Timeout for a call made while an admin screen is being drawn.
	 *
	 * Shorter than an explicit action's, because nobody asked for this call:
	 * the admin opened a page. During an outage every Send4 screen would
	 * otherwise hang for the full interactive timeout — more on a path that
	 * signs in again — before saying anything at all.
	 */
	const TIMEOUT_SCREEN = 4;

	/**
	 * Seconds to wait for the API.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Credentials to use instead of the stored ones, or null for the stored.
	 *
	 * @var array{client_id:string,client_secret:string}|null
	 */
	private $credentials = null;

	/**
	 * The token issued to $credentials, held for this object's lifetime only.
	 *
	 * @var string
	 */
	private $candidate_token = '';

	/**
	 * An API root to use instead of the configured one, or null for the
	 * configured. Only ever set beside $credentials: a destination is proven
	 * with the pair that will be used against it.
	 *
	 * @var string|null
	 */
	private $base_url = null;

	/**
	 * Constructor.
	 *
	 * @param int $timeout Seconds to wait for the API.
	 */
	public function __construct( $timeout = self::TIMEOUT_INTERACTIVE ) {
		$this->timeout = (int) $timeout;
	}

	/**
	 * A client that signs in with credentials that are not stored (yet).
	 *
	 * For proving a new pair works before it replaces one that does. Its token
	 * never touches the shared token cache, in either direction: reading the
	 * cache would hand it the stored credential's token and make any pair look
	 * valid, and writing it would leave the stored credential using a token
	 * that belongs to a pair that may never be saved.
	 *
	 * The same goes for where it signs in. A pair meant for another Send4
	 * environment has to be tried against that environment, and the site's own
	 * destination must not move until the pair is accepted there — so the
	 * candidate carries its destination with it rather than saving it first.
	 *
	 * @param string $client_id     Candidate client ID.
	 * @param string $client_secret Candidate client secret.
	 * @param int    $timeout       Seconds to wait for the API.
	 * @param string $base_url      Candidate API root, '' for the configured one.
	 * @return Send4_Api_Client
	 */
	public static function with_credentials( $client_id, $client_secret, $timeout = self::TIMEOUT_INTERACTIVE, $base_url = '' ) {
		$client              = new self( $timeout );
		$client->credentials = array(
			'client_id'     => (string) $client_id,
			'client_secret' => (string) $client_secret,
		);

		if ( '' !== (string) $base_url ) {
			$client->base_url = untrailingslashit( (string) $base_url );
		}

		return $client;
	}

	/**
	 * A client configured for the visitor-facing path.
	 *
	 * @return Send4_Api_Client
	 */
	public static function for_visitor() {
		return new self( self::TIMEOUT_VISITOR );
	}

	/**
	 * Drop the cached token.
	 *
	 * @return void
	 */
	public static function forget_token() {
		delete_transient( self::TOKEN_TRANSIENT );
		delete_transient( self::SIGN_IN_COOLDOWN );
	}

	/**
	 * A bearer token, from cache when it is still comfortably valid.
	 *
	 * @param bool $force Exchange a fresh token even if one is cached.
	 * @return string|WP_Error
	 */
	public function token( $force = false ) {
		if ( null !== $this->credentials ) {
			return ( ! $force && '' !== $this->candidate_token ) ? $this->candidate_token : $this->exchange_token();
		}

		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );

			if ( is_array( $cached ) && ! empty( $cached['token'] ) && isset( $cached['expires_at'], $cached['bound_to'] ) ) {
				$ours = hash_equals( (string) $cached['bound_to'], self::binding( Send4_Settings::connection(), $this->destination() ) );

				if ( $ours && (int) $cached['expires_at'] > time() + self::TOKEN_SAFETY_MARGIN ) {
					return (string) $cached['token'];
				}
			}
		}

		return $this->exchange_token();
	}

	/**
	 * What a cached token answers to: the credential it was exchanged for and
	 * the API it was exchanged at.
	 *
	 * An exchange is a network call of up to ten seconds, and a reconnect can
	 * land inside one. The credentials change, the cache is cleared — and then
	 * the old request comes back and writes the token of the account the site
	 * has just left into the cache that was cleared for it. Nothing can stop
	 * that write, so the token carries this instead, and token() will not use
	 * one that answers to anything but what is stored now. The same check keeps
	 * production's token from being shown to staging after `wp send4 connect
	 * --url-base`, and the other way round.
	 *
	 * A digest, because the transient sits in the database or an object cache
	 * and has no business holding a second copy of the secret.
	 *
	 * @param array  $connection The credential, as Send4_Settings::connection() shapes it.
	 * @param string $base_url   The API root.
	 * @return string
	 */
	private static function binding( array $connection, $base_url ) {
		return hash( 'sha256', $connection['client_id'] . "\n" . $connection['client_secret'] . "\n" . $base_url );
	}

	/**
	 * The API root this client talks to.
	 *
	 * @return string
	 */
	private function destination() {
		return null !== $this->base_url ? $this->base_url : Send4_Settings::base_url();
	}

	/**
	 * Swap the client credentials for a fresh bearer token.
	 *
	 * @return string|WP_Error
	 */
	private function exchange_token() {
		$connection = null !== $this->credentials ? $this->credentials : Send4_Settings::connection();

		if ( '' === $connection['client_id'] || '' === $connection['client_secret'] ) {
			return new WP_Error(
				'send4_not_connected',
				__( 'Send4 is not connected: connect your account under Send4 → Connection.', 'send4' )
			);
		}

		// Worked out before the request, from what the request is about to use.
		// By the time it answers, what is stored may belong to somebody else.
		$bound_to = self::binding( $connection, $this->destination() );

		// Candidate credentials are being tried by somebody at a screen, not
		// by a worker: they neither wait on the connection's cooldown nor set it.
		$stored = null === $this->credentials;

		if ( $stored ) {
			$cooldown = self::cooldown( $bound_to );

			if ( null !== $cooldown ) {
				return $cooldown;
			}
		}

		$response = $this->request(
			'POST',
			'/api/v2/auth/token',
			array(
				'client_id'     => $connection['client_id'],
				'client_secret' => $connection['client_secret'],
			),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode( $response );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			// Sign-in is the endpoint that is rate limited, so its Retry-After
			// is the one the queue most needs to hear.
			$retry_after = self::retry_after( $response );
			$message     = $this->describe_auth_failure( $code, $body );

			if ( $stored && $retry_after > 0 ) {
				// Kept for the whole connection, not just this request: the
				// next queue run, a visitor's submit and an admin screen would
				// each sign in again within the wait, and each one would earn
				// another 429 and push the throttle further out.
				set_transient(
					self::SIGN_IN_COOLDOWN,
					array(
						'until'    => time() + $retry_after,
						'status'   => $code,
						'message'  => $message,
						'bound_to' => $bound_to,
					),
					$retry_after
				);
			}

			return new WP_Error(
				'send4_token_rejected',
				$message,
				array(
					'status'      => $code,
					'retry_after' => $retry_after,
				)
			);
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : self::TOKEN_FALLBACK_TTL;

		if ( ! empty( $body['expires_at'] ) ) {
			$parsed = strtotime( (string) $body['expires_at'] );

			if ( false !== $parsed && $parsed > time() ) {
				$expires_in = $parsed - time();
			}
		}

		if ( $expires_in < 60 ) {
			$expires_in = self::TOKEN_FALLBACK_TTL;
		}

		if ( null !== $this->credentials ) {
			$this->candidate_token = (string) $body['access_token'];

			return $this->candidate_token;
		}

		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'token'      => (string) $body['access_token'],
				'expires_at' => time() + $expires_in,
				'bound_to'   => $bound_to,
			),
			$expires_in
		);

		return (string) $body['access_token'];
	}

	/**
	 * The error sign-in would answer with while Send4's Retry-After runs,
	 * without asking it; null when sign-in may go ahead.
	 *
	 * A wait recorded for another credential or API root does not apply, for
	 * the reason binding() gives.
	 *
	 * @param string $bound_to binding() of the credential about to sign in.
	 * @return WP_Error|null
	 */
	private static function cooldown( $bound_to ) {
		$cooldown = get_transient( self::SIGN_IN_COOLDOWN );

		if ( ! is_array( $cooldown ) || ! isset( $cooldown['until'], $cooldown['bound_to'] ) ) {
			return null;
		}

		$wait = (int) $cooldown['until'] - time();

		if ( $wait <= 0 || ! hash_equals( (string) $cooldown['bound_to'], $bound_to ) ) {
			return null;
		}

		return new WP_Error(
			'send4_sign_in_cooldown',
			isset( $cooldown['message'] ) ? (string) $cooldown['message'] : __( 'Send4 asked this site to wait before signing in again.', 'send4' ),
			array(
				'status'      => isset( $cooldown['status'] ) ? (int) $cooldown['status'] : 429,
				'retry_after' => $wait,
			)
		);
	}

	/**
	 * Turn an auth failure into something an admin can act on.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function describe_auth_failure( $code, $body ) {
		if ( 429 === $code ) {
			return __( 'Send4 is rate limiting sign-in attempts (10 per minute). Wait a minute and try again.', 'send4' );
		}

		if ( ! empty( $body['error_description'] ) ) {
			return (string) $body['error_description'];
		}

		if ( 401 === $code ) {
			return __( 'Send4 rejected these credentials. Check the client ID and secret, and that the API client is active.', 'send4' );
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Send4 returned an unexpected status (%d) while signing in.', 'send4' ), (int) $code );
	}

	/**
	 * Leg 1 of the connect handshake: introduce this site to Send4.
	 *
	 * Unauthenticated by necessity — obtaining a credential is the point — and
	 * the reply is only useful to whoever receives it, since redeeming the code
	 * later also requires the secret returned here.
	 *
	 * @return array{connect_id:string,connect_secret:string,authorize_url:string}|WP_Error
	 */
	public function register_site() {
		$response = $this->request(
			'POST',
			'/api/connect/register',
			array(
				'platform'  => 'wordpress',
				'site_url'  => untrailingslashit( home_url() ),
				'admin_url' => admin_url( 'admin.php?page=' . Send4_Admin::CONNECTION_SLUG ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode( $response );

		if ( 201 !== $code || empty( $body['connect_id'] ) || empty( $body['authorize_url'] ) ) {
			return new WP_Error(
				'send4_register_failed',
				$this->describe_connect_failure( $code, $body )
			);
		}

		$authorize_url = (string) $body['authorize_url'];

		// The administrator's browser is about to be sent here. The API is
		// trusted with the submissions, not with where that browser ends up, so
		// an approval link that points anywhere but Send4 (or a development
		// machine) is refused here, before it can become a redirect.
		if ( ! Send4_Settings::authorize_url_is_allowed( $authorize_url ) ) {
			return new WP_Error(
				'send4_register_failed',
				sprintf(
					/* translators: %s: the URL the API answered with. */
					__( 'Send4 answered with an approval link that is not on Send4 (%s), so the connection was not started.', 'send4' ),
					$authorize_url
				)
			);
		}

		return array(
			'connect_id'     => (string) $body['connect_id'],
			'connect_secret' => (string) ( $body['connect_secret'] ?? '' ),
			'authorize_url'  => $authorize_url,
		);
	}

	/**
	 * Leg 3: swap the approved one-time code for this site's own credentials.
	 *
	 * @param string $connect_id     The id returned by register_site().
	 * @param string $connect_secret The secret returned by register_site().
	 * @param string $code           The single-use code Send4 sent back.
	 * @return array{client_id:string,client_secret:string}|WP_Error
	 */
	public function exchange_code( $connect_id, $connect_secret, $code ) {
		$response = $this->request(
			'POST',
			'/api/connect/exchange',
			array(
				'connect_id'     => (string) $connect_id,
				'connect_secret' => (string) $connect_secret,
				'code'           => (string) $code,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode( $response );

		if ( 200 !== $http || empty( $body['client_id'] ) || empty( $body['client_secret'] ) ) {
			return new WP_Error(
				'send4_exchange_failed',
				$this->describe_connect_failure( $http, $body )
			);
		}

		return array(
			'client_id'     => (string) $body['client_id'],
			'client_secret' => (string) $body['client_secret'],
		);
	}

	/**
	 * Turn a handshake failure into something an admin can act on.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function describe_connect_failure( $code, $body ) {
		if ( ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}

		if ( 429 === $code ) {
			return __( 'Send4 is rate limiting connection attempts. Wait a minute and try again.', 'send4' );
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Send4 returned an unexpected status (%d) while connecting.', 'send4' ), (int) $code );
	}

	/**
	 * Connection test: who are we, and which flows are listening?
	 *
	 * Since #1531 the answer may also carry `plugin` (the minimum version Send4
	 * asks sites to run) and `readiness` (what still stands between this site
	 * and a texted lead). An older Send4 sends neither, and each is kept only
	 * when it is an array; Send4_Minimum_Version and Send4_Readiness read them.
	 *
	 * @return array|WP_Error Array with client, flows, plugin and readiness keys.
	 */
	public function handshake() {
		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->authenticated_request( 'GET', '/api/integrations/wordpress/handshake', null, $token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode( $response );

		if ( 401 === $code ) {
			$token = $this->token( true );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->authenticated_request( 'GET', '/api/integrations/wordpress/handshake', null, $token );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = $this->decode( $response );
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'send4_missing_ability',
				__( 'This API client is missing the "WordPress plugin" permission. Edit the key in your Send4 dashboard and tick that ability.', 'send4' )
			);
		}

		if ( 200 !== $code || ! isset( $body['data'] ) ) {
			return new WP_Error(
				'send4_handshake_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Send4 returned an unexpected status (%d) for the connection test.', 'send4' ), $code )
			);
		}

		$data = $body['data'];

		return array(
			'client'    => isset( $data['client'] ) && is_array( $data['client'] ) ? $data['client'] : array(),
			'flows'     => isset( $data['flows'] ) && is_array( $data['flows'] ) ? $data['flows'] : array(),
			'plugin'    => isset( $data['plugin'] ) && is_array( $data['plugin'] ) ? $data['plugin'] : null,
			'readiness' => isset( $data['readiness'] ) && is_array( $data['readiness'] ) ? array_values( $data['readiness'] ) : null,
		);
	}

	/**
	 * POST one submission envelope.
	 *
	 * Retries exactly once, and only on a 401, because a token can stop being
	 * accepted at any time — an operator revoking the key, or the 24 hour
	 * lifetime running out mid-flight.
	 *
	 * @param array $envelope The envelope, already normalized.
	 * @return Send4_Api_Result
	 */
	public function submit( array $envelope ) {
		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $this->result_from_token_error( $token );
		}

		$response = $this->authenticated_request( 'POST', '/api/integrations/wordpress/submissions', $envelope, $token );
		$result   = $this->interpret_submission( $response );

		if ( 401 !== $result->code ) {
			return $result;
		}

		$token = $this->token( true );

		if ( is_wp_error( $token ) ) {
			return $this->result_from_token_error( $token );
		}

		$response = $this->authenticated_request( 'POST', '/api/integrations/wordpress/submissions', $envelope, $token );

		return $this->interpret_submission( $response );
	}

	/**
	 * Report this site's detected forms to Send4 (#0687).
	 *
	 * Send4 has no other way to learn what forms exist here — it only ever sees
	 * a form key on a submission, which is too late to offer that form in the
	 * flow builder. PUT because it replaces the whole inventory: a form deleted
	 * in WordPress must stop appearing there, and there is no per-form delete.
	 *
	 * The site's versions ride along as `environment` (#1531). Send4 reads that
	 * key leniently and an older Send4 ignores it, so it can never cost the
	 * site its inventory.
	 *
	 * @param array $descriptors Descriptors from Send4_Form_Descriptors::build().
	 * @param array $environment From Send4_Environment::report(); empty sends none.
	 * @return true|WP_Error True on success.
	 */
	public function report_forms( array $descriptors, array $environment = array() ) {
		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$body = array( 'forms' => array_values( $descriptors ) );

		if ( ! empty( $environment ) ) {
			$body['environment'] = $environment;
		}

		$response = $this->authenticated_request( 'PUT', '/api/integrations/wordpress/forms', $body, $token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// The token may simply have expired between the cache and the call.
		if ( 401 === $code ) {
			$token = $this->token( true );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->authenticated_request( 'PUT', '/api/integrations/wordpress/forms', $body, $token );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( 200 === $code ) {
			return true;
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'send4_missing_ability',
				__( 'This API client is missing the "WordPress plugin" permission. Edit the key in your Send4 dashboard and tick that ability.', 'send4' )
			);
		}

		// 409: the credential was minted by hand rather than by connecting this
		// site, so Send4 has no installation row to record forms against. Not
		// retryable, and the fix is to reconnect — say so rather than leaving
		// the admin to read a status code.
		if ( 409 === $code ) {
			$body = $this->decode( $response );

			return new WP_Error(
				'send4_no_install',
				isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message']
					? $body['message']
					: __( 'This API key was not created by connecting this site, so Send4 has nowhere to record its forms. Reconnect from the Connection screen.', 'send4' )
			);
		}

		return new WP_Error(
			'send4_forms_report_failed',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Send4 returned an unexpected status (%d) while reporting this site\'s forms.', 'send4' ), $code )
		);
	}

	/**
	 * Classify a submission response.
	 *
	 * @param array|WP_Error $response Raw wp_remote_* return value.
	 * @return Send4_Api_Result
	 */
	private function interpret_submission( $response ) {
		if ( is_wp_error( $response ) ) {
			return new Send4_Api_Result( Send4_Api_Result::TRANSIENT, 0, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode( $response );

		if ( 202 === $code || 200 === $code ) {
			// The API always answers {"status":"accepted"|"duplicate","routed":bool}.
			// Anything else — HTML from a cache, {"maintenance":true} from a load
			// balancer, an empty body — is not this API having accepted the lead.
			// Reading it as accepted would call it UNROUTED, which is final, and
			// the queue would delete the only copy of somebody's enquiry.
			$status = isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : '';

			if ( ! in_array( $status, array( 'accepted', 'duplicate' ), true ) || ! isset( $body['routed'] ) || ! is_bool( $body['routed'] ) ) {
				return new Send4_Api_Result(
					Send4_Api_Result::TRANSIENT,
					$code,
					__( 'Send4 answered with something that was not a submission response.', 'send4' )
				);
			}

			$routed = $body['routed'];
			$run_id = isset( $body['run_id'] ) ? (int) $body['run_id'] : null;

			if ( 'duplicate' === $status ) {
				return new Send4_Api_Result(
					Send4_Api_Result::DUPLICATE,
					$code,
					__( 'Send4 had already received this submission.', 'send4' )
				);
			}

			if ( ! $routed ) {
				return new Send4_Api_Result(
					Send4_Api_Result::UNROUTED,
					$code,
					__( 'Delivered, but no Send4 flow is listening for this form.', 'send4' )
				);
			}

			return new Send4_Api_Result( Send4_Api_Result::DELIVERED, $code, '', $run_id );
		}

		if ( 422 === $code ) {
			return new Send4_Api_Result(
				Send4_Api_Result::PERMANENT,
				$code,
				$this->describe_validation_failure( $body )
			);
		}

		if ( 403 === $code ) {
			// Two different refusals share this status: the credential lacks the
			// ability, or it belongs to a different site (Send4 binds a
			// credential to the site_url it was minted for and answers
			// {status:'rejected'} with a message saying so). They need opposite
			// fixes — tick an ability, versus reconnect this site — so prefer
			// what the server said over guessing.
			$message = isset( $body['message'] ) ? trim( (string) $body['message'] ) : '';

			return new Send4_Api_Result(
				Send4_Api_Result::PERMANENT,
				$code,
				'' !== $message
					? $message
					: __( 'This API client is missing the "WordPress plugin" permission.', 'send4' )
			);
		}

		if ( 401 === $code ) {
			return new Send4_Api_Result(
				Send4_Api_Result::TRANSIENT,
				$code,
				__( 'Send4 rejected the access token.', 'send4' )
			);
		}

		if ( $code >= 300 && $code < 400 ) {
			return new Send4_Api_Result(
				Send4_Api_Result::TRANSIENT,
				$code,
				__( 'Send4 answered with a redirect, which usually means the URL is wrong.', 'send4' )
			);
		}

		if ( 429 === $code ) {
			return new Send4_Api_Result(
				Send4_Api_Result::TRANSIENT,
				$code,
				__( 'Send4 is rate limiting submissions. The lead will be retried.', 'send4' ),
				null,
				self::retry_after( $response )
			);
		}

		// Timeout and "too early" are the only other 4xx a later attempt can fix.
		// Every other client error (400, 404, 405, 410, 413, ...) will get the
		// very same answer next time, and retrying it ten times over a day and
		// a half only delays the moment an operator is shown the problem.
		if ( $code >= 400 && $code < 500 && ! in_array( $code, array( 408, 425 ), true ) ) {
			$message = isset( $body['message'] ) && is_string( $body['message'] ) ? trim( $body['message'] ) : '';

			if ( '' === $message ) {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Send4 refused the submission (status %d).', 'send4' ), $code );
			}

			return new Send4_Api_Result( Send4_Api_Result::PERMANENT, $code, $message );
		}

		return new Send4_Api_Result(
			Send4_Api_Result::TRANSIENT,
			$code,
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Send4 returned status %d.', 'send4' ), $code )
		);
	}

	/**
	 * Flatten Laravel's validation error bag into one line.
	 *
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function describe_validation_failure( $body ) {
		if ( empty( $body['errors'] ) || ! is_array( $body['errors'] ) ) {
			return isset( $body['message'] ) ? (string) $body['message'] : __( 'Send4 rejected the submission as malformed.', 'send4' );
		}

		$parts = array();

		foreach ( $body['errors'] as $field => $messages ) {
			$messages = is_array( $messages ) ? $messages : array( $messages );
			$parts[]  = $field . ': ' . implode( ' ', array_map( 'strval', $messages ) );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Seconds a response's Retry-After header asks for, 0 when absent.
	 *
	 * The header is either a number of seconds or an HTTP date. Capped at a
	 * day: a wild value must not park a lead beyond the retention window.
	 *
	 * @param array|WP_Error $response Raw wp_remote_* return value.
	 * @return int
	 */
	private static function retry_after( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$header = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );

		if ( '' === $header ) {
			return 0;
		}

		if ( ctype_digit( $header ) ) {
			$seconds = (int) $header;
		} else {
			$at      = strtotime( $header );
			$seconds = false === $at ? 0 : $at - time();
		}

		return min( DAY_IN_SECONDS, max( 0, $seconds ) );
	}

	/**
	 * A token failure is permanent when the credentials themselves are wrong.
	 *
	 * Public because the queue worker exchanges the token once, before its
	 * batch, and has to classify a failure the same way submit() would.
	 *
	 * @param WP_Error $error Token error.
	 * @return Send4_Api_Result
	 */
	public function result_from_token_error( WP_Error $error ) {
		// Neither of these can come right on a retry: one means no credentials
		// were entered, the other means the destination is not Send4 at all.
		$permanent = in_array(
			$error->get_error_code(),
			array( 'send4_not_connected', 'send4_bad_destination' ),
			true
		);
		$data      = $error->get_error_data();
		$status    = (int) ( $data['status'] ?? 0 );

		if ( 400 === $status || 401 === $status ) {
			$permanent = true;
		}

		return new Send4_Api_Result(
			$permanent ? Send4_Api_Result::PERMANENT : Send4_Api_Result::TRANSIENT,
			$status,
			$error->get_error_message(),
			null,
			(int) ( $data['retry_after'] ?? 0 )
		);
	}

	/**
	 * Issue a request carrying a bearer token.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below the API root.
	 * @param array|null $body   JSON body, or null for GET.
	 * @param string     $token  Bearer token.
	 * @return array|WP_Error
	 */
	private function authenticated_request( $method, $path, $body, $token ) {
		return $this->request( $method, $path, $body, true, array( 'Authorization' => 'Bearer ' . $token ) );
	}

	/**
	 * Issue a request. All HTTP goes through here so timeouts and headers stay consistent.
	 *
	 * @param string     $method        HTTP method.
	 * @param string     $path          Path below the API root.
	 * @param array|null $body          JSON body, or null.
	 * @param bool       $json          Send the body as JSON (false: form encoded).
	 * @param array      $extra_headers Additional headers.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body, $json = true, array $extra_headers = array() ) {
		$base_url = $this->destination();

		if ( ! Send4_Settings::base_url_is_allowed( $base_url ) ) {
			return new WP_Error(
				'send4_bad_destination',
				sprintf(
					/* translators: %s: configured URL. */
					__( 'Refusing to send: %s is not a Send4 address.', 'send4' ),
					$base_url
				)
			);
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'blocking'    => true,
			'sslverify'   => true,
			// A redirect is never a legitimate answer from this API. Following
			// one would mean handing the bearer token to whoever the redirect
			// names, and an unauthenticated Laravel route answers 302 to /login,
			// which would otherwise look like a successful 200 with an HTML body.
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Send4WordPress/' . SEND4_VERSION . '; ' . home_url( '/' ),
				),
				$extra_headers
			),
			'user-agent'  => 'Send4WordPress/' . SEND4_VERSION,
		);

		if ( null !== $body ) {
			if ( $json ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			} else {
				$args['body'] = $body;
			}
		}

		return wp_remote_request( $base_url . $path, $args );
	}

	/**
	 * Decode a JSON response body, tolerating garbage.
	 *
	 * @param array $response Raw wp_remote_* return value.
	 * @return array
	 */
	private function decode( $response ) {
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
