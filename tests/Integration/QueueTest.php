<?php
/**
 * The retry queue: backoff, attempt ceiling, and what a retry actually sends.
 *
 * @package Send4
 */

/**
 * A queued lead has already been promised to the site owner, so the rules here
 * are about not losing it and not delivering it twice.
 */
class Send4_Queue_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * A queued row, produced the way a real failure produces one.
	 *
	 * @return object
	 */
	private function queueOne() {
		$this->enableForm(
			self::FORM_KEY,
			array(
				'phone' => '2',
				'name'  => '1',
				'email' => '3',
			)
		);

		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::capture(
			( new Send4_Adapter_Gravityforms() )->capture(
				$this->fixture( 'gravityforms/entry.php' ),
				$this->fixture( 'gravityforms/form.php' )
			)
		);

		return $this->onlyQueueRow();
	}

	/**
	 * A retry must be the same delivery, not a second lead.
	 *
	 * @return void
	 */
	public function test_a_retry_reuses_the_same_submission_id() {
		$row = $this->queueOne();

		$this->makeDue( $row );
		$this->http->queue202();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['delivered'] );

		$retried = $this->http->lastBody();

		$this->assertSame( $row->submission_id, $retried['submission_id'] );
		$this->assertSame( Send4_Queue::envelope( $row ), $retried );
	}

	/**
	 * A delivered retry leaves nothing behind: the row holds lead data.
	 *
	 * @return void
	 */
	public function test_a_delivered_retry_deletes_the_row() {
		$row = $this->queueOne();

		$this->makeDue( $row );
		$this->http->queue202();

		Send4_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'delivered', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * Same for a duplicate or an unrouted answer: both mean Send4 has it.
	 *
	 * @return void
	 */
	public function test_a_duplicate_retry_also_deletes_the_row() {
		$row = $this->queueOne();

		$this->makeDue( $row );
		$this->http->queueDuplicate();

		Send4_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * A still-failing retry is rescheduled rather than dropped.
	 *
	 * @return void
	 */
	public function test_a_failing_retry_is_rescheduled_and_counts_the_attempt() {
		$row = $this->queueOne();

		$this->makeDue( $row );
		$this->http->queue500();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['failed'] );

		$after = $this->onlyQueueRow();

		$this->assertSame( 2, (int) $after->attempts );
		$this->assertSame( Send4_Queue::STATUS_PENDING, $after->status );
		$this->assertEqualsWithDelta( time() + 300, strtotime( $after->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * A row that is not due yet is left alone.
	 *
	 * @return void
	 */
	public function test_it_leaves_rows_that_are_not_due() {
		$this->queueOne();

		$before = $this->http->requestCount();

		$this->assertSame( 0, Send4_Delivery::process_queue()['attempted'] );
		$this->assertSame( $before, $this->http->requestCount() );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * One minute, five, fifteen, an hour, six hours, and then six hours for
	 * ever — which puts the tenth and last attempt about a day and a half out.
	 *
	 * @return void
	 */
	public function test_the_backoff_schedule() {
		$expected = array(
			1  => 60,
			2  => 300,
			3  => 900,
			4  => 3600,
			5  => 21600,
			6  => 21600,
			7  => 21600,
			8  => 21600,
			9  => 21600,
			10 => 21600,
		);

		foreach ( $expected as $attempt => $delay ) {
			$this->assertSame(
				$delay,
				Send4_Queue::delay_for_attempt( $attempt ),
				'attempt ' . $attempt
			);
		}

		// Attempt zero cannot happen, but must not index out of the array.
		$this->assertSame( 60, Send4_Queue::delay_for_attempt( 0 ) );
	}

	/**
	 * After ten attempts the row stops being retried and is kept for the
	 * settings screen (and for the retention sweep) as a failure.
	 *
	 * @return void
	 */
	public function test_it_gives_up_after_ten_attempts() {
		$row = $this->queueOne();

		// Attempt 1 was the synchronous send; nine more make ten.
		for ( $i = 0; $i < 9; $i++ ) {
			$current = $this->onlyQueueRow();

			$this->assertSame( Send4_Queue::STATUS_PENDING, $current->status );

			$this->makeDue( $current );
			$this->http->queue500();

			Send4_Delivery::process_queue();
		}

		$final = $this->onlyQueueRow();

		$this->assertSame( 10, (int) $final->attempts );
		$this->assertSame( Send4_Queue::STATUS_FAILED, $final->status );
		$this->assertSame( $row->submission_id, $final->submission_id );

		// And it is never picked up again, however long we wait.
		$this->makeDue( $final );

		$this->assertSame( 0, Send4_Delivery::process_queue()['attempted'] );
	}

	/**
	 * The submission id is the unique key, so a second failure for the same
	 * submission updates one row rather than creating a second delivery.
	 *
	 * @return void
	 */
	public function test_enqueueing_the_same_submission_twice_keeps_one_row() {
		$envelope = array(
			'submission_id' => 'gravityforms:7:512:abcdef123456',
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => self::FORM_KEY,
				'name' => 'Roof quote',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);

		$first  = Send4_Queue::enqueue( $envelope, self::FORM_KEY, 'first failure' );
		$second = Send4_Queue::enqueue( $envelope, self::FORM_KEY, 'second failure' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * A row whose envelope cannot be read is parked rather than retried for a
	 * day and a half against a body that will never parse.
	 *
	 * @return void
	 */
	public function test_an_unreadable_envelope_is_parked() {
		global $wpdb;

		$row = $this->queueOne();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4_Queue::table(),
			array(
				'envelope'        => 'not json',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$before = $this->http->requestCount();

		$this->assertSame( 1, Send4_Delivery::process_queue()['failed'] );
		$this->assertSame( $before, $this->http->requestCount() );
		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
	}

	/**
	 * Counts drive the settings screen and `wp send4 status`.
	 *
	 * @return void
	 */
	public function test_it_counts_rows_by_status() {
		$this->queueOne();

		$counts = Send4_Queue::counts();

		$this->assertSame( 1, $counts[ Send4_Queue::STATUS_PENDING ] );
		$this->assertSame( 0, $counts[ Send4_Queue::STATUS_FAILED ] );
		$this->assertSame( 0, $counts[ Send4_Queue::STATUS_PERMANENT ] );
	}

	/**
	 * A bare envelope for tests that drive the queue directly.
	 *
	 * @param string $suffix Makes the submission id unique.
	 * @return array
	 */
	private function envelope( $suffix = 'a' ) {
		return array(
			'submission_id' => 'gravityforms:7:512:' . $suffix,
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => self::FORM_KEY,
				'name' => 'Roof quote',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);
	}

	/**
	 * Queue several due rows directly.
	 *
	 * @param int $count How many.
	 * @return array<int,object>
	 */
	private function queueDue( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			Send4_Queue::enqueue( $this->envelope( 'due' . $i ), self::FORM_KEY, 'Send4 returned status 500.' );
		}

		foreach ( $this->queueRows() as $row ) {
			$this->makeDue( $row );
		}

		return $this->queueRows();
	}

	/**
	 * The tenth failure used to be logged as "rescheduled" with the form's
	 * status left on queued, although nothing would ever try it again.
	 *
	 * @return void
	 */
	public function test_the_attempt_that_exhausts_the_retries_is_reported_as_failed() {
		global $wpdb;

		$row = $this->queueOne();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Send4_Queue::table(), array( 'attempts' => Send4_Queue::MAX_ATTEMPTS - 1 ), array( 'id' => (int) $row->id ) );
		$this->makeDue( $row );
		$this->http->queue500();

		Send4_Delivery::process_queue();

		$this->assertSame( Send4_Queue::STATUS_FAILED, $this->onlyQueueRow()->status );
		$this->assertSame( 'failed', Send4_Settings::form( self::FORM_KEY )['last_status'] );

		$entry = Send4_Logger::entries()[0];

		$this->assertSame( 'error', $entry['level'] );
		$this->assertStringContainsString( 'giving up after 10 attempts', $entry['message'] );
		$this->assertSame( 'failed', $entry['context']['status'] );
	}

	/**
	 * record_failure() says where the row ended up, so the caller can report it.
	 *
	 * @return void
	 */
	public function test_record_failure_returns_the_new_status() {
		$row = $this->queueOne();

		$this->assertSame( Send4_Queue::STATUS_PENDING, Send4_Queue::record_failure( $row, 'still down' ) );

		$row->attempts = Send4_Queue::MAX_ATTEMPTS - 1;

		$this->assertSame( Send4_Queue::STATUS_FAILED, Send4_Queue::record_failure( $row, 'still down' ) );
	}

	/**
	 * A sign-in outage is the same answer for every row. The worker used to
	 * ask the token endpoint once per row — rate limited to ten a minute — and
	 * charge each row an attempt for an answer that said nothing about it.
	 *
	 * @return void
	 */
	public function test_a_token_outage_leaves_the_batch_untouched() {
		$this->queueDue( 3 );
		$this->http->queue500();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );

		foreach ( $this->queueRows() as $row ) {
			$this->assertSame( 1, (int) $row->attempts, 'A row was charged an attempt for a sign-in failure.' );
			$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		}
	}

	/**
	 * A sign-in answered with 429 and a Retry-After is not tried again until
	 * the wait is over: the next run, and a visitor's submit, go without one.
	 *
	 * @return void
	 */
	public function test_runs_during_a_sign_in_retry_after_do_not_sign_in() {
		$this->queueDue( 2 );

		$limited            = Send4_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$limited['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '120',
		);
		$this->http->queue( $limited );

		Send4_Delivery::process_queue();
		Send4_Delivery::process_queue();

		$result = ( new Send4_Api_Client() )->submit( array( 'submission_id' => 'contact-form-7:1:1700000000:visitor' ) );

		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertSame( Send4_Api_Result::TRANSIENT, $result->outcome );
		$this->assertGreaterThan( 100, $result->retry_after );

		foreach ( $this->queueRows() as $row ) {
			$this->assertSame( 1, (int) $row->attempts );
			$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		}
	}

	/**
	 * Once the wait is over, sign-in is tried again.
	 *
	 * @return void
	 */
	public function test_sign_in_resumes_once_the_retry_after_has_passed() {
		$this->queueDue( 1 );

		set_transient(
			Send4_Api_Client::SIGN_IN_COOLDOWN,
			array(
				'until'    => time() - 1,
				'status'   => 429,
				'message'  => 'Too many sign-ins.',
				'bound_to' => 'whatever',
			),
			60
		);

		$this->http->queueToken();
		$this->http->queue202();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['delivered'] );
	}

	/**
	 * A reconnect is a different credential, with its own sign-in budget.
	 *
	 * @return void
	 */
	public function test_a_reconnect_clears_the_sign_in_wait() {
		$limited            = Send4_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$limited['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '120',
		);
		$this->http->queue( $limited );

		( new Send4_Api_Client() )->token();

		$this->assertNotFalse( get_transient( Send4_Api_Client::SIGN_IN_COOLDOWN ) );

		Send4_Api_Client::forget_token();

		$this->assertFalse( get_transient( Send4_Api_Client::SIGN_IN_COOLDOWN ) );
	}

	/**
	 * One healthy batch costs one token exchange, not one per row.
	 *
	 * @return void
	 */
	public function test_a_batch_exchanges_the_token_once() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queue202();
		$this->http->queue202();
		$this->http->queue202();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 3, $summary['delivered'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
	}

	/**
	 * During an outage every row behind the first would spend its own timeout
	 * on the same answer — twenty rows at ten seconds outlast WP-Cron's lock,
	 * and the next run starts sending the same rows.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_at_the_first_sign_send4_is_down() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queueTimeout();

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );

		$attempts = array_map(
			static function ( $row ) {
				return (int) $row->attempts;
			},
			$this->queueRows()
		);

		sort( $attempts );

		$this->assertSame( array( 1, 1, 2 ), $attempts, 'Only the row actually tried is charged an attempt.' );
	}

	/**
	 * Same for a 5xx.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_at_a_server_error() {
		$this->queueDue( 2 );
		$this->http->queueToken();
		$this->http->queue500();

		$this->assertSame( 1, Send4_Delivery::process_queue()['attempted'] );
	}

	/**
	 * A run stops starting new sends once its time budget is spent, so it
	 * finishes well inside WP-Cron's 60 second lock.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_when_its_time_budget_is_spent() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queue202();

		add_filter( 'send4_queue_time_budget', '__return_zero' );

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'], 'The first row is always attempted, so a run makes progress.' );
		$this->assertCount( 2, $this->queueRows() );
	}

	/**
	 * Two workers holding the same SELECT result must not both send a row.
	 * Only the claim that still matches the row as it was read wins.
	 *
	 * @return void
	 */
	public function test_a_row_can_be_claimed_only_once() {
		$rows     = $this->queueDue( 1 );
		$snapshot = Send4_Queue::due()[0];

		$this->assertSame( (int) $rows[0]->id, (int) $snapshot->id );
		$this->assertTrue( Send4_Queue::claim( $snapshot ), 'The first worker gets the row.' );
		$this->assertFalse( Send4_Queue::claim( $snapshot ), 'The second worker, holding the same snapshot, does not.' );
	}

	/**
	 * A claimed row is out of everybody else's batch while the lease runs.
	 *
	 * @return void
	 */
	public function test_a_claimed_row_is_skipped_by_an_overlapping_run() {
		$this->queueDue( 1 );

		$this->assertTrue( Send4_Queue::claim( Send4_Queue::due()[0] ) );

		$this->assertSame( 0, Send4_Delivery::process_queue()['attempted'] );
		$this->assertSame( 0, $this->http->requestCount() );

		$lease = strtotime( $this->onlyQueueRow()->next_attempt_at . ' UTC' );

		$this->assertEqualsWithDelta( time() + Send4_Queue::LEASE_SECONDS, $lease, 5 );
	}

	/**
	 * A worker that died mid-send needs no cleanup: once the lease runs out
	 * the row is due again.
	 *
	 * @return void
	 */
	public function test_an_expired_lease_makes_the_row_due_again() {
		$this->queueDue( 1 );
		$this->assertTrue( Send4_Queue::claim( Send4_Queue::due()[0] ) );

		$this->makeDue( $this->onlyQueueRow() );
		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4_Delivery::process_queue()['delivered'] );
	}

	/**
	 * A 429 says how long to wait. Retrying sooner earns another 429 and burns
	 * one of the ten attempts.
	 *
	 * @return void
	 */
	public function test_a_rate_limited_retry_waits_as_long_as_send4_asked() {
		$this->queueDue( 1 );
		$this->http->queueToken();

		$response            = Send4_Http_Recorder::response( 429, array( 'message' => 'Too Many Attempts.' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '7200',
		);
		$this->http->queue( $response );

		Send4_Delivery::process_queue();

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertEqualsWithDelta( time() + 7200, strtotime( $row->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * And the backoff still wins when it is the longer of the two.
	 *
	 * @return void
	 */
	public function test_the_backoff_wins_over_a_shorter_retry_after() {
		$row = $this->queueOne();

		Send4_Queue::record_failure( $row, 'slow down', 5 );

		$this->assertEqualsWithDelta( time() + 300, strtotime( $this->onlyQueueRow()->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * The every-minute event exists only while there is work for it.
	 *
	 * @return void
	 */
	public function test_the_event_is_dropped_once_the_queue_is_empty() {
		$this->queueDue( 1 );
		Send4_Cron::ensure_scheduled();

		$this->http->queueToken();
		$this->http->queue202();

		Send4_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
		$this->assertFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * But not while a lead is still waiting.
	 *
	 * @return void
	 */
	public function test_the_event_is_kept_while_a_lead_is_pending() {
		$this->queueDue( 1 );
		Send4_Cron::ensure_scheduled();

		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::process_queue();

		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * A disconnected site sends nothing, and must keep its pending leads'
	 * event for the moment it is reconnected.
	 *
	 * @return void
	 */
	public function test_a_disconnected_site_keeps_the_event_for_its_pending_leads() {
		$this->queueDue( 1 );
		Send4_Cron::ensure_scheduled();
		Send4_Settings::disconnect();

		Send4_Delivery::process_queue();

		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * Queuing a submission whose row was dead-lettered, for a reason that is
	 * retryable this time, puts it back in line. The docblock always promised
	 * an update; the code returned the old row untouched.
	 *
	 * @return void
	 */
	public function test_a_retryable_failure_revives_a_dead_lettered_row() {
		$first = Send4_Queue::enqueue( $this->envelope(), self::FORM_KEY, 'missing ability', Send4_Queue::STATUS_PERMANENT );
		$again = Send4_Queue::enqueue( $this->envelope(), self::FORM_KEY, 'Send4 returned status 503.' );

		$this->assertSame( $first, $again );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 'Send4 returned status 503.', $row->last_error );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertEqualsWithDelta( time() + 60, strtotime( $row->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * A pending row is left alone: it will get its own answer.
	 *
	 * @return void
	 */
	public function test_a_second_failure_leaves_a_pending_row_as_it_was() {
		Send4_Queue::enqueue( $this->envelope(), self::FORM_KEY, 'first failure' );
		Send4_Queue::enqueue( $this->envelope(), self::FORM_KEY, 'rejected', Send4_Queue::STATUS_PERMANENT );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 'first failure', $row->last_error );
	}
}
