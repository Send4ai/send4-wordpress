<?php
/**
 * The Connect button, end to end on the plugin's side.
 *
 * @package Send4
 */

/**
 * What matters here is that the two short-lived values from leg 1 are kept,
 * presented once at redemption and then dropped — and that a code which did not
 * start on this site cannot be redeemed at all.
 */
class Send4_Connect_Handshake_Test extends Send4_Test_Case {

	/**
	 * Scripted registration response.
	 *
	 * @return void
	 */
	private function queueRegistration() {
		$this->http->queue(
			Send4_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://app.send4.ai/connect/wordpress/wpconnect_abc123',
				)
			)
		);
	}

	public function test_registering_keeps_the_pair_needed_to_redeem_later() {
		$this->queueRegistration();

		$registration = ( new Send4_Api_Client() )->register_site();

		$this->assertSame( 'wpconnect_abc123', $registration['connect_id'] );

		$body = $this->http->lastBody();
		$this->assertSame( 'wordpress', $body['platform'] );
		$this->assertSame( untrailingslashit( home_url() ), $body['site_url'] );
		// Send4 redirects the browser straight back to this URL, so it has to
		// be the screen that redeems the code, not wherever the plugin used to
		// keep its settings.
		$this->assertStringContainsString( 'admin.php?page=' . Send4_Admin::CONNECTION_SLUG, $body['admin_url'] );
	}

	public function test_a_failed_registration_is_reported_not_swallowed() {
		$this->http->queue(
			Send4_Http_Recorder::response( 422, array( 'message' => 'admin_url must be on the same site as site_url.' ) )
		);

		$result = ( new Send4_Api_Client() )->register_site();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'same site', $result->get_error_message() );
	}

	public function test_the_exchange_presents_both_halves() {
		$this->http->queue(
			Send4_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'client_x',
					'client_secret' => 'secret_y',
				)
			)
		);

		$credentials = ( new Send4_Api_Client() )->exchange_code( 'wpconnect_abc123', 'registration-secret', 'one-time-code' );

		$this->assertSame( 'client_x', $credentials['client_id'] );

		$body = $this->http->lastBody();
		$this->assertSame( 'wpconnect_abc123', $body['connect_id'] );
		$this->assertSame( 'registration-secret', $body['connect_secret'] );
		$this->assertSame( 'one-time-code', $body['code'] );
	}

	/**
	 * The approval link is followed by the administrator's browser, so a reply
	 * pointing anywhere but Send4 is refused at the API client — before it can
	 * reach a redirect, and whoever the caller is.
	 *
	 * @return void
	 */
	public function test_registration_refuses_an_approval_link_that_is_not_send4() {
		$this->http->queue(
			Send4_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://evil.example/approve',
				)
			)
		);

		$result = ( new Send4_Api_Client() )->register_site();

		$this->assertWPError( $result );
		$this->assertSame( 'send4_register_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'not on Send4', $result->get_error_message() );
	}
}
