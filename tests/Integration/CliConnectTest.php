<?php
/**
 * `wp send4 connect`.
 *
 * @package Send4
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * The CLI connects exactly as the Connection screen does: the credential is
 * proven before it is stored, and the forms go to the workspace straight away.
 */
class Send4_Cli_Connect_Test extends Send4_Test_Case {

	/**
	 * One detectable form and a clean output log.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$output = array();

		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);
	}

	/**
	 * Run the command, returning whether it halted.
	 *
	 * @param array $flags Flags.
	 * @return bool
	 */
	private function connect( array $flags ) {
		try {
			( new Send4_Cli() )->connect( array(), $flags );

			return false;
		} catch ( Send4_Cli_Halted $halted ) {
			return true;
		}
	}

	public function test_a_refused_credential_is_not_stored() {
		$this->http->queueTokenRejected( 401 );

		$this->assertTrue(
			$this->connect(
				array(
					'client-id'     => 'typo-id',
					'client-secret' => 'typo-secret',
				)
			)
		);

		$this->assertSame( 'test-client-id', Send4_Settings::connection()['client_id'] );
		$this->assertStringContainsString( 'still using the ones it had', WP_CLI::$output[0][1] );
	}

	/**
	 * --url-base used to be saved before the credentials were tried. A typo in
	 * the secret then left the site's working credentials pointed at the other
	 * environment: nothing was "saved", and the connection was broken anyway.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_leaves_the_destination_alone() {
		$this->http->queueTokenRejected( 401 );

		$this->assertTrue(
			$this->connect(
				array(
					'client-id'     => 'typo-id',
					'client-secret' => 'typo-secret',
					'url-base'      => 'https://staging.send4.ai',
				)
			)
		);

		$this->assertFalse( get_option( Send4_Settings::BASE_URL_OPTION, false ), 'The destination must not move unless the connection does.' );
		$this->assertSame( Send4_Settings::DEFAULT_BASE_URL, Send4_Settings::base_url() );
		$this->assertSame( 'test-client-id', Send4_Settings::connection()['client_id'] );

		// And the pair was tried where it was meant to be used, not against
		// the destination the site already had.
		$this->assertSame( 1, $this->http->requestCount() );
		$this->assertSame( 'https://staging.send4.ai/api/v2/auth/token', $this->http->requests()[0]['url'] );
	}

	/**
	 * When the pair is accepted, the destination it was proven against is saved
	 * with it, and everything after goes there.
	 *
	 * @return void
	 */
	public function test_a_new_destination_is_saved_with_the_credentials_proven_against_it() {
		$this->http->queueToken();
		$this->http->queueHandshake( array(), 'Staging Workspace' );
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );

		$this->assertFalse(
			$this->connect(
				array(
					'client-id'     => 'staging-id',
					'client-secret' => 'staging-secret',
					'url-base'      => 'https://staging.send4.ai/',
				)
			)
		);

		$this->assertSame( 'https://staging.send4.ai', Send4_Settings::base_url() );
		$this->assertSame( 'staging-id', Send4_Settings::connection()['client_id'] );
		$this->assertSame( 4, $this->http->requestCount() );

		foreach ( $this->http->requests() as $request ) {
			$this->assertStringStartsWith( 'https://staging.send4.ai/api/', $request['url'] );
		}
	}

	/**
	 * The admin path always did this; the CLI left a newly connected
	 * workspace with no form list for up to a day.
	 *
	 * @return void
	 */
	public function test_connecting_reports_the_forms_and_refreshes_the_screens() {
		update_option( Send4_Forms_Sync::HASH_OPTION, 'hash-from-the-old-workspace', false );
		update_option( Send4_Forms_Sync::AT_OPTION, time(), false );
		set_transient( Send4_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Old Workspace' ), 'flows' => array() ), 300 );

		$this->http->queueToken();
		$this->http->queueHandshake( array(), 'New Workspace' );
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );

		$this->assertFalse(
			$this->connect(
				array(
					'client-id'     => 'new-id',
					'client-secret' => 'new-secret',
				)
			)
		);

		$this->assertSame( 'new-id', Send4_Settings::connection()['client_id'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/forms' ) );
		$this->assertSame( 'New Workspace', Send4_Admin::cached_handshake()['client']['name'] );
		$this->assertSame( 'success', WP_CLI::$output[0][0] );
	}
}
