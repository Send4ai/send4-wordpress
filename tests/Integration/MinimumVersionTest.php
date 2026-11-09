<?php
/**
 * Asking an admin to update when Send4 needs a newer plugin.
 *
 * @package Send4
 */

/**
 * WordPress.org cannot force an update, so the minimum version is the only way
 * left to ask (#1531). The notice has to reach someone who can act on it, stay
 * where guideline 11 allows it, go away when dismissed, and never stand
 * between a visitor's form and Send4.
 */
class Send4_Minimum_Version_Test extends Send4_Test_Case {

	/**
	 * An administrator who can update plugins, on any network shape.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Start every test with nothing stored and nobody dismissed.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Send4_Minimum_Version::OPTION );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// On a network only a super admin may update plugins.
		if ( is_multisite() ) {
			grant_super_admin( $this->admin_id );
		}

		wp_set_current_user( $this->admin_id );
		set_current_screen( 'plugins' );
	}

	/**
	 * Undo the screen and the stored answer.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Send4_Minimum_Version::OPTION );
		set_current_screen( 'front' );
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Store what a handshake would have said.
	 *
	 * @param string $minimum Minimum version.
	 * @param string $message Message, '' for none.
	 * @return void
	 */
	private function requireVersion( $minimum, $message = 'Send4 needs version 9.0.0 or newer of this plugin.' ) {
		$plugin = array( 'minimum_version' => $minimum );

		if ( '' !== $message ) {
			$plugin['message'] = $message;
		}

		Send4_Minimum_Version::remember(
			array(
				'client' => array( 'name' => 'Stogas UAB' ),
				'flows'  => array(),
				'plugin' => $plugin,
			)
		);
	}

	/**
	 * What the notice hook prints.
	 *
	 * @return string
	 */
	private function notice() {
		ob_start();
		Send4_Minimum_Version::render();

		return (string) ob_get_clean();
	}

	public function test_a_site_below_the_minimum_is_asked_to_update() {
		$this->requireVersion( '9.0.0' );

		$html = $this->notice();

		$this->assertStringContainsString( 'Send4 needs version 9.0.0 or newer of this plugin.', $html );
		$this->assertStringContainsString( 'update-core.php', $html, 'The notice links to where the update is.' );
		$this->assertStringContainsString( Send4_Minimum_Version::DISMISS_ACTION, $html );
	}

	public function test_a_site_at_the_minimum_is_not_asked() {
		$this->requireVersion( SEND4_VERSION );

		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Send4's default minimum is 0.0.0: nobody is asked anything.
	 *
	 * @return void
	 */
	public function test_the_default_minimum_asks_nobody() {
		$this->requireVersion( '0.0.0' );

		$this->assertNull( Send4_Minimum_Version::unmet() );
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * A Send4 that sends no minimum (or stopped sending one) must not leave an
	 * old one nagging.
	 *
	 * @return void
	 */
	public function test_a_handshake_without_a_minimum_forgets_the_stored_one() {
		$this->requireVersion( '9.0.0' );

		Send4_Minimum_Version::remember(
			array(
				'client' => array( 'name' => 'Stogas UAB' ),
				'flows'  => array(),
				'plugin' => null,
			)
		);

		$this->assertSame( '', Send4_Minimum_Version::stored()['minimum_version'] );
		$this->assertSame( '', Send4_Minimum_Version::stored()['message'] );
		$this->assertNull( Send4_Minimum_Version::unmet() );
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Whatever arrives is only a version if it looks like one.
	 *
	 * @return void
	 */
	public function test_an_unreadable_minimum_is_ignored() {
		$this->requireVersion( '<script>' );

		$this->assertSame( '', Send4_Minimum_Version::stored()['minimum_version'] );
		$this->assertSame( '', Send4_Minimum_Version::stored()['message'], 'A message without a minimum is never kept.' );
		$this->assertNull( Send4_Minimum_Version::unmet() );
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Disconnecting ends the notice: an update would change nothing for a site
	 * with no connection, and the daily refresh that could clear it stops.
	 *
	 * @return void
	 */
	public function test_disconnecting_ends_the_notice_and_forgets_the_minimum() {
		$this->requireVersion( '9.0.0' );
		$this->assertNotSame( '', $this->notice() );

		Send4_Settings::disconnect();

		$this->assertSame( '', $this->notice() );
		$this->assertFalse( get_option( Send4_Minimum_Version::OPTION, false ) );
	}

	/**
	 * However the credentials went, a site without them shows no notice.
	 *
	 * @return void
	 */
	public function test_a_site_without_credentials_shows_no_notice() {
		$this->requireVersion( '9.0.0' );

		Send4_Settings::update_connection( array( 'client_secret' => '' ) );

		$this->assertSame( '9.0.0', Send4_Minimum_Version::unmet() );
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Send4's message is product copy, shown as text: no markup survives.
	 *
	 * @return void
	 */
	public function test_the_message_is_shown_as_plain_text() {
		$this->requireVersion( '9.0.0', 'Update <a href="https://evil.example">here</a> <script>alert(1)</script>now.' );

		$html = $this->notice();

		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Update here now.', $html );
	}

	/**
	 * Without Send4's message the plugin says it in the admin's own language.
	 *
	 * @return void
	 */
	public function test_without_a_message_the_plugin_uses_its_own_words() {
		$this->requireVersion( '9.0.0', '' );

		$this->assertStringContainsString( 'Send4 needs version 9.0.0 or newer of this plugin.', $this->notice() );
	}

	/**
	 * Guideline 11: the Plugins screen and our own screens, nowhere else.
	 *
	 * @return void
	 */
	public function test_it_shows_only_on_the_plugins_screen_and_ours() {
		$this->requireVersion( '9.0.0' );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->notice(), 'Not on the WordPress dashboard.' );

		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->notice(), 'Not on the posts list.' );

		set_current_screen( 'toplevel_page_send4' );
		$this->assertNotSame( '', $this->notice(), 'On the Send4 dashboard.' );

		// The prefix is built from the translated menu title, so only the
		// slug at the end can be relied on.
		set_current_screen( 'sendketuri_page_send4-forms' );
		$this->assertNotSame( '', $this->notice(), 'On the forms screen in another language.' );

		set_current_screen( 'toplevel_page_send4-lookalike' );
		$this->assertSame( '', $this->notice(), 'Not on a screen that merely starts like ours.' );
	}

	/**
	 * The notice asks for an update, so it goes to whoever can do one.
	 *
	 * @return void
	 */
	public function test_someone_who_cannot_update_plugins_is_not_asked() {
		$this->requireVersion( '9.0.0' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Dismissing hides it for that person until the minimum rises again.
	 *
	 * @return void
	 */
	public function test_dismissing_lasts_until_the_minimum_rises() {
		$this->requireVersion( '9.0.0' );

		$this->dismiss();

		$this->assertSame( '', $this->notice(), 'Dismissed for 9.0.0.' );

		$this->requireVersion( '9.1.0', 'Send4 needs version 9.1.0 or newer of this plugin.' );

		$this->assertStringContainsString( '9.1.0', $this->notice(), 'A higher minimum is news again.' );
	}

	/**
	 * One admin dismissing it does not hide it from the others.
	 *
	 * @return void
	 */
	public function test_dismissing_is_per_person() {
		$this->requireVersion( '9.0.0' );

		$this->dismiss();

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $other );
		}

		wp_set_current_user( $other );

		$this->assertNotSame( '', $this->notice() );
	}

	/**
	 * The Dismiss link carries a nonce, and a request without it does nothing.
	 *
	 * @return void
	 */
	public function test_dismissing_needs_the_nonce() {
		$this->requireVersion( '9.0.0' );

		$_REQUEST['_wpnonce'] = 'not-the-nonce';

		try {
			Send4_Minimum_Version::handle_dismiss();
			$this->fail( 'A forged dismissal must not get through.' );
		} catch ( WPDieException $refused ) {
			$this->assertSame( '', (string) get_user_meta( $this->admin_id, Send4_Minimum_Version::DISMISSED_META, true ) );
		}
	}

	/**
	 * A minimum is a notice and never a gate: an old plugin keeps sending.
	 *
	 * @return void
	 */
	public function test_a_site_below_the_minimum_still_sends_its_leads() {
		$this->requireVersion( '9.0.0' );

		$this->http->queueToken();
		$this->http->queue202();

		$result = ( new Send4_Api_Client() )->submit(
			array(
				'submission_id' => 'fake-forms:1:1:abc',
				'site_url'      => home_url( '/' ),
				'form'          => array(
					'key'  => 'fake-forms:1',
					'name' => 'Roof quote',
				),
				'lead'          => array( 'phone' => '+37060012345' ),
				'data'          => array(),
			)
		);

		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
		$this->assertSame( 202, $result->code );
	}

	/**
	 * Any successful handshake updates what is stored, the screens' included.
	 *
	 * @return void
	 */
	public function test_a_handshake_from_the_screens_stores_the_minimum() {
		$this->http->queueToken();
		$this->http->queueHandshake(
			array(),
			'Stogas UAB',
			array(
				'plugin' => array(
					'minimum_version' => '9.0.0',
					'message'         => 'Update, please.',
				),
			)
		);

		$this->assertIsArray( ( new Send4_Admin() )->handshake( true ) );

		$this->assertSame( '9.0.0', Send4_Minimum_Version::stored()['minimum_version'] );
		$this->assertSame( 'Update, please.', Send4_Minimum_Version::stored()['message'] );
	}

	/**
	 * The daily event asks again once the stored answer is a day old, so a
	 * site whose admins never open the Send4 screens still hears about it.
	 *
	 * @return void
	 */
	public function test_the_daily_refresh_asks_when_the_answer_is_stale() {
		$this->http->queueToken();
		$this->http->queueHandshake( array(), 'Stogas UAB', array( 'plugin' => array( 'minimum_version' => '9.0.0' ) ) );

		Send4_Minimum_Version::refresh_if_stale();

		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/handshake' ) );
		$this->assertSame( '9.0.0', Send4_Minimum_Version::stored()['minimum_version'] );
	}

	/**
	 * And leaves a fresh answer alone: no request at all.
	 *
	 * @return void
	 */
	public function test_the_daily_refresh_leaves_a_fresh_answer_alone() {
		$this->requireVersion( '9.0.0' );

		Send4_Minimum_Version::refresh_if_stale();

		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * A Send4 that asks for no minimum (today's production) still counts as
	 * asked: the daily refresh makes one handshake a day, not one per run.
	 *
	 * @return void
	 */
	public function test_an_answer_without_a_minimum_is_not_asked_for_again_the_same_day() {
		$this->http->queueToken();
		$this->http->queueHandshake( array(), 'Stogas UAB' );

		Send4_Minimum_Version::refresh_if_stale();
		Send4_Minimum_Version::refresh_if_stale();

		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/handshake' ) );
		$this->assertSame( '', Send4_Minimum_Version::stored()['minimum_version'] );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, Send4_Minimum_Version::stored()['checked_at'] );
	}

	/**
	 * An outage is not news about the minimum.
	 *
	 * @return void
	 */
	public function test_a_failed_refresh_keeps_the_stored_answer() {
		$this->requireVersion( '9.0.0' );

		$stored               = get_option( Send4_Minimum_Version::OPTION );
		$stored['checked_at'] = time() - DAY_IN_SECONDS;
		update_option( Send4_Minimum_Version::OPTION, $stored, false );

		$this->http->fallback( new WP_Error( 'http_request_failed', 'offline' ) );

		Send4_Minimum_Version::refresh_if_stale();

		$this->assertSame( '9.0.0', Send4_Minimum_Version::stored()['minimum_version'] );
	}

	/**
	 * A site that never connected makes no request (guideline 7).
	 *
	 * @return void
	 */
	public function test_a_disconnected_site_never_asks() {
		Send4_Settings::disconnect();

		Send4_Minimum_Version::refresh_if_stale();

		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * Run the Dismiss handler with a valid nonce.
	 *
	 * @return void
	 */
	private function dismiss() {
		$_REQUEST['_wpnonce'] = wp_create_nonce( Send4_Minimum_Version::DISMISS_ACTION );

		$spy = static function ( $location ) {
			throw new Send4_Minimum_Version_Redirected( (string) $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			Send4_Minimum_Version::handle_dismiss();
			$this->fail( 'Dismissing should redirect back.' );
		} catch ( Send4_Minimum_Version_Redirected $redirect ) {
			$this->assertNotSame( '', $redirect->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}
	}
}

/**
 * Thrown in place of the exit that follows the Dismiss handler's redirect.
 */
class Send4_Minimum_Version_Redirected extends Exception {}
