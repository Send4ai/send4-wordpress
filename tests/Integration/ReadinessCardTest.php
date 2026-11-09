<?php
/**
 * The dashboard's "Before Send4 can text your leads" card.
 *
 * @package Send4
 */

/**
 * Send4 answers 202 to a submission whether or not anything can text the lead,
 * so this card is the only place a site learns that nobody will be texted
 * (#1531). It shows what Send4 said and nothing it did not: no card from an
 * older Send4, none when the connection failed, and no link that leads
 * anywhere but Send4.
 */
class Send4_Readiness_Card_Test extends Send4_Test_Case {

	/**
	 * An administrator, one detectable form.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);
	}

	/**
	 * Undo the request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * The six rows Send4 sends today, in its order, with the given ones failing.
	 *
	 * @param array<int,string> $failing Keys that fail.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $failing ) {
		$rows = array(
			'plan_active'    => array( 'Your Send4 plan is active.', 'Your Send4 plan has stopped. Choose a plan to start texting leads.', 'https://app.send4.ai/subscribe' ),
			'legal_accepted' => array( 'The Send4 terms are accepted.', 'Accept the Send4 terms.', 'https://app.send4.ai/legal' ),
			'email_verified' => array( 'Your email address is confirmed.', 'Confirm your email address.', 'https://app.send4.ai/profile' ),
			'flow_listening' => array( 'A flow listens for your forms.', 'No flow listens for your forms yet. Create one with a WordPress form trigger and activate it.', 'https://app.send4.ai/flows' ),
			'sms_channel'    => array( 'Your sending number is ready.', 'Add the phone number Send4 texts your leads from, or ask Send4 to set one up for you.', 'https://app.send4.ai/settings/phones' ),
			'sms_sending'    => array( 'Your number can send.', 'Connect the Twilio or Vonage account your number sends through, or ask Send4 to do it for you.', null ),
		);

		$out = array();

		foreach ( $rows as $key => $copy ) {
			$fails = in_array( $key, $failing, true );
			$out[] = array(
				'key'        => $key,
				'ok'         => ! $fails,
				'message'    => $fails ? $copy[1] : $copy[0],
				'action_url' => $fails ? $copy[2] : null,
			);
		}

		return $out;
	}

	/**
	 * Cache a successful handshake for the dashboard to read.
	 *
	 * @param array|null $readiness Readiness rows, null for an older Send4.
	 * @return void
	 */
	private function connectedWith( $readiness ) {
		$handshake = array(
			'client' => array( 'name' => 'Stogas UAB' ),
			'flows'  => array(),
		);

		if ( null !== $readiness ) {
			$handshake['readiness'] = $readiness;
		}

		set_transient( Send4_Admin::HANDSHAKE_CACHE, $handshake, 300 );
	}

	/**
	 * The dashboard's markup.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();

		try {
			( new Send4_Admin() )->render_dashboard();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	public function test_failing_checks_are_listed_with_their_links() {
		$this->connectedWith( $this->rows( array( 'flow_listening', 'sms_channel' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Before Send4 can text your leads', $html );
		$this->assertStringContainsString( 'No flow listens for your forms yet.', $html );
		$this->assertStringContainsString( 'Add the phone number Send4 texts your leads from', $html );
		$this->assertStringContainsString( 'href="https://app.send4.ai/flows"', $html );
		$this->assertStringContainsString( 'href="https://app.send4.ai/settings/phones"', $html );

		// Passing rows are not on the card.
		$this->assertStringNotContainsString( 'Your Send4 plan is active.', $html );
	}

	/**
	 * In Send4's order, because that is the order to work through them.
	 *
	 * @return void
	 */
	public function test_failing_checks_keep_send4s_order() {
		$failing = Send4_Readiness::failing(
			array( 'readiness' => $this->rows( array( 'sms_sending', 'plan_active', 'flow_listening' ) ) )
		);

		$this->assertSame( array( 'plan_active', 'flow_listening', 'sms_sending' ), wp_list_pluck( $failing, 'key' ) );
	}

	public function test_no_card_when_everything_passes() {
		$this->connectedWith( $this->rows( array() ) );

		$this->assertStringNotContainsString( 'Before Send4 can text your leads', $this->render() );
	}

	/**
	 * Today's production Send4 sends no readiness, and silence is not a
	 * failure: the card is only ever drawn from what Send4 said.
	 *
	 * @return void
	 */
	public function test_no_card_from_a_send4_that_sends_no_readiness() {
		$this->connectedWith( null );

		$this->assertStringNotContainsString( 'Before Send4 can text your leads', $this->render() );
	}

	/**
	 * When the connection itself failed, the outage notice says so; a
	 * checklist on top of it would be guessing.
	 *
	 * @return void
	 */
	public function test_no_card_when_the_connection_failed() {
		$this->http->fallback( new WP_Error( 'http_request_failed', 'offline' ) );

		$this->assertStringNotContainsString( 'Before Send4 can text your leads', $this->render() );
	}

	/**
	 * A failing check Send4 can only fix itself has no link, just its words.
	 *
	 * @return void
	 */
	public function test_a_check_without_a_link_still_shows() {
		$this->connectedWith( $this->rows( array( 'sms_sending' ) ) );

		$failing = Send4_Readiness::failing( Send4_Admin::cached_handshake() );

		$this->assertCount( 1, $failing );
		$this->assertSame( '', $failing[0]['action_url'] );
		$this->assertStringContainsString( 'Connect the Twilio or Vonage account', $this->render() );
	}

	/**
	 * The API is trusted with submissions, not with where the administrator's
	 * browser goes next: a link that does not lead to Send4 is dropped.
	 *
	 * @return void
	 */
	public function test_a_link_that_does_not_lead_to_send4_is_dropped() {
		$rows = array(
			array(
				'key'        => 'flow_listening',
				'ok'         => false,
				'message'    => 'No flow listens for your forms yet.',
				'action_url' => 'https://send4.ai.evil.example/flows',
			),
			array(
				'key'        => 'sms_channel',
				'ok'         => false,
				'message'    => 'Add a number.',
				'action_url' => 'javascript:alert(1)',
			),
			array(
				'key'        => 'sms_sending',
				'ok'         => false,
				'message'    => 'Connect the provider.',
				'action_url' => 'https://app.send4.ai@evil.example/x',
			),
		);

		$this->connectedWith( $rows );

		$html = $this->render();

		$this->assertSame( array( '', '', '' ), wp_list_pluck( Send4_Readiness::failing( array( 'readiness' => $rows ) ), 'action_url' ) );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( 'No flow listens for your forms yet.', $html );
	}

	/**
	 * Send4's message is product copy, drawn as text.
	 *
	 * @return void
	 */
	public function test_the_message_is_shown_as_plain_text_and_capped() {
		$failing = Send4_Readiness::failing(
			array(
				'readiness' => array(
					array(
						'key'     => 'plan_active',
						'ok'      => false,
						'message' => "Choose a <b>plan</b>\n<script>alert(1)</script>today. " . str_repeat( 'x', 400 ),
					),
				),
			)
		);

		$this->assertCount( 1, $failing );
		$this->assertStringStartsWith( 'Choose a plan today.', $failing[0]['message'] );
		$this->assertStringNotContainsString( '<', $failing[0]['message'] );
		$this->assertSame( Send4_Readiness::MAX_MESSAGE, mb_strlen( $failing[0]['message'] ) );
	}

	/**
	 * A check Send4 adds later shows up without a plugin release, and a row
	 * with nothing to say is skipped rather than drawn empty.
	 *
	 * @return void
	 */
	public function test_unknown_checks_show_and_empty_ones_do_not() {
		$failing = Send4_Readiness::failing(
			array(
				'readiness' => array(
					array(
						'key'     => 'a_check_from_the_future',
						'ok'      => false,
						'message' => 'Do the new thing.',
					),
					array(
						'key' => 'mute',
						'ok'  => false,
					),
					'not a row',
					array(
						'key'     => 'no_ok_key',
						'message' => 'Unclear means failing.',
					),
				),
			)
		);

		$this->assertSame( array( 'a_check_from_the_future', 'no_ok_key' ), wp_list_pluck( $failing, 'key' ) );
	}

	/**
	 * The live handshake keeps the readiness Send4 sent, for the card to read.
	 *
	 * @return void
	 */
	public function test_the_handshake_keeps_the_readiness() {
		$this->http->queueToken();
		$this->http->queueHandshake( array(), 'Stogas UAB', array( 'readiness' => $this->rows( array( 'sms_channel' ) ) ) );

		$handshake = ( new Send4_Api_Client() )->handshake();

		$this->assertIsArray( $handshake );
		$this->assertCount( 6, $handshake['readiness'] );
		$this->assertSame( array( 'sms_channel' ), wp_list_pluck( Send4_Readiness::failing( $handshake ), 'key' ) );
	}
}
