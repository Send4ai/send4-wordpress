<?php
/**
 * What the plugin does with each answer Send4 can give a submission POST.
 *
 * @package Send4
 */

/**
 * The queue decision is the whole point of this class: a lead must never be
 * lost to a deploy, and must never be sent twice because a 202 was misread.
 */
class Send4_Delivery_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * A captured Gravity Forms submission, on an enabled form.
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
	 * The most recent log entry.
	 *
	 * @return array
	 */
	private function lastLogEntry() {
		$entries = Send4_Logger::entries();

		$this->assertNotEmpty( $entries );

		return $entries[0];
	}

	/**
	 * Send4 binds a credential to the site it was minted for and answers 403
	 * {status:'rejected'} when a submission arrives from anywhere else. That
	 * needs a different fix from the other 403 (a key missing the ability), so
	 * the admin has to be told which one happened.
	 *
	 * @return void
	 */
	public function test_a_site_mismatch_403_reports_what_send4_said() {
		$submission = $this->captureFixture();

		$this->http->queueToken();
		$this->http->queue(
			Send4_Http_Recorder::response(
				403,
				array(
					'status'  => 'rejected',
					'routed'  => false,
					'message' => 'This credential is connected to a different site.',
				)
			)
		);

		$result = Send4_Delivery::capture( $submission );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame(
			'This credential is connected to a different site.',
			$result->message,
			'Telling this admin to tick an ability sends them to the wrong fix.'
		);
	}

	/**
	 * A 403 with nothing useful in it still has to say something actionable.
	 *
	 * @return void
	 */
	public function test_a_403_without_a_message_falls_back_to_the_ability_hint() {
		$submission = $this->captureFixture();

		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 403, array() ) );

		$result = Send4_Delivery::capture( $submission );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );
		$this->assertStringContainsString( 'WordPress plugin', $result->message );
	}

	/**
	 * The happy path: accepted, routed, nothing left behind.
	 *
	 * @return void
	 */
	public function test_a_routed_202_is_delivered_and_not_queued() {
		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::DELIVERED, $result->outcome );
		$this->assertSame( 4242, $result->run_id );
		$this->assertSame( array(), $this->queueRows() );

		$form = Send4_Settings::form( self::FORM_KEY );

		$this->assertSame( 'delivered', $form['last_status'] );
		$this->assertSame( '', $form['last_error'] );
		$this->assertSame( 'info', $this->lastLogEntry()['level'] );

		// The envelope really went to the submissions endpoint, whole.
		$posted = $this->http->lastBody();

		$this->assertSame( '+37060012345', $posted['lead']['phone'] );
		$this->assertSame( self::FORM_KEY, $posted['form']['key'] );
	}

	/**
	 * Accepted but unrouted means "connect a flow", not "retry".
	 *
	 * @return void
	 */
	public function test_an_unrouted_202_is_recorded_but_never_queued_or_retried() {
		$this->http->queueToken();
		$this->http->queue202( false );

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::UNROUTED, $result->outcome );
		$this->assertTrue( $result->is_final() );
		$this->assertFalse( $result->is_retryable() );
		$this->assertSame( array(), $this->queueRows() );

		$this->assertSame( 'unrouted', Send4_Settings::form( self::FORM_KEY )['last_status'] );

		// Nothing to retry, so a queue pass makes no further requests.
		$before = $this->http->requestCount();

		Send4_Delivery::process_queue();

		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * Send4 having seen the submission already is a success, not a warning.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_treated_as_delivered() {
		$this->http->queueToken();
		$this->http->queueDuplicate();

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::DUPLICATE, $result->outcome );
		$this->assertTrue( $result->is_final() );
		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'duplicate', Send4_Settings::form( self::FORM_KEY )['last_status'] );

		$entry = $this->lastLogEntry();

		$this->assertSame( 'info', $entry['level'] );
		$this->assertNotSame( 'warning', $entry['level'] );
	}

	/**
	 * A malformed envelope will be malformed forever, so it is parked, not
	 * retried, and the error is kept for the settings screen.
	 *
	 * @return void
	 */
	public function test_a_422_is_parked_as_a_permanent_failure() {
		$this->http->queueToken();
		$this->http->queue422( array( 'lead.phone' => array( 'The lead.phone field is required.' ) ) );

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::PERMANENT, $result->outcome );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( 'lead.phone: The lead.phone field is required.', $row->last_error );

		$form = Send4_Settings::form( self::FORM_KEY );

		$this->assertSame( 'failed', $form['last_status'] );
		$this->assertSame( 'error', $this->lastLogEntry()['level'] );

		$before = $this->http->requestCount();

		$this->assertSame( 0, Send4_Delivery::process_queue()['attempted'] );
		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * A server error is somebody else's outage: keep the lead and try later.
	 *
	 * @return void
	 */
	public function test_a_500_is_queued_for_retry() {
		$this->http->queueToken();
		$this->http->queue500();

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertSame( self::FORM_KEY, $row->form_key );

		$this->assertSame( 'queued', Send4_Settings::form( self::FORM_KEY )['last_status'] );

		// The retry is scheduled, and the first backoff step is a minute.
		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
		$this->assertEqualsWithDelta(
			time() + 60,
			strtotime( $row->next_attempt_at . ' UTC' ),
			5
		);
	}

	/**
	 * A request that never completes is the same class of problem as a 500.
	 *
	 * @return void
	 */
	public function test_a_network_timeout_is_queued_for_retry() {
		$this->http->queueToken();
		$this->http->queueTimeout();

		$result = Send4_Delivery::capture( $this->captureFixture() );

		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 0, $result->code );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertStringContainsString( 'timed out', $row->last_error );

		// The envelope is stored intact so the retry sends the same lead.
		$this->assertSame( '+37060012345', Send4_Queue::envelope( $row )['lead']['phone'] );
	}

	/**
	 * A form nobody has enabled is not Send4's business.
	 *
	 * @return void
	 */
	public function test_it_ignores_a_form_that_is_not_enabled() {
		$submission = ( new Send4_Adapter_Gravityforms() )->capture(
			$this->fixture( 'gravityforms/entry.php' ),
			$this->fixture( 'gravityforms/form.php' )
		);

		$this->assertNull( Send4_Delivery::capture( $submission ) );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * No phone means nobody to text, so nothing is sent and the operator is
	 * told why on the settings screen.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_submission_with_no_phone_number() {
		$entry = $this->fixture( 'gravityforms/entry.php' );

		$entry['2'] = '';

		$this->enableForm(
			self::FORM_KEY,
			array(
				'phone' => '2',
				'name'  => '1',
				'email' => '3',
			)
		);

		$submission = ( new Send4_Adapter_Gravityforms() )->capture( $entry, $this->fixture( 'gravityforms/form.php' ) );

		$this->assertNull( Send4_Delivery::capture( $submission ) );
		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'failed', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * A disconnected site keeps quiet rather than throwing on a visitor's
	 * request; the contact form itself must still work.
	 *
	 * @return void
	 */
	public function test_it_does_nothing_when_the_site_is_not_connected() {
		$submission = $this->captureFixture();

		Send4_Settings::disconnect();

		$this->assertNull( Send4_Delivery::capture( $submission ) );
		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( 'warning', $this->lastLogEntry()['level'] );
	}
}
