<?php
/**
 * How the plugin asks WordPress to make a request, rather than what it asks for.
 *
 * @package Send4
 */

/**
 * Three settings on every outbound call are load-bearing, and all three are
 * invisible in the response:
 *
 * - redirection => 0, because following a redirect hands the bearer token to
 *   whoever the redirect names, and an unauthenticated Laravel route answers
 *   302 to /login — which, followed, looks like a perfectly good 200 with an
 *   HTML body;
 * - sslverify => true, because the request carries a credential and a lead;
 * - a timeout short enough that a Send4 outage does not hold a visitor's form
 *   submit open for the length of a TCP timeout.
 *
 * None of them changes the outcome of a scripted test, so each is asserted here
 * directly against the arguments WP_Http was handed.
 */
class Send4_Request_Hardening_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * A captured Gravity Forms submission on an enabled form.
	 *
	 * @return Send4_Submission
	 */
	private function captureFixture() {
		$this->enableForm(
			self::FORM_KEY,
			array(
				'phone' => '2',
				'name'  => '1',
				'email' => '3',
			)
		);

		return ( new Send4_Adapter_Gravityforms() )->capture(
			$this->fixture( 'gravityforms/entry.php' ),
			$this->fixture( 'gravityforms/form.php' )
		);
	}

	/**
	 * Assert the hardening on every request recorded so far.
	 *
	 * @param int $expected How many requests there should be.
	 * @return void
	 */
	private function assertEveryRequestIsHardened( $expected ) {
		$requests = $this->http->requests();

		$this->assertCount( $expected, $requests );

		foreach ( $requests as $index => $request ) {
			$where = 'request ' . $index . ' (' . $request['url'] . ')';

			$this->assertSame(
				0,
				$request['args']['redirection'],
				$where . ' must not follow redirects: a redirect would hand the bearer token to whoever it names.'
			);
			$this->assertTrue(
				$request['args']['sslverify'],
				$where . ' must verify TLS: it carries a credential and a lead.'
			);
			$this->assertTrue( $request['args']['blocking'], $where . ' must wait for the answer.' );
		}
	}

	/**
	 * The visitor-facing path: token exchange, submission POST and the queue
	 * retry that follows a failure.
	 *
	 * @return void
	 */
	public function test_every_request_on_the_submission_path_is_hardened() {
		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::capture( $this->captureFixture() );

		$this->makeDue( $this->onlyQueueRow() );
		$this->http->queue202();

		Send4_Delivery::process_queue();

		// Token, POST, retry POST — the retry reuses the cached token.
		$this->assertEveryRequestIsHardened( 3 );
	}

	/**
	 * And the admin-facing one.
	 *
	 * @return void
	 */
	public function test_every_request_on_the_handshake_path_is_hardened() {
		$this->http->queueToken();
		$this->http->queueHandshake();

		( new Send4_Api_Client() )->handshake();

		$this->assertEveryRequestIsHardened( 2 );
	}

	/**
	 * A visitor is waiting on this one, so it gets seconds, not minutes.
	 *
	 * The literal is asserted as well as the constant: a test written only
	 * against Send4_Api_Client::TIMEOUT_VISITOR would follow the constant
	 * wherever somebody moved it, including to a value that parks a contact
	 * form for five minutes while Send4 is down.
	 *
	 * @return void
	 */
	public function test_the_visitor_path_uses_the_short_timeout() {
		$this->assertSame(
			3,
			Send4_Api_Client::TIMEOUT_VISITOR,
			'Somebody is watching a spinner on their own contact form while this runs.'
		);

		$this->http->queueToken();
		$this->http->queue202();

		Send4_Delivery::capture( $this->captureFixture() );

		$requests = $this->http->requests();

		$this->assertCount( 2, $requests );

		foreach ( $requests as $request ) {
			$this->assertSame(
				Send4_Api_Client::TIMEOUT_VISITOR,
				$request['args']['timeout'],
				$request['url'] . ' was made while a visitor waited and must use the short timeout.'
			);
		}
	}

	/**
	 * Nobody is waiting on the settings screen's connection test in the same
	 * way, and an operator would rather wait than be told "timed out".
	 *
	 * @return void
	 */
	public function test_the_interactive_path_uses_the_longer_timeout() {
		$this->assertSame( 10, Send4_Api_Client::TIMEOUT_INTERACTIVE );
		$this->assertGreaterThan( Send4_Api_Client::TIMEOUT_VISITOR, Send4_Api_Client::TIMEOUT_INTERACTIVE );

		$this->http->queueToken();
		$this->http->queueHandshake();

		( new Send4_Api_Client() )->handshake();

		foreach ( $this->http->requests() as $request ) {
			$this->assertSame( Send4_Api_Client::TIMEOUT_INTERACTIVE, $request['args']['timeout'] );
		}
	}

	/**
	 * The queue worker is not on a visitor's request either.
	 *
	 * @return void
	 */
	public function test_the_queue_worker_uses_the_longer_timeout() {
		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::capture( $this->captureFixture() );

		$this->makeDue( $this->onlyQueueRow() );
		$this->http->queue202();

		Send4_Delivery::process_queue();

		$this->assertSame(
			Send4_Api_Client::TIMEOUT_INTERACTIVE,
			$this->http->lastRequest()['args']['timeout']
		);
	}

	/**
	 * A 302 is not a success. Read as one it would silently drop the lead and
	 * tell the operator everything is fine.
	 *
	 * @return void
	 */
	public function test_a_redirect_is_transient_and_queues_the_lead() {
		$this->http->queueToken();
		$this->http->queue302();

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 302, $result->code );
		$this->assertStringContainsString( 'redirect', $result->message );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( '+37060012345', Send4_Queue::envelope( $row )['lead']['phone'] );
		$this->assertSame( 'queued', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * Neither is a 200 that is not this API talking: a caching layer, a captive
	 * portal or a maintenance page can all answer 200 with HTML.
	 *
	 * @dataProvider nonJsonSuccessProvider
	 *
	 * @param int $code Status code the imposter answered with.
	 * @return void
	 */
	public function test_a_success_that_is_not_json_is_transient( $code ) {
		$this->http->queueToken();
		$this->http->queueNonJson( $code );

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( $code, $result->code );
		$this->assertSame( Send4_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
	}

	/**
	 * Both codes the client treats as success.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function nonJsonSuccessProvider() {
		return array(
			'200' => array( 200 ),
			'202' => array( 202 ),
		);
	}
}
