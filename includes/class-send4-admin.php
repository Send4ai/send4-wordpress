<?php
/**
 * The Send4 admin menu.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The whole wp-admin surface: an overview, the form list, and the connection.
 */
class Send4_Admin {

	const PAGE_SLUG         = 'send4';
	const FORMS_SLUG        = 'send4-forms';
	const CONNECTION_SLUG   = 'send4-connection';
	const CAPABILITY        = 'manage_options';
	const HANDSHAKE_CACHE   = 'send4_handshake';
	const HANDSHAKE_CACHE_S = 300;
	const NOTICE_CACHE_S    = 60;

	/**
	 * What a pending notice's transient is called, before the user id. A
	 * constant so the uninstall test can plant one: uninstall.php has to sweep
	 * these up by prefix, as it cannot know who was half way through a redirect.
	 */
	const NOTICE_PREFIX = 'send4_notice_';

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
	 * The hook suffixes WordPress gave our screens, keyed dashboard, forms and
	 * connection.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_url' ) );
		add_action( 'admin_init', array( $this, 'maybe_complete_connect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SEND4_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_post_send4_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_send4_start_connect', array( $this, 'handle_start_connect' ) );
		add_action( 'admin_post_send4_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_send4_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_send4_save_mapping', array( $this, 'handle_save_mapping' ) );
		add_action( 'admin_post_send4_disable_form', array( $this, 'handle_disable_form' ) );
		add_action( 'admin_post_send4_sync_forms', array( $this, 'handle_sync_forms' ) );
		add_action( 'admin_post_send4_run_queue', array( $this, 'handle_run_queue' ) );
		add_action( 'admin_post_send4_requeue', array( $this, 'handle_requeue' ) );
		add_action( 'admin_post_send4_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu and its screens.
	 *
	 * @return void
	 */
	public function register_page() {
		// The hook suffixes are kept rather than rebuilt from the slugs later:
		// WordPress names a submenu's screen after sanitize_title() of the
		// parent's *translated* menu title, so "send4_page_send4-forms" only
		// holds while the title is untranslated. A site in another language
		// would lose the stylesheet on every screen but the dashboard.
		$this->hooks = array();

		$this->hooks['dashboard'] = (string) add_menu_page(
			__( 'Send4', 'send4' ),
			__( 'Send4', 'send4' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			26
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Send4 Dashboard', 'send4' ),
			__( 'Dashboard', 'send4' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);

		$this->hooks['forms'] = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Forms & Triggers', 'send4' ),
			__( 'Forms & Triggers', 'send4' ),
			self::CAPABILITY,
			self::FORMS_SLUG,
			array( $this, 'render_forms' )
		);

		$this->hooks['connection'] = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Connection', 'send4' ),
			__( 'Connection', 'send4' ),
			self::CAPABILITY,
			self::CONNECTION_SLUG,
			array( $this, 'render_connection' )
		);

		// add_submenu_page() answers false for a user without the capability.
		$this->hooks = array_filter( $this->hooks );
	}

	/**
	 * Answer for options-general.php?page=send4, where this plugin used to
	 * live.
	 *
	 * A connection started before the upgrade told Send4 to return the browser
	 * to that URL and has 24 hours to do so, and bookmarks outlive releases.
	 *
	 * Done on admin_init rather than by registering the old slug as a hidden
	 * page: the slug is now a top-level menu, so WordPress resolves a
	 * Settings-parented page with the same slug to `toplevel_page_send4` —
	 * the dashboard's own hook — and the redirect would fire on the dashboard
	 * instead. admin_init runs before wp-admin dispatches the page, so this
	 * lands earlier and cannot collide with anything.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_url() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'options-general.php' !== $pagenow || ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$args = array();

		foreach ( array( 'send4_code', 'send4_connect_id' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				// Encoded here because add_query_arg() does not encode what it
				// is given: unencoded, a code carrying an ampersand would be
				// split into extra query arguments — one of which could be
				// `page`, and the last `page` wins.
				$args[ $key ] = rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( $this->page_url( $args, self::CONNECTION_SLUG ) );
		exit;
	}

	/**
	 * Redeem the one-time code Send4 sends the browser back with.
	 *
	 * It arrives as a GET on the Connection screen rather than through
	 * admin-post.php, because Send4 is redirecting a browser, not posting a
	 * form. There is no WP nonce to check and none is needed: the exchange also
	 * requires the registration secret, which never left this site, so a forged
	 * link carrying somebody else's code cannot produce a credential.
	 *
	 * On admin_init and not in render_connection(), because every path out of
	 * here ends in a redirect and wp-admin prints admin-header.php before it
	 * calls a page's render callback. Redirecting from the renderer means
	 * wp_redirect() cannot send Location — "headers already sent" — and the
	 * exit that follows truncates the page, so the admin gets a blank screen
	 * whether the connection succeeded or failed. admin_init runs before a
	 * byte of output.
	 *
	 * @return void
	 */
	public function maybe_complete_connect() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) || self::CONNECTION_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( empty( $_GET['send4_code'] ) || empty( $_GET['send4_connect_id'] ) ) {
			return;
		}

		// Not wp_die(): someone without the capability cannot reach the screen
		// anyway, and WordPress says so in its own words a moment later.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->complete_connect(
			sanitize_text_field( wp_unslash( $_GET['send4_code'] ) ),
			sanitize_text_field( wp_unslash( $_GET['send4_connect_id'] ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'send4' ) . '</a>';

		return $links;
	}

	/**
	 * Stylesheet for the Send4 screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! $this->is_send4_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'send4-admin', Send4_Assets::url( 'admin/css/admin.css' ), array(), SEND4_VERSION );

		// Names the default-country options in the admin's own language. The
		// select works without it, showing country codes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $this->hooks['forms'] ) && $this->hooks['forms'] === $hook_suffix && ! empty( $_GET['form'] ) ) {
			wp_enqueue_script( 'send4-phone-country', Send4_Assets::url( 'admin/js/phone-country.js' ), array(), SEND4_VERSION, true );
		}
	}

	/**
	 * Mark our own screens so the stylesheet can restyle the content area
	 * without reaching into any other plugin's page.
	 *
	 * @param string $classes Space separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = get_current_screen();

		if ( null !== $screen && $this->is_send4_screen( $screen->id ) ) {
			$classes .= ' send4-screen';
		}

		return $classes;
	}

	/**
	 * Is this hook suffix / screen id one of ours?
	 *
	 * @param string $hook_suffix Hook suffix or screen id.
	 * @return bool
	 */
	private function is_send4_screen( $hook_suffix ) {
		return '' !== (string) $hook_suffix && in_array( (string) $hook_suffix, $this->hooks, true );
	}

	/**
	 * URL of one of our screens.
	 *
	 * @param array  $args Extra query args.
	 * @param string $slug Page slug, defaulting to the dashboard.
	 * @return string
	 */
	public function page_url( array $args = array(), $slug = self::PAGE_SLUG ) {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The overview screen.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '30d';

		$admin = $this;
		$data  = Send4_Admin_Dashboard::build( $this, Send4_Stats::valid_range( $range ) );

		require SEND4_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * The form list, or one form's field mapping.
	 *
	 * @return void
	 */
	public function render_forms() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_key = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';

		if ( '' !== $form_key ) {
			$this->render_mapping( $form_key );

			return;
		}

		$handshake = Send4_Settings::has_credentials() ? $this->handshake( false ) : null;
		$forms     = Send4_Adapters::detect_forms();
		$adapters  = Send4_Adapters::all();
		$admin     = $this;

		require SEND4_PLUGIN_DIR . 'admin/views/forms.php';
	}

	/**
	 * The connection screen.
	 *
	 * @return void
	 */
	public function render_connection() {
		$this->guard();

		$connection = Send4_Settings::connection();
		$handshake  = Send4_Settings::has_credentials() ? $this->handshake( false ) : null;
		$admin      = $this;

		require SEND4_PLUGIN_DIR . 'admin/views/connection.php';
	}

	/**
	 * Render the per-form mapping screen.
	 *
	 * @param string $form_key Form key.
	 * @return void
	 */
	private function render_mapping( $form_key ) {
		$descriptor = $this->find_form( $form_key );

		if ( null === $descriptor ) {
			echo '<div class="wrap send4-wrap"><h1>' . esc_html__( 'Send4', 'send4' ) . '</h1><div class="notice notice-error"><p>'
				. esc_html__( 'That form no longer exists.', 'send4' ) . '</p></div><p><a href="'
				. esc_url( $this->page_url( array(), self::FORMS_SLUG ) ) . '">' . esc_html__( 'Back to forms', 'send4' ) . '</a></p></div>';

			return;
		}

		$form = Send4_Settings::form( $form_key );

		// Only the app bar's pill reads this, and the admin almost always
		// arrives from the form list, which has just checked. Not worth a
		// network round trip before the page can draw.
		$handshake = Send4_Settings::has_credentials() ? self::cached_handshake() : null;
		$admin     = $this;

		require SEND4_PLUGIN_DIR . 'admin/views/mapping.php';
	}

	/**
	 * Every screen starts by proving the caller may be here.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to configure Send4.', 'send4' ) );
		}
	}

	/**
	 * Find a detected form by key.
	 *
	 * @param string $form_key Form key.
	 * @return array|null
	 */
	public function find_form( $form_key ) {
		return Send4_Adapters::find_form( $form_key );
	}

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
	public function handshake( $force = false ) {
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
	private static function remember_handshake( $result ) {
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
	public static function connection_state( $handshake ) {
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
	public static function forget_handshake() {
		delete_transient( self::HANDSHAKE_CACHE );
	}

	/**
	 * Which flows are bound to a form key, from the cached handshake.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @param string              $form_key  Form key.
	 * @return array<int,array>
	 */
	public function flows_for( $handshake, $form_key ) {
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
	 * Save credentials and immediately prove they work.
	 *
	 * @return void
	 */
	public function handle_connect() {
		$this->require_capability();
		check_admin_referer( 'send4_connect' );

		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		$existing      = Send4_Settings::connection();

		// sanitize_text_field() leaves a real secret exactly as it was: only
		// letters, digits, "_" and "-", which it never touches. Anything else
		// was not a Send4 secret to begin with, so it is refused, never sent.
		if ( '' !== $client_secret && ! Send4_Settings::is_client_secret( $client_secret ) ) {
			$this->redirect_with_notice( 'error', __( 'That is not a Send4 client secret. Copy it again from your API key in Send4.', 'send4' ), array(), self::CONNECTION_SLUG );
		}

		if ( '' === $client_secret ) {
			$client_secret = $existing['client_secret'];
		}

		if ( '' === $client_id || '' === $client_secret ) {
			$this->redirect_with_notice( 'error', __( 'Both the client ID and the client secret are required.', 'send4' ), array(), self::CONNECTION_SLUG );
		}

		$this->confirm_connection( $client_id, $client_secret );
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
		// offer them straight away rather than after the first submission
		// (#0687). Forgotten and forced, because a reconnect may be a different
		// workspace that has never been told anything about this site. A
		// failure here does not undo the connection — the daily sweep retries.
		Send4_Forms_Sync::forget();
		Send4_Forms_Sync::maybe_push( true );

		return $result;
	}

	/**
	 * Connect with a candidate credential and say how it went.
	 *
	 * Always redirects, back to the Connection screen the admin acted on.
	 *
	 * @param string $client_id     Candidate client ID.
	 * @param string $client_secret Candidate client secret.
	 * @return void
	 */
	private function confirm_connection( $client_id, $client_secret ) {
		$had_credentials = Send4_Settings::has_credentials();
		$result          = self::establish_connection( $client_id, $client_secret );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice(
				'error',
				$had_credentials
					/* translators: %s: why Send4 refused, e.g. "Send4 rejected these credentials." */
					? sprintf( __( '%s The new credentials were not saved; this site is still using the ones it had.', 'send4' ), $result->get_error_message() )
					/* translators: %s: why Send4 refused, e.g. "Send4 rejected these credentials." */
					: sprintf( __( '%s The credentials were not saved.', 'send4' ), $result->get_error_message() ),
				array(),
				self::CONNECTION_SLUG
			);
		}

		$this->redirect_with_notice(
			'success',
			/* translators: %s: Send4 account name. */
			sprintf( __( 'Connected to %s.', 'send4' ), isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '' ),
			array(),
			self::CONNECTION_SLUG
		);
	}

	/**
	 * Start a connection: introduce this site to Send4 and send the admin there
	 * to approve it.
	 *
	 * @return void
	 */
	public function handle_start_connect() {
		$this->require_capability();
		check_admin_referer( 'send4_start_connect' );

		$client       = new Send4_Api_Client();
		$registration = $client->register_site();

		if ( is_wp_error( $registration ) ) {
			$this->redirect_with_notice( 'error', $registration->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		Send4_Settings::update_connection(
			array(
				'connect_id'     => $registration['connect_id'],
				'connect_secret' => $registration['connect_secret'],
			)
		);

		// Off to Send4 to approve. wp_redirect rather than wp_safe_redirect:
		// the whole point is to leave this site. The destination is not taken
		// on faith, though — register_site() has already refused anything that
		// is not a Send4 or development address, because the API is trusted
		// with the submissions, not with where the administrator's browser
		// ends up.
		wp_redirect( $registration['authorize_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Finish a connection that Send4 just approved.
	 *
	 * Always redirects. See maybe_complete_connect(), which is the only caller
	 * and where the capability check and the reasoning about nonces live.
	 *
	 * @param string $code       The one-time code from the redirect.
	 * @param string $connect_id The connection the code belongs to.
	 * @return void
	 */
	private function complete_connect( $code, $connect_id ) {
		$connection = Send4_Settings::connection();

		if ( '' === $connection['connect_id'] || $connection['connect_id'] !== $connect_id ) {
			$this->redirect_with_notice(
				'error',
				__( 'That connection did not start here. Try connecting again.', 'send4' ),
				array(),
				self::CONNECTION_SLUG
			);
		}

		$client      = new Send4_Api_Client();
		$credentials = $client->exchange_code( $connection['connect_id'], $connection['connect_secret'], $code );

		if ( is_wp_error( $credentials ) ) {
			$this->redirect_with_notice( 'error', $credentials->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		Send4_Settings::update_connection(
			array(
				// One-shot: keeping them would only ever be a replay target.
				'connect_id'     => '',
				'connect_secret' => '',
			)
		);

		$this->confirm_connection( $credentials['client_id'], $credentials['client_secret'] );
	}

	/**
	 * Re-run the handshake.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->require_capability();
		check_admin_referer( 'send4_test' );

		$result = $this->handshake( true );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		$flows = isset( $result['flows'] ) ? count( $result['flows'] ) : 0;

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: Send4 account name, 2: number of flows. */
				_n(
					'Connected to %1$s. %2$d flow is listening for WordPress forms.',
					'Connected to %1$s. %2$d flows are listening for WordPress forms.',
					$flows,
					'send4'
				),
				isset( $result['client']['name'] ) ? $result['client']['name'] : '',
				$flows
			),
			array(),
			self::CONNECTION_SLUG
		);
	}

	/**
	 * Forget the credentials.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->require_capability();
		check_admin_referer( 'send4_disconnect' );

		// Before the credentials go: rows queued before rows carried a client
		// id must be bound to this connection, or a reconnect to a different
		// workspace would send them there as new leads.
		if ( Send4_Queue::table_exists() ) {
			Send4_Queue::adopt_unowned( Send4_Queue::current_client_id() );
		}

		Send4_Settings::disconnect();
		self::forget_handshake();

		// The next connection may be a different workspace, which has never been
		// told anything about this site's forms — so the next push must be
		// unconditional rather than skipped as "unchanged".
		Send4_Forms_Sync::forget();

		$this->redirect_with_notice( 'success', __( 'Disconnected from Send4. Your field mappings were kept.', 'send4' ), array(), self::CONNECTION_SLUG );
	}

	/**
	 * Save one form's mapping, and enable or disable it.
	 *
	 * @return void
	 */
	public function handle_save_mapping() {
		$this->require_capability();
		check_admin_referer( 'send4_save_mapping' );

		$form_key = isset( $_POST['form_key'] ) ? sanitize_text_field( wp_unslash( $_POST['form_key'] ) ) : '';

		// Looked up once: detection reads every form plugin, and this used to
		// do it twice before the save had even started.
		$descriptor = '' === $form_key ? null : $this->find_form( $form_key );

		if ( null === $descriptor ) {
			$this->redirect_with_notice( 'error', __( 'That form no longer exists.', 'send4' ), array(), self::FORMS_SLUG );
		}

		$mapping = array(
			'phone' => isset( $_POST['map_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['map_phone'] ) ) : '',
			'name'  => isset( $_POST['map_name'] ) ? sanitize_text_field( wp_unslash( $_POST['map_name'] ) ) : '',
			'email' => isset( $_POST['map_email'] ) ? sanitize_text_field( wp_unslash( $_POST['map_email'] ) ) : '',
		);

		$valid_ids = wp_list_pluck( $descriptor['fields'], 'id' );

		foreach ( $mapping as $role => $field_id ) {
			if ( '' !== $field_id && ! in_array( $field_id, $valid_ids, true ) ) {
				$mapping[ $role ] = '';
			}
		}

		$enable = ! empty( $_POST['enabled'] );

		// Phone checks (#0688). Saved on BOTH paths below: the enable-without-
		// phone path redirects early, and dropping the operator's ticks there
		// would silently switch validation off on their next save.
		$default_country = isset( $_POST['phone_default_country'] ) ? sanitize_key( wp_unslash( $_POST['phone_default_country'] ) ) : '';
		$phone_checks    = array(
			'validate_phone' => ! empty( $_POST['validate_phone'] ),
			'phone_field'    => array(
				'picker'          => ! empty( $_POST['phone_picker'] ),
				'default_country' => Send4_Phone_Field::is_country( $default_country ) ? $default_country : '',
			),
		);

		if ( $enable && '' === $mapping['phone'] ) {
			Send4_Settings::update_form(
				$form_key,
				array_merge(
					array(
						'mapping' => $mapping,
						'name'    => $descriptor['title'],
					),
					$phone_checks
				)
			);

			$this->redirect_with_notice(
				'error',
				__( 'Pick which field holds the phone number before enabling this form — Send4 follows up by SMS, so a submission without a phone number has nobody to reach.', 'send4' ),
				array( 'form' => $form_key ),
				self::FORMS_SLUG
			);
		}

		Send4_Settings::update_form(
			$form_key,
			array_merge(
				array(
					'enabled' => $enable,
					'mapping' => $mapping,
					'name'    => $descriptor['title'],
				),
				$phone_checks
			)
		);

		// The mapping decides which fields land in `lead.*` and which keep a
		// top-level key, so saving one changes the descriptors Send4 holds.
		self::defer_forms_sync();

		$this->redirect_with_notice(
			'success',
			$enable ? __( 'Form saved and enabled.', 'send4' ) : __( 'Form saved. It is not sending to Send4.', 'send4' ),
			array(),
			self::FORMS_SLUG
		);
	}

	/**
	 * Turn one form off from the list screen.
	 *
	 * @return void
	 */
	public function handle_disable_form() {
		$this->require_capability();
		check_admin_referer( 'send4_disable_form' );

		$form_key = isset( $_POST['form_key'] ) ? sanitize_text_field( wp_unslash( $_POST['form_key'] ) ) : '';

		// update_form() creates the entry it is asked for, so an unchecked key
		// would leave a junk form behind that nothing ever prunes. A form that
		// is stored but not detected right now (its plugin is deactivated) can
		// still be switched off.
		if ( '' === $form_key || ( null === $this->find_form( $form_key ) && ! Send4_Settings::has_form( $form_key ) ) ) {
			$this->redirect_with_notice( 'error', __( 'That form no longer exists.', 'send4' ), array(), self::FORMS_SLUG );
		}

		Send4_Settings::update_form( $form_key, array( 'enabled' => false ) );
		self::defer_forms_sync();

		$this->redirect_with_notice( 'success', __( 'Form disabled.', 'send4' ), array(), self::FORMS_SLUG );
	}

	/**
	 * Report the forms to Send4 shortly, rather than while the admin waits.
	 *
	 * Saving a mapping or turning a form off used to push inline, before the
	 * redirect, so during an outage the Save button hung for the whole
	 * timeout — twice over on a path that signs in again — for metadata whose
	 * failure is only ever logged. The push now runs on the next cron tick,
	 * which wp-admin's own next page load spawns. It reads the settings when
	 * it runs, so several saves in a row still send the latest state, and a
	 * push that does not land is retried by the daily sweep as before.
	 *
	 * Same hook and no arguments as the daily sweep, so deactivation's
	 * unschedule() clears this too.
	 *
	 * @return void
	 */
	private static function defer_forms_sync() {
		wp_schedule_single_event( time(), Send4_Forms_Sync::HOOK );
	}

	/**
	 * Report this site's forms to Send4 now.
	 *
	 * The escape hatch for the gap the automatic triggers cannot close: a form
	 * created, renamed or deleted in the CMS fires no hook this plugin sees, so
	 * without this the list is only as fresh as the daily sweep.
	 *
	 * @return void
	 */
	public function handle_sync_forms() {
		$this->require_capability();
		check_admin_referer( 'send4_sync_forms' );

		$result = Send4_Forms_Sync::maybe_push( true );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), array(), self::FORMS_SLUG );
		}

		if ( null === $result ) {
			$this->redirect_with_notice(
				'error',
				__( 'Connect this site to Send4 before syncing its forms.', 'send4' ),
				array(),
				self::FORMS_SLUG
			);
		}

		$this->redirect_with_notice(
			'success',
			__( 'Your forms were sent to Send4. They are now selectable on a WordPress trigger.', 'send4' ),
			array(),
			self::FORMS_SLUG
		);
	}

	/**
	 * Drain the retry queue now.
	 *
	 * @return void
	 */
	public function handle_run_queue() {
		$this->require_capability();
		check_admin_referer( 'send4_run_queue' );

		$summary = Send4_Delivery::process_queue();

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: attempted count, 2: delivered count. */
				_n(
					'Retried %1$s queued submission; %2$s delivered.',
					'Retried %1$s queued submissions; %2$s delivered.',
					(int) $summary['attempted'],
					'send4'
				),
				number_format_i18n( (int) $summary['attempted'] ),
				number_format_i18n( (int) $summary['delivered'] )
			)
		);
	}

	/**
	 * Empty the activity log.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		$this->require_capability();
		check_admin_referer( 'send4_clear_log' );

		Send4_Logger::clear();

		$this->redirect_with_notice( 'success', __( 'Activity log cleared.', 'send4' ) );
	}

	/**
	 * Put dead-lettered submissions back on the queue.
	 *
	 * For after the cause has been fixed — an ability ticked, a key replaced,
	 * the destination corrected — which the plugin has no way to notice.
	 *
	 * @return void
	 */
	public function handle_requeue() {
		$this->require_capability();
		check_admin_referer( 'send4_requeue' );

		if ( ! Send4_Settings::has_credentials() ) {
			$this->redirect_with_notice( 'error', __( 'Connect this site to Send4 before resending failed submissions.', 'send4' ) );
		}

		$moved = Send4_Delivery::requeue();

		if ( 0 === $moved ) {
			$this->redirect_with_notice( 'success', __( 'There were no failed submissions for this Send4 account to resend.', 'send4' ) );
		}

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %d: number of submissions. */
				_n( '%d failed submission will be resent within a minute.', '%d failed submissions will be resent within a minute.', $moved, 'send4' ),
				$moved
			)
		);
	}

	/**
	 * The capability check every admin-post handler makes first.
	 *
	 * Each handler then calls check_admin_referer() itself, on the next line.
	 * That check used to live in here too, and the nonce was always verified,
	 * but Plugin Check only sees a nonce check written in the same function
	 * that reads $_POST, so a wrapper reads to it, and to a reviewer, as none.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to configure Send4.', 'send4' ) );
		}
	}

	/**
	 * Redirect back to one of our screens carrying a notice.
	 *
	 * The text travels in a short-lived transient keyed to the current user —
	 * the arrangement core uses for settings_errors — and the URL carries only
	 * a marker. Carrying the text in the URL, as this used to, meant anybody
	 * could link an administrator to a Send4 screen showing a notice of their
	 * choosing, in the plugin's own voice; escaping stops markup, not lies.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @param array  $args    Extra query args.
	 * @param string $slug    Page slug to land on.
	 * @return void
	 */
	private function redirect_with_notice( $type, $message, array $args = array(), $slug = self::PAGE_SLUG ) {
		$type = 'success' === $type ? 'success' : 'error';

		set_transient(
			self::notice_key(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			self::NOTICE_CACHE_S
		);

		$url = $this->page_url( array_merge( $args, array( 'send4_notice' => $type ) ), $slug );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The notice carried on the current request, if any.
	 *
	 * One-shot: reading it removes it, so a reload does not repeat an outcome
	 * that is no longer news.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public function current_notice() {
		// The marker only says a notice may be waiting; nothing in the URL is
		// rendered. A marker with nothing stored behind it — a forged link, or
		// one older than the transient — shows nothing at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['send4_notice'] ) ) {
			return null;
		}

		$notice = get_transient( self::notice_key() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) || ! is_string( $notice['message'] ) ) {
			return null;
		}

		delete_transient( self::notice_key() );

		return array(
			'type'    => isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error',
			'message' => $notice['message'],
		);
	}

	/**
	 * Where the current user's pending notice is kept.
	 *
	 * Per user, so two administrators cannot read each other's outcomes; per
	 * site by virtue of being a transient.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return self::NOTICE_PREFIX . get_current_user_id();
	}

	/**
	 * Human readable label for a stored delivery status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public function status_label( $status ) {
		switch ( $status ) {
			case 'delivered':
				return __( 'Delivered', 'send4' );
			case 'duplicate':
				return __( 'Already received', 'send4' );
			case 'unrouted':
				return __( 'Not connected to a flow', 'send4' );
			case 'queued':
				return __( 'Queued for retry', 'send4' );
			case 'failed':
				return __( 'Failed', 'send4' );
			default:
				return __( 'No submissions yet', 'send4' );
		}
	}

	/**
	 * Which badge tone a stored delivery status deserves.
	 *
	 * @param string $status Stored status.
	 * @return string One of ok|info|warn|bad|neutral.
	 */
	public function status_tone( $status ) {
		switch ( $status ) {
			case 'delivered':
				return 'ok';
			case 'duplicate':
				return 'info';
			case 'unrouted':
			case 'queued':
				return 'warn';
			case 'failed':
				return 'bad';
			default:
				return 'neutral';
		}
	}

	/**
	 * A translated label for a flow status Send4 reports.
	 *
	 * The API answers in its own English keywords. The known ones are named in
	 * the admin's language; anything newer is shown as sent rather than hidden.
	 *
	 * @param string $status Flow status from the handshake.
	 * @return string
	 */
	public function flow_status_label( $status ) {
		switch ( (string) $status ) {
			case '':
				return __( 'Unknown', 'send4' );
			case 'active':
				return _x( 'Active', 'flow status', 'send4' );
			case 'inactive':
				return _x( 'Inactive', 'flow status', 'send4' );
			case 'draft':
				return _x( 'Draft', 'flow status', 'send4' );
			case 'paused':
				return _x( 'Paused', 'flow status', 'send4' );
			case 'archived':
				return _x( 'Archived', 'flow status', 'send4' );
			default:
				return (string) $status;
		}
	}

	/**
	 * A stored UTC timestamp in the site's own date and time format and zone.
	 *
	 * @param string $utc Timestamp as stored, Y-m-d H:i:s in UTC.
	 * @return string Empty when there is nothing readable to show.
	 */
	public function format_utc( $utc ) {
		$stamp = '' === (string) $utc ? false : strtotime( $utc . ' UTC' );

		if ( false === $stamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp );
	}

	/**
	 * Print a nonce for one of the several small forms on a screen.
	 *
	 * Core's wp_nonce_field() prints id="_wpnonce" — and a referer field with an id
	 * of its own — every time it is called, so a screen with more than one
	 * form ends up with duplicate ids. check_admin_referer() needs only the
	 * field's name, and the handlers redirect to a fixed screen rather than to
	 * the referer.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	public function nonce_field( $action ) {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}
}
