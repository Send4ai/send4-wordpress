<?php
/**
 * The admin screens while Send4 is down, and connecting without losing a
 * working credential.
 *
 * @package Send4
 */

/**
 * During an outage the screens must stay fast, say what is actually happening
 * to submissions, and keep the way out (Test connection) in reach. And a new
 * credential must prove itself before it replaces one that works.
 */
class Send4_Admin_Outage_Test extends Send4_Test_Case {

	/**
	 * Run as an administrator, with one detectable form.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);

		Send4_Forms_Sync::unschedule();
		Send4_Forms_Sync::forget();
	}

	/**
	 * Undo the request globals a handler test sets.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Run an admin-post handler as wp-admin would, with a valid nonce, and
	 * report where it redirected.
	 *
	 * @param string $action The admin-post action, also the nonce action.
	 * @param array  $post   Posted fields.
	 * @return string|null
	 */
	private function post( $action, array $post = array() ) {
		$_POST                = $post;
		$_REQUEST             = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		$admin  = new Send4_Admin();
		$method = 'handle_' . preg_replace( '/^send4_/', '', $action );

		return $this->captureRedirect(
			static function () use ( $admin, $method ) {
				$admin->$method();
			}
		);
	}

	/**
	 * Run something and report where it tried to redirect to.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function captureRedirect( callable $run ) {
		$spy = static function ( $location ) {
			throw new Send4_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$run();

			return null;
		} catch ( Send4_Redirected $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}
	}

	/**
	 * The page= a redirect lands on.
	 *
	 * @param string|null $location Redirect target.
	 * @return string
	 */
	private function pageOf( $location ) {
		$this->assertNotNull( $location, 'Expected a redirect.' );

		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return isset( $query['page'] ) ? (string) $query['page'] : '';
	}

	/**
	 * A screen's markup.
	 *
	 * @param string $method render_dashboard|render_forms|render_connection.
	 * @return string
	 */
	private function render( $method ) {
		$admin = new Send4_Admin();

		ob_start();

		try {
			$admin->$method();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Send4 answering nothing at all, however many times it is asked.
	 *
	 * @return void
	 */
	private function sendFourIsDown() {
		$this->http->fallback( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	// ── The handshake cache ─────────────────────────────────────────────────

	/**
	 * Every screen used to ask again during an outage and wait out the
	 * timeout before it could draw.
	 *
	 * @return void
	 */
	public function test_a_failed_handshake_is_remembered_for_a_minute() {
		$this->http->queueTimeout();

		$admin = new Send4_Admin();

		$this->assertWPError( $admin->handshake( false ) );
		$this->assertSame( 1, $this->http->requestCount() );

		// Nothing queued: another request would fail the recorder anyway, but
		// the count says it outright.
		$again = $admin->handshake( false );

		$this->assertWPError( $again );
		$this->assertSame( 'http_request_failed', $again->get_error_code() );
		$this->assertSame( 1, $this->http->requestCount(), 'A cached failure must not be asked again.' );

		$expires = (int) get_option( '_transient_timeout_' . Send4_Admin::HANDSHAKE_CACHE );
		$this->assertLessThanOrEqual( time() + Send4_Admin::HANDSHAKE_FAILURE_S, $expires );
	}

	/**
	 * The error's details survive the cache, since the screens classify on
	 * them.
	 *
	 * @return void
	 */
	public function test_a_remembered_failure_keeps_its_status() {
		$this->http->queueTokenRejected( 401 );

		( new Send4_Admin() )->handshake( false );

		$cached = Send4_Admin::cached_handshake();

		$this->assertWPError( $cached );
		$this->assertSame( 'send4_token_rejected', $cached->get_error_code() );
		$this->assertSame( 'rejected', Send4_Admin::connection_state( $cached ) );
	}

	public function test_drawing_a_screen_waits_less_than_a_button_press() {
		$this->http->queueTimeout();

		( new Send4_Admin() )->handshake( false );

		$this->assertSame( Send4_Api_Client::TIMEOUT_SCREEN, $this->http->requests()[0]['args']['timeout'] );
		$this->assertLessThan( Send4_Api_Client::TIMEOUT_INTERACTIVE, Send4_Api_Client::TIMEOUT_SCREEN );
	}

	/**
	 * Test connection is the admin asking, so it neither trusts the cache nor
	 * gives up early.
	 *
	 * @return void
	 */
	public function test_test_connection_ignores_a_remembered_failure() {
		$this->http->queueTimeout();
		( new Send4_Admin() )->handshake( false );

		$this->http->reset();
		$this->http->queueToken();
		$this->http->queueHandshake();

		$destination = $this->post( 'send4_test' );

		$this->assertStringContainsString( 'send4_notice=success', $destination );
		$this->assertSame( Send4_Api_Client::TIMEOUT_INTERACTIVE, $this->http->requests()[0]['args']['timeout'] );
		$this->assertIsArray( Send4_Admin::cached_handshake(), 'The success replaces the remembered failure.' );
	}

	/**
	 * Otherwise the notice would say it failed while every screen still said
	 * "Connected" for up to five minutes.
	 *
	 * @return void
	 */
	public function test_a_failed_test_replaces_a_remembered_success() {
		set_transient( Send4_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Stogas UAB' ), 'flows' => array() ), 300 );
		$this->http->queueTimeout();

		$this->post( 'send4_test' );

		$this->assertWPError( Send4_Admin::cached_handshake() );
	}

	public function test_it_tells_an_outage_from_a_refusal() {
		$this->assertSame( 'unreachable', Send4_Admin::connection_state( new WP_Error( 'http_request_failed', 'timed out' ) ) );
		$this->assertSame( 'unreachable', Send4_Admin::connection_state( new WP_Error( 'send4_token_rejected', 'x', array( 'status' => 500 ) ) ) );
		$this->assertSame( 'unreachable', Send4_Admin::connection_state( new WP_Error( 'send4_token_rejected', 'x', array( 'status' => 429 ) ) ) );
		$this->assertSame( 'rejected', Send4_Admin::connection_state( new WP_Error( 'send4_token_rejected', 'x', array( 'status' => 401 ) ) ) );
		$this->assertSame( 'rejected', Send4_Admin::connection_state( new WP_Error( 'send4_missing_ability', 'x' ) ) );
		$this->assertSame( 'connected', Send4_Admin::connection_state( array( 'client' => array() ) ) );
		$this->assertSame( 'unchecked', Send4_Admin::connection_state( null ) );

		Send4_Settings::disconnect();

		$this->assertSame( 'disconnected', Send4_Admin::connection_state( new WP_Error( 'http_request_failed', 'timed out' ) ) );
	}

	// ── What the screens say during an outage ───────────────────────────────

	/**
	 * Submissions ARE being queued during an outage, so "not active yet" and
	 * "nothing is being sent" were both false.
	 *
	 * @return void
	 */
	public function test_the_dashboard_says_submissions_are_queued_not_that_nothing_is_set_up() {
		$this->sendFourIsDown();

		$html = $this->render( 'render_dashboard' );

		$this->assertStringContainsString( 'being queued', $html );
		$this->assertStringContainsString( 'cURL error 28', $html );
		$this->assertStringNotContainsString( 'not active yet', $html );
		$this->assertStringNotContainsString( 'Nothing is being sent', $html );
		$this->assertStringContainsString( 'Send4 unreachable', $html, 'The app bar pill says the same.' );
	}

	public function test_the_forms_screen_does_not_claim_the_site_is_unconnected() {
		$this->sendFourIsDown();

		$html = $this->render( 'render_forms' );

		$this->assertStringContainsString( 'being queued', $html );
		$this->assertStringNotContainsString( 'nothing is sent until the site is connected', $html );
	}

	/**
	 * The fix during an outage is to test again, not to connect again —
	 * connecting mints a new credential while the stored one may be fine.
	 *
	 * @return void
	 */
	public function test_the_connection_screen_keeps_test_and_disconnect_during_an_outage() {
		$this->sendFourIsDown();

		$html = $this->render( 'render_connection' );

		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
		$this->assertStringContainsString( 'cURL error 28', $html );
		$this->assertStringNotContainsString( 'Connect with Send4', $html );
		$this->assertStringNotContainsString( 'not active yet', $html );
	}

	/**
	 * A refused credential is a different story: submissions are refused too,
	 * and reconnecting is a real fix.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_says_nothing_is_delivered_and_offers_to_reconnect() {
		$this->http->fallback( Send4_Http_Recorder::response( 401, array( 'error_description' => 'The client credentials are invalid.' ) ) );

		$html = $this->render( 'render_connection' );

		$this->assertStringContainsString( 'not being delivered', $html );
		$this->assertStringNotContainsString( 'being queued', $html );
		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'Connect with Send4', $html );
	}

	/**
	 * The mapping screen only needs the pill, so it does not go to the network
	 * to draw it.
	 *
	 * @return void
	 */
	public function test_the_mapping_screen_does_not_wait_on_the_network() {
		$_GET['form'] = 'fake-forms:1';

		$html = $this->render( 'render_forms' );

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertStringContainsString( 'Fake form', $html );
	}

	public function test_several_forms_on_one_screen_do_not_repeat_an_id() {
		$this->http->fallback( Send4_Http_Recorder::response( 401, array() ) );
		Send4_Logger::log( 'info', 'Something happened.' );

		foreach ( array( 'render_dashboard', 'render_connection' ) as $screen ) {
			$html = $this->render( $screen );

			$this->assertLessThanOrEqual( 1, substr_count( $html, 'id="_wpnonce"' ), $screen . ' repeats id="_wpnonce".' );
			$this->assertLessThanOrEqual( 1, substr_count( $html, 'id="_wp_http_referer"' ), $screen . ' repeats id="_wp_http_referer".' );
		}
	}

	/**
	 * check_admin_referer() reads the field by name, so dropping the id must
	 * not cost the nonce.
	 *
	 * @return void
	 */
	public function test_the_id_less_nonce_still_verifies() {
		$admin = new Send4_Admin();

		ob_start();
		$admin->nonce_field( 'send4_test' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/value="([^"]+)"/', $html, $match );

		$this->assertNotFalse( wp_verify_nonce( $match[1], 'send4_test' ) );
	}

	// ── Connecting ──────────────────────────────────────────────────────────

	/**
	 * A mistyped secret used to replace the working one before the handshake
	 * ran, so every later submission was refused and dead-lettered.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_does_not_replace_the_working_one() {
		$this->http->queueTokenRejected( 401 );

		$destination = $this->post(
			'send4_connect',
			array(
				'client_id'     => 'typo-id',
				'client_secret' => 'secret_typo' . str_repeat( 'x', 44 ),
			)
		);

		$this->assertStringContainsString( 'send4_notice=error', $destination );

		$connection = Send4_Settings::connection();
		$this->assertSame( 'test-client-id', $connection['client_id'] );
		$this->assertSame( 'test-client-secret', $connection['client_secret'] );
		$this->assertSame( 'Stogas UAB', $connection['client_name'], 'The working connection is left exactly as it was.' );

		// And it really was the candidate that was tried.
		$this->assertSame( 'typo-id', $this->http->requests()[0]['body']['client_id'] );
	}

	/**
	 * The stored credential's cached token would make any candidate look valid.
	 *
	 * @return void
	 */
	public function test_a_candidate_signs_in_for_itself_rather_than_borrowing_the_cached_token() {
		set_transient(
			Send4_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'token-of-the-stored-credential',
				'expires_at' => time() + DAY_IN_SECONDS,
			),
			DAY_IN_SECONDS
		);
		$this->http->queueTokenRejected( 401 );

		$result = Send4_Admin::establish_connection( 'new-id', 'new-secret' );

		$this->assertWPError( $result );
		$this->assertStringEndsWith( '/api/v2/auth/token', $this->http->requests()[0]['url'] );
		$this->assertSame(
			'token-of-the-stored-credential',
			get_transient( Send4_Api_Client::TOKEN_TRANSIENT )['token'],
			'A failed candidate must not disturb the stored credential\'s token either.'
		);
	}

	public function test_a_proven_credential_is_stored_and_the_old_token_dropped() {
		set_transient(
			Send4_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'token-of-the-old-credential',
				'expires_at' => time() + DAY_IN_SECONDS,
			),
			DAY_IN_SECONDS
		);
		$this->http->queueToken( 'candidate-token' );
		$this->http->queueHandshake( array(), 'New Workspace' );
		// The forms report that follows a connect.
		$this->http->queueToken( 'stored-token' );
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );

		$result = Send4_Admin::establish_connection( 'new-id', 'new-secret' );

		$this->assertIsArray( $result );

		$connection = Send4_Settings::connection();
		$this->assertSame( 'new-id', $connection['client_id'] );
		$this->assertSame( 'new-secret', $connection['client_secret'] );
		$this->assertSame( 'New Workspace', $connection['client_name'] );

		$token = get_transient( Send4_Api_Client::TOKEN_TRANSIENT );
		$this->assertNotSame( 'token-of-the-old-credential', is_array( $token ) ? $token['token'] : '' );

		// The (possibly different) workspace is told about the forms at once.
		$reports = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 1, $reports );
		$this->assertSame( 'Bearer stored-token', $reports[0]['headers']['Authorization'] );
		$this->assertGreaterThan( 0, Send4_Forms_Sync::last_synced_at() );

		$this->assertSame( 'New Workspace', Send4_Admin::cached_handshake()['client']['name'] );
	}

	/**
	 * The Connect button's return leg goes through the same routine, so the
	 * one-shot pair is still dropped when the new credential fails.
	 *
	 * @return void
	 */
	public function test_a_failed_return_leg_keeps_the_old_credential_and_drops_the_one_shot_pair() {
		global $pagenow;

		Send4_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$pagenow                  = 'admin.php';
		$_GET['page']             = Send4_Admin::CONNECTION_SLUG;
		$_GET['send4_code']       = 'code-abc';
		$_GET['send4_connect_id'] = 'wpconnect_abc123';

		$this->http->queue(
			Send4_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'minted-id',
					'client_secret' => 'minted-secret',
				)
			)
		);
		$this->http->queueTimeout();

		$admin       = new Send4_Admin();
		$destination = $this->captureRedirect(
			static function () use ( $admin ) {
				$admin->maybe_complete_connect();
			}
		);

		$this->assertStringContainsString( 'send4_notice=error', $destination );
		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $destination ) );

		$connection = Send4_Settings::connection();
		$this->assertSame( 'test-client-id', $connection['client_id'] );
		$this->assertSame( '', $connection['connect_id'] );
	}

	// ── Where the Connection screen's buttons land ──────────────────────────

	/**
	 * These used to land on the Dashboard, away from the result and from the
	 * button to try again.
	 *
	 * @return void
	 */
	public function test_connection_actions_return_to_the_connection_screen() {
		$this->http->queueToken();
		$this->http->queueHandshake();
		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $this->post( 'send4_test' ) ), 'Test, succeeding' );

		$this->http->queueTimeout();
		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $this->post( 'send4_test' ) ), 'Test, failing' );

		$this->http->queueTokenRejected( 401 );
		$this->assertSame(
			Send4_Admin::CONNECTION_SLUG,
			$this->pageOf(
				$this->post(
					'send4_connect',
					array(
						'client_id'     => 'x',
						'client_secret' => 'secret_' . str_repeat( 'y', 48 ),
					)
				)
			),
			'Save credentials, failing'
		);

		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $this->post( 'send4_connect', array( 'client_id' => '' ) ) ), 'Save credentials, incomplete' );

		$this->http->queue500();
		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $this->post( 'send4_start_connect' ) ), 'Connect with Send4, failing' );

		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $this->pageOf( $this->post( 'send4_disconnect' ) ), 'Disconnect' );
	}

	// ── The forms sync is not waited on ─────────────────────────────────────

	/**
	 * Saving a mapping used to push the form list inline, so during an outage
	 * the Save button hung for the whole timeout.
	 *
	 * @return void
	 */
	public function test_saving_a_mapping_defers_the_forms_report() {
		$destination = $this->post(
			'send4_save_mapping',
			array(
				'form_key'  => 'fake-forms:1',
				'map_phone' => 'phone',
				'enabled'   => '1',
			)
		);

		$this->assertStringContainsString( 'send4_notice=success', $destination );
		$this->assertSame( 0, $this->http->requestCount(), 'Nothing may be sent while the admin waits.' );

		$next = wp_next_scheduled( Send4_Forms_Sync::HOOK );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( time(), $next, 'Due now, on the next cron tick.' );
	}

	public function test_turning_a_form_off_defers_the_forms_report() {
		$this->enableForm( 'fake-forms:1', array( 'phone' => 'phone' ) );

		$this->post( 'send4_disable_form', array( 'form_key' => 'fake-forms:1' ) );

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertNotFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}

	/**
	 * The deferred event is the one the cron callback already handles, and
	 * deactivation already clears.
	 *
	 * @return void
	 */
	public function test_the_deferred_report_is_sent_when_cron_runs_and_cleared_on_deactivation() {
		$this->post( 'send4_disable_form', array( 'form_key' => 'fake-forms:1' ) );

		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );

		do_action( Send4_Forms_Sync::HOOK );

		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/forms' ) );

		Send4_Forms_Sync::unschedule();
		$this->assertFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}

	// ── Small print ─────────────────────────────────────────────────────────

	public function test_known_flow_statuses_are_translated_and_unknown_ones_shown_as_sent() {
		$admin = new Send4_Admin();

		$filter = static function ( $translation, $text, $context, $domain ) {
			return 'send4' === $domain && 'Paused' === $text && 'flow status' === $context ? 'Pristabdytas' : $translation;
		};
		add_filter( 'gettext_with_context', $filter, 10, 4 );

		$this->assertSame( 'Pristabdytas', $admin->flow_status_label( 'paused' ) );
		$this->assertSame( 'something-new', $admin->flow_status_label( 'something-new' ) );

		remove_filter( 'gettext_with_context', $filter, 10 );
	}

	public function test_timestamps_are_shown_in_the_sites_own_format_and_zone() {
		update_option( 'timezone_string', 'Europe/Vilnius' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		// 12:00 UTC is 15:00 in Vilnius in summer.
		$this->assertSame( '2026-07-01 15:00', ( new Send4_Admin() )->format_utc( '2026-07-01 12:00:00' ) );
		$this->assertSame( '', ( new Send4_Admin() )->format_utc( '' ) );
	}
}
