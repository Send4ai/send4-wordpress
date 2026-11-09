<?php
/**
 * What each answer to a submission POST means for the lead.
 *
 * @package Send4
 */

/**
 * The classification decides between "deleted because Send4 has it", "kept
 * and retried" and "kept and parked", so a wrong answer here either loses a
 * lead or retries a hopeless one for a day and a half.
 */
class Send4_Response_Classification_Test extends Send4_Test_Case {

	/**
	 * A minimal envelope.
	 *
	 * @return array
	 */
	private function envelope() {
		return array(
			'submission_id' => 'contact-form-7:1:1700000000:classify',
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
	 * Submit once and return the classification.
	 *
	 * @param array $response The scripted answer.
	 * @return Send4_Api_Result
	 */
	private function classify( array $response ) {
		$this->http->queueToken();
		$this->http->queue( $response );

		return ( new Send4_Api_Client() )->submit( $this->envelope() );
	}

	/**
	 * A 2xx that is not this API's answer is not an acceptance.
	 *
	 * `{"maintenance":true}` used to default to status "accepted", routed
	 * false: UNROUTED, which is final, so the queue deleted the only copy.
	 *
	 * @dataProvider notASubmissionResponseProvider
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Response body.
	 * @return void
	 */
	public function test_a_success_that_is_not_a_submission_response_is_transient( $code, array $body ) {
		$result = $this->classify( Send4_Http_Recorder::response( $code, $body ) );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertFalse( $result->is_final() );
	}

	/**
	 * Bodies a proxy, a load balancer or a half-deployed app might send.
	 *
	 * @return array<string,array{0:int,1:array}>
	 */
	public function notASubmissionResponseProvider() {
		return array(
			'maintenance flag'        => array( 200, array( 'maintenance' => true ) ),
			'status without routed'   => array( 202, array( 'status' => 'accepted' ) ),
			'routed without status'   => array( 202, array( 'routed' => false ) ),
			'unknown status'          => array( 202, array( 'status' => 'ok', 'routed' => true ) ),
			'routed is not a boolean' => array( 202, array( 'status' => 'accepted', 'routed' => 'yes' ) ),
			'rejected'                => array( 200, array( 'status' => 'rejected', 'routed' => false ) ),
		);
	}

	/**
	 * The real answers still mean what they meant.
	 *
	 * @return void
	 */
	public function test_the_real_answers_are_still_final() {
		$this->assertSame(
			Send4_Api_Result::UNROUTED,
			$this->classify( Send4_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => false ) ) )->outcome
		);

		delete_transient( Send4_Api_Client::TOKEN_TRANSIENT );

		$this->assertSame(
			Send4_Api_Result::DUPLICATE,
			$this->classify( Send4_Http_Recorder::response( 202, array( 'status' => 'duplicate', 'routed' => false ) ) )->outcome
		);
	}

	/**
	 * The end-to-end version of the first test: a queued lead answered with a
	 * maintenance page stays on the queue.
	 *
	 * @return void
	 */
	public function test_a_queued_lead_is_not_deleted_by_a_maintenance_answer() {
		Send4_Queue::enqueue( $this->envelope(), 'contact-form-7:1', 'Send4 returned status 503.' );
		$this->makeDue( $this->onlyQueueRow() );

		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'maintenance' => true ) ) );

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 0, $summary['delivered'] );
		$this->assertSame( Send4_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
	}

	/**
	 * A client error other than timeout, too-early or rate limiting gets the
	 * same answer on every retry, so it is parked at once rather than retried
	 * ten times over a day and a half.
	 *
	 * @dataProvider permanentClientErrorProvider
	 *
	 * @param int $code HTTP status.
	 * @return void
	 */
	public function test_a_hopeless_client_error_is_permanent( $code ) {
		$result = $this->classify( Send4_Http_Recorder::response( $code, array( 'message' => 'No.' ) ) );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame( $code, $result->code );
		$this->assertSame( 'No.', $result->message, 'What Send4 said is kept for the settings screen.' );
	}

	/**
	 * Statuses no retry can change.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function permanentClientErrorProvider() {
		return array(
			'400' => array( 400 ),
			'404' => array( 404 ),
			'405' => array( 405 ),
			'409' => array( 409 ),
			'410' => array( 410 ),
			'413' => array( 413 ),
		);
	}

	/**
	 * Without a message the status is named instead.
	 *
	 * @return void
	 */
	public function test_a_hopeless_client_error_without_a_message_names_the_status() {
		$result = $this->classify( Send4_Http_Recorder::response( 404, '' ) );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );
		$this->assertStringContainsString( '404', $result->message );
	}

	/**
	 * These three are about timing, not about the submission.
	 *
	 * @dataProvider retryableClientErrorProvider
	 *
	 * @param int $code HTTP status.
	 * @return void
	 */
	public function test_a_timing_client_error_is_transient( $code ) {
		$this->assertSame(
			Send4_Api_Result::TRANSIENT,
			$this->classify( Send4_Http_Recorder::response( $code, array() ) )->outcome
		);
	}

	/**
	 * Client errors a later attempt can fix.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function retryableClientErrorProvider() {
		return array(
			'408' => array( 408 ),
			'425' => array( 425 ),
			'429' => array( 429 ),
		);
	}

	/**
	 * A 429's Retry-After is carried on the result, in either of its forms.
	 *
	 * @dataProvider retryAfterProvider
	 *
	 * @param callable $header   Builds the header value.
	 * @param int      $expected Seconds expected.
	 * @return void
	 */
	public function test_a_rate_limit_carries_its_retry_after( $header, $expected ) {
		$response            = Send4_Http_Recorder::response( 429, array( 'message' => 'Too Many Attempts.' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => $header(),
		);

		$result = $this->classify( $response );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertEqualsWithDelta( $expected, $result->retry_after, 5 );
	}

	/**
	 * Seconds, an HTTP date, and nonsense.
	 *
	 * @return array<string,array{0:callable,1:int}>
	 */
	public function retryAfterProvider() {
		return array(
			'seconds'   => array(
				static function () {
					return '120';
				},
				120,
			),
			'HTTP date' => array(
				static function () {
					return gmdate( 'D, d M Y H:i:s', time() + 900 ) . ' GMT';
				},
				900,
			),
			'a date in the past' => array(
				static function () {
					return gmdate( 'D, d M Y H:i:s', time() - 900 ) . ' GMT';
				},
				0,
			),
			'garbage'   => array(
				static function () {
					return 'soon';
				},
				0,
			),
			'absurd'    => array(
				static function () {
					return '99999999';
				},
				DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * A rate-limited sign-in carries it too, since sign-in is the endpoint
	 * Send4 actually rate limits.
	 *
	 * @return void
	 */
	public function test_a_rate_limited_sign_in_carries_its_retry_after() {
		$response            = Send4_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '45',
		);
		$this->http->queue( $response );

		$result = ( new Send4_Api_Client() )->submit( $this->envelope() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 45, $result->retry_after );
	}
}
