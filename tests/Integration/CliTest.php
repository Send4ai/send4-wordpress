<?php
/**
 * The WP-CLI commands that touch the queue and the destination.
 *
 * @package Send4
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * `wp send4` is how the development stack configures
 * the plugin, so it gets the same guarantees as the settings screen.
 */
class Send4_Cli_Test extends Send4_Test_Case {

	/**
	 * Start every test with no recorded output.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$output = array();
	}

	/**
	 * The last thing a command printed.
	 *
	 * @return array{0:string,1:string}
	 */
	private function lastOutput() {
		$this->assertNotEmpty( WP_CLI::$output, 'The command printed nothing.' );

		return WP_CLI::$output[ count( WP_CLI::$output ) - 1 ];
	}

	/**
	 * A destination outside the allow-list is refused before anything is
	 * stored. The request layer would refuse every call to it anyway, but only
	 * after the credentials had been saved beside it.
	 *
	 * @return void
	 */
	public function test_connect_refuses_a_url_that_is_not_send4_and_stores_nothing() {
		$before = Send4_Settings::connection();

		try {
			( new Send4_Cli() )->connect(
				array(),
				array(
					'client-id'     => 'new-client',
					'client-secret' => 'new-secret',
					'url-base'      => 'https://evil.com',
				)
			);
			$this->fail( 'connect should have stopped with an error.' );
		} catch ( Send4_Cli_Halted $halted ) {
			$this->assertStringContainsString( 'is not a Send4 address', $halted->getMessage() );
		}

		$this->assertFalse( get_option( Send4_Settings::BASE_URL_OPTION, false ) );
		$this->assertSame( $before, Send4_Settings::connection() );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * An allowed override is still stored and used.
	 *
	 * @return void
	 */
	public function test_connect_stores_an_allowed_url() {
		$this->http->queueToken();
		$this->http->queueHandshake();

		( new Send4_Cli() )->connect(
			array(),
			array(
				'client-id'     => 'new-client',
				'client-secret' => 'new-secret',
				'url-base'      => 'https://staging.send4.ai/',
			)
		);

		$this->assertSame( 'https://staging.send4.ai', get_option( Send4_Settings::BASE_URL_OPTION ) );
		$this->assertSame( 'success', $this->lastOutput()[0] );
	}

	/**
	 * `wp send4 requeue` puts this connection's dead letters back in line.
	 *
	 * @return void
	 */
	public function test_requeue_moves_failed_rows_back_to_pending() {
		Send4_Queue::enqueue(
			array(
				'submission_id' => 'gravityforms:7:512:cli',
				'lead'          => array( 'phone' => '+37060012345' ),
			),
			'gravityforms:7',
			'missing ability',
			Send4_Queue::STATUS_PERMANENT
		);
		Send4_Cron::unschedule();

		( new Send4_Cli() )->requeue();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
		$this->assertSame( 'success', $this->lastOutput()[0] );
		$this->assertStringContainsString( 'Requeued 1', $this->lastOutput()[1] );
		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * Without a connection there is nothing to requeue them for.
	 *
	 * @return void
	 */
	public function test_requeue_refuses_when_not_connected() {
		Send4_Settings::disconnect();

		$this->expectException( Send4_Cli_Halted::class );

		( new Send4_Cli() )->requeue();
	}

	/**
	 * `wp send4 handshake` says what Send4 still needs before it can text a
	 * lead, and when Send4 asks for a newer plugin (#1531).
	 *
	 * @return void
	 */
	public function test_handshake_prints_what_is_missing_and_the_minimum() {
		delete_option( Send4_Minimum_Version::OPTION );

		$this->http->queueToken();
		$this->http->queueHandshake(
			array(),
			'Stogas UAB',
			array(
				'plugin'    => array( 'minimum_version' => '9.0.0' ),
				'readiness' => array(
					array(
						'key'        => 'plan_active',
						'ok'         => true,
						'message'    => 'Your Send4 plan is active.',
						'action_url' => null,
					),
					array(
						'key'        => 'sms_channel',
						'ok'         => false,
						'message'    => 'Add the phone number Send4 texts your leads from.',
						'action_url' => 'https://app.send4.ai/settings/phones',
					),
				),
			)
		);

		( new Send4_Cli() )->handshake();

		$warnings = WP_CLI::printed( 'warning' );

		$this->assertContains( 'Send4 asks for version 9.0.0 or newer of this plugin; this is ' . SEND4_VERSION . '.', $warnings );
		$this->assertContains( 'Not ready to text leads: Add the phone number Send4 texts your leads from. https://app.send4.ai/settings/phones', $warnings );
		$this->assertNotContains( 'Not ready to text leads: Your Send4 plan is active.', $warnings );

		delete_option( Send4_Minimum_Version::OPTION );
	}
}
