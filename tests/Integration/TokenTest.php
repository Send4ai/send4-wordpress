<?php
/**
 * Token exchange, caching, and what happens when a token is revoked.
 *
 * @package Send4
 */

/**
 * Send4 keeps one active token per API client, so every exchange revokes the
 * previous one. These tests pin the behaviour that keeps a site from fighting
 * itself: cache the token, re-exchange only on a 401, and only once.
 */
class Send4_Token_Test extends Send4_Test_Case {

	/**
	 * A minimal valid envelope.
	 *
	 * @param string $submission_id Idempotency key.
	 * @return array
	 */
	private function envelope( $submission_id = 'contact-form-7:1:1700000000:abcdef123456' ) {
		return array(
			'submission_id' => $submission_id,
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => 'contact-form-7:1',
				'name' => 'Send4 test form',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);
	}

	/**
	 * The token lands in the transient with the expiry the API reported.
	 *
	 * @return void
	 */
	public function test_it_caches_the_token_with_its_expiry() {
		$this->http->queueToken( 'first-token', 86400 );

		$before = time();
		$token  = ( new Send4_Api_Client() )->token();
		$after  = time();

		$this->assertSame( 'first-token', $token );

		$cached = get_transient( Send4_Api_Client::TOKEN_TRANSIENT );

		$this->assertIsArray( $cached );
		$this->assertSame( 'first-token', $cached['token'] );
		$this->assertGreaterThanOrEqual( $before + 86400, $cached['expires_at'] );
		$this->assertLessThanOrEqual( $after + 86400, $cached['expires_at'] );

		$this->assertSame( 1, $this->http->requestCount() );
		$this->assertStringEndsWith( '/api/v2/auth/token', $this->http->requests()[0]['url'] );
		$this->assertSame(
			array(
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
			),
			$this->http->requests()[0]['body']
		);
	}

	/**
	 * A cached token is reused rather than exchanged again.
	 *
	 * @return void
	 */
	public function test_it_reuses_a_cached_token() {
		$this->http->queueToken( 'first-token' );

		$client = new Send4_Api_Client();

		$this->assertSame( 'first-token', $client->token() );
		$this->assertSame( 'first-token', $client->token() );
		$this->assertSame( 'first-token', ( new Send4_Api_Client() )->token() );
		$this->assertSame( 1, $this->http->requestCount() );
	}

	/**
	 * A token inside the safety margin is replaced before it can fail a POST.
	 *
	 * @return void
	 */
	public function test_it_replaces_a_token_that_is_about_to_expire() {
		set_transient(
			Send4_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'nearly-dead',
				'expires_at' => time() + 60,
			),
			600
		);

		$this->http->queueToken( 'fresh-token' );

		$this->assertSame( 'fresh-token', ( new Send4_Api_Client() )->token() );
		$this->assertSame( 1, $this->http->requestCount() );
	}

	/**
	 * A sign-in that outlives a reconnect must not serve the new connection.
	 *
	 * The exchange is a network call, up to ten seconds of one, and a reconnect
	 * can land inside it: the credentials change, the cache is cleared, and then
	 * the old request comes back and writes the account the site has just left
	 * into the cache that was cleared for it. Nothing asked whose token that
	 * was, so every lead for the next day went out under it.
	 *
	 * The filter below is that other request: it runs while the exchange is on
	 * the wire, which is the only moment the ordering can be staged in one process.
	 *
	 * @return void
	 */
	public function test_a_sign_in_that_outlives_a_reconnect_does_not_serve_the_new_connection() {
		$reconnected = false;
		$reconnect   = static function ( $preempt, $args, $url ) use ( &$reconnected ) {
			unset( $args );

			if ( ! $reconnected && '/api/v2/auth/token' === substr( $url, -18 ) ) {
				$reconnected = true;

				Send4_Settings::update_connection(
					array(
						'client_id'     => 'workspace-b',
						'client_secret' => 'secret-b',
					)
				);
				Send4_Api_Client::forget_token();
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $reconnect, 5, 3 );

		$this->http->queueToken( 'token-of-the-account-it-left' );

		// The request that was already under way still gets its own answer.
		$this->assertSame( 'token-of-the-account-it-left', ( new Send4_Api_Client() )->token() );

		remove_filter( 'pre_http_request', $reconnect, 5 );

		$this->http->queueToken( 'token-of-workspace-b' );
		$this->http->queue202();

		$result = ( new Send4_Api_Client() )->submit( $this->envelope() );

		$this->assertSame( Send4_Api_Result::DELIVERED, $result->outcome );

		$exchanges   = $this->http->requestsTo( '/api/v2/auth/token' );
		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 2, $exchanges, 'The stale token must cost the new connection a sign-in of its own.' );
		$this->assertSame( 'workspace-b', $exchanges[1]['body']['client_id'] );
		$this->assertCount( 1, $submissions );
		$this->assertSame( 'Bearer token-of-workspace-b', $submissions[0]['headers']['Authorization'] );
	}

	/**
	 * Nor is a token sent anywhere but the API that issued it. `wp send4
	 * connect --url-base` moves a site between Send4 environments, and
	 * production's bearer token is nothing staging should ever be shown.
	 *
	 * @return void
	 */
	public function test_a_cached_token_is_not_sent_to_a_different_destination() {
		$this->http->queueToken( 'production-token' );

		$this->assertSame( 'production-token', ( new Send4_Api_Client() )->token() );

		update_option( Send4_Settings::BASE_URL_OPTION, 'https://staging.send4.ai', false );

		$this->http->queueToken( 'staging-token' );

		$this->assertSame( 'staging-token', ( new Send4_Api_Client() )->token() );

		$exchanges = $this->http->requestsTo( '/api/v2/auth/token' );

		$this->assertCount( 2, $exchanges );
		$this->assertStringStartsWith( 'https://staging.send4.ai/', $exchanges[1]['url'] );
	}

	/**
	 * A token cached by a release that did not record whose it was is not
	 * trusted either: updating the plugin costs one sign-in, once.
	 *
	 * @return void
	 */
	public function test_a_token_cached_without_its_owner_is_exchanged_again() {
		set_transient(
			Send4_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'cached-by-an-older-release',
				'expires_at' => time() + 86400,
			),
			86400
		);

		$this->http->queueToken( 'fresh-token' );

		$this->assertSame( 'fresh-token', ( new Send4_Api_Client() )->token() );
		$this->assertSame( 'fresh-token', ( new Send4_Api_Client() )->token(), 'And the replacement is cached as usual.' );
		$this->assertSame( 1, $this->http->requestCount() );
	}

	/**
	 * A revoked token costs one re-exchange and one replay, and then succeeds.
	 *
	 * @return void
	 */
	public function test_a_401_triggers_exactly_one_re_exchange_and_a_replay() {
		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueToken( 'fresh-token' );
		$this->http->queue202( true, 4242 );

		$result = ( new Send4_Api_Client() )->submit( $this->envelope() );

		$this->assertSame( Send4_Api_Result::DELIVERED, $result->outcome );
		$this->assertSame( 4242, $result->run_id );

		$this->assertSame( 4, $this->http->requestCount() );
		$this->assertCount( 2, $this->http->requestsTo( '/api/v2/auth/token' ) );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 2, $submissions );
		$this->assertSame( 'Bearer stale-token', $submissions[0]['headers']['Authorization'] );
		$this->assertSame( 'Bearer fresh-token', $submissions[1]['headers']['Authorization'] );

		// The replay must carry the same envelope, or the retry is a new lead.
		$this->assertSame( $submissions[0]['body'], $submissions[1]['body'] );
	}

	/**
	 * A second 401 is not chased: two exchanges in one submit would be a loop.
	 *
	 * @return void
	 */
	public function test_it_does_not_chase_a_second_401() {
		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueToken( 'fresh-token' );
		$this->http->queue( Send4_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );

		$result = ( new Send4_Api_Client() )->submit( $this->envelope() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 401, $result->code );
		$this->assertSame( 4, $this->http->requestCount() );
	}

	/**
	 * When the credentials themselves have been revoked the submission is put
	 * on the queue as a permanent failure, and no further exchange is attempted.
	 *
	 * @return void
	 */
	public function test_a_failed_re_exchange_queues_the_submission_and_stops() {
		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueTokenRejected( 401, 'invalid_client' );

		$envelope = $this->envelope();
		$result   = Send4_Delivery::send( $envelope, 'contact-form-7:1' );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );

		// Two exchanges: the first, and the one the 401 forced. Not a third.
		$this->assertCount( 2, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
		$this->assertSame( 3, $this->http->requestCount() );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( $envelope['submission_id'], $row->submission_id );

		// And nothing retries it: process_queue only looks at pending rows.
		$this->assertSame(
			array(
				'attempted' => 0,
				'delivered' => 0,
				'failed'    => 0,
			),
			Send4_Delivery::process_queue()
		);
		$this->assertSame( 3, $this->http->requestCount() );
	}

	/**
	 * A token endpoint that is merely down is an outage, not a bad credential.
	 *
	 * This is the branch that decides whether a Send4 deploy costs a site its
	 * leads. Classifying it permanent would dead-letter every lead submitted
	 * during any ordinary outage, and there is nothing on the settings screen
	 * that would make that look different from a bad password.
	 *
	 * @dataProvider tokenOutageProvider
	 *
	 * @param string $failure Which recorder helper stages the outage.
	 * @return void
	 */
	public function test_a_token_endpoint_outage_is_transient_and_queues_the_lead( $failure ) {
		call_user_func( array( $this->http, $failure ) );

		$envelope = $this->envelope();
		$result   = Send4_Delivery::send( $envelope, 'contact-form-7:1' );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertTrue( $result->is_retryable() );

		// The exchange was attempted once and the submission never went out.
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( $envelope['submission_id'], $row->submission_id );
		$this->assertEqualsWithDelta( time() + 60, strtotime( $row->next_attempt_at . ' UTC' ), 5 );

		// Nothing was cached from a failed exchange.
		$this->assertFalse( get_transient( Send4_Api_Client::TOKEN_TRANSIENT ) );
	}

	/**
	 * The two ways a healthy API client meets an unhealthy Send4.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function tokenOutageProvider() {
		return array(
			'the endpoint answers 500' => array( 'queue500' ),
			'the endpoint never answers' => array( 'queueTimeout' ),
		);
	}

	/**
	 * And once Send4 is back the queued lead goes out, unchanged.
	 *
	 * @return void
	 */
	public function test_a_lead_queued_by_a_token_outage_is_delivered_on_the_next_pass() {
		$this->http->queue500();

		$envelope = $this->envelope();

		Send4_Delivery::send( $envelope, 'contact-form-7:1' );

		$row = $this->onlyQueueRow();

		$this->makeDue( $row );

		$this->http->queueToken( 'recovered-token' );
		$this->http->queue202( true, 4242 );

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['delivered'] );
		$this->assertSame( array(), $this->queueRows() );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions );
		$this->assertSame( 'Bearer recovered-token', $submissions[0]['headers']['Authorization'] );

		// The same delivery, not a second lead: Send4 dedupes on this key.
		$this->assertSame( $envelope['submission_id'], $submissions[0]['body']['submission_id'] );
		$this->assertSame( $envelope, $submissions[0]['body'] );
	}

	/**
	 * Send4 rate limits sign-in attempts. Retrying is exactly the right answer,
	 * and the operator is told which wall was hit rather than "status 429".
	 *
	 * @return void
	 */
	public function test_a_rate_limited_exchange_says_so_and_stays_retryable() {
		$this->http->queueTokenRejected( 429, 'too_many_requests' );

		$result = Send4_Delivery::send( $this->envelope(), 'contact-form-7:1' );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 429, $result->code );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertStringContainsString( 'rate limiting', $row->last_error );
		$this->assertStringContainsString( '10 per minute', $row->last_error );

		// The same wording reaches the settings screen.
		$form = Send4_Settings::form( 'contact-form-7:1' );

		$this->assertSame( 'queued', $form['last_status'] );
		$this->assertStringContainsString( 'rate limiting', $form['last_error'] );
	}

	/**
	 * Without credentials nothing is sent at all.
	 *
	 * @return void
	 */
	public function test_it_refuses_to_exchange_without_credentials() {
		Send4_Settings::disconnect();

		$result = ( new Send4_Api_Client() )->submit( $this->envelope() );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * The handshake reads the client and the flows Send4 reports.
	 *
	 * @return void
	 */
	public function test_the_handshake_reports_the_client_and_its_flows() {
		$this->http->queueToken();
		$this->http->queueHandshake(
			array(
				array(
					'flow_id'     => 12,
					'flow_name'   => 'Roof quote follow-up',
					'flow_status' => 'active',
					'trigger_id'  => 44,
					'form_key'    => 'contact-form-7:1',
					'is_active'   => true,
				),
			)
		);

		$handshake = ( new Send4_Api_Client() )->handshake();

		$this->assertIsArray( $handshake );
		$this->assertSame( 'Stogas UAB', $handshake['client']['name'] );
		$this->assertCount( 1, $handshake['flows'] );
		$this->assertSame( 'contact-form-7:1', $handshake['flows'][0]['form_key'] );
	}

	/**
	 * A client without the integrations.wordpress ability is told exactly that.
	 *
	 * @return void
	 */
	public function test_the_handshake_explains_a_missing_ability() {
		$this->http->queueToken();
		$this->http->queue403();

		$handshake = ( new Send4_Api_Client() )->handshake();

		$this->assertWPError( $handshake );
		$this->assertSame( 'send4_missing_ability', $handshake->get_error_code() );
	}
}
