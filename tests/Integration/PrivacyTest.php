<?php
/**
 * GDPR: disclosure, export, erasure and retention over the retry queue.
 *
 * @package Send4
 */

/**
 * The queue holds a lead's phone number, name and email until delivery
 * succeeds, so it is a place personal data accumulates and has to be reachable
 * by WordPress's own privacy tools.
 */
class Send4_Privacy_Test extends Send4_Test_Case {

	/**
	 * Put a row in the queue directly.
	 *
	 * @param string $email    Lead email.
	 * @param string $status   Row status.
	 * @param int    $age_days How long ago the row was last touched.
	 * @param string $suffix   Makes the submission id unique.
	 * @return int Row id.
	 */
	private function seedRow( $email, $status = Send4_Queue::STATUS_PENDING, $age_days = 0, $suffix = 'a' ) {
		global $wpdb;

		$when = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );

		$envelope = array(
			'submission_id' => 'contact-form-7:1:1700000000:' . $suffix,
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => 'contact-form-7:1',
				'name' => 'Send4 test form',
			),
			'lead'          => array(
				'name'  => 'Jonas',
				'email' => $email,
				'phone' => '+37060012345',
			),
			'data'          => array( 'your_city' => 'Vilnius' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Send4_Queue::table(),
			array(
				'submission_id'   => $envelope['submission_id'],
				'form_key'        => 'contact-form-7:1',
				'envelope'        => wp_json_encode( $envelope ),
				'attempts'        => 1,
				'status'          => $status,
				'next_attempt_at' => $when,
				'created_at'      => $when,
				'updated_at'      => $when,
				'last_error'      => 'Send4 returned status 500.',
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * WordPress has to know the plugin has an exporter and an eraser at all.
	 *
	 * @return void
	 */
	public function test_it_registers_an_exporter_and_an_eraser() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'send4', $exporters );
		$this->assertArrayHasKey( 'send4', $erasers );
		$this->assertIsCallable( $exporters['send4']['callback'] );
		$this->assertIsCallable( $erasers['send4']['callback'] );
	}

	/**
	 * The exporter returns what is still queued for that person.
	 *
	 * @return void
	 */
	public function test_the_exporter_returns_a_queued_lead() {
		$this->seedRow( 'j@x.lt' );

		$export = Send4_Privacy::export( 'j@x.lt' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );

		$item = $export['data'][0];

		$this->assertSame( 'send4-queue', $item['group_id'] );

		$values = wp_list_pluck( $item['data'], 'value', 'name' );

		$this->assertSame( 'contact-form-7:1', $values['Form'] );
		$this->assertSame( 'Jonas', $values['Name'] );
		$this->assertSame( 'j@x.lt', $values['Email'] );
		$this->assertSame( '+37060012345', $values['Phone'] );
		$this->assertSame( 'Vilnius', $values['your_city'] );
	}

	/**
	 * Somebody else's queued lead is not disclosed.
	 *
	 * @return void
	 */
	public function test_the_exporter_ignores_other_peoples_leads() {
		$this->seedRow( 'someone.else@x.lt' );

		$export = Send4_Privacy::export( 'j@x.lt' );

		$this->assertSame( array(), $export['data'] );
	}

	/**
	 * Email addresses are matched case-insensitively, the way people type them.
	 *
	 * @return void
	 */
	public function test_the_exporter_matches_regardless_of_case() {
		$this->seedRow( 'J@X.LT' );

		$this->assertCount( 1, Send4_Privacy::export( ' j@x.lt ' )['data'] );
	}

	/**
	 * The eraser deletes that person's queued leads and says so.
	 *
	 * @return void
	 */
	public function test_the_eraser_removes_a_queued_lead() {
		$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'a' );
		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'b' );

		$result = Send4_Privacy::erase( 'j@x.lt' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );

		$rows = $this->queueRows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'someone.else@x.lt', Send4_Queue::envelope( $rows[0] )['lead']['email'] );
	}

	/**
	 * With no email field mapped, the address travels as an ordinary answer —
	 * and that row still holds the person's phone and name.
	 *
	 * @return void
	 */
	public function test_an_address_among_the_answers_is_found_too() {
		global $wpdb;

		$id       = $this->seedRow( '', Send4_Queue::STATUS_PENDING, 0, 'unmapped' );
		$envelope = Send4_Queue::envelope( $this->queueRows()[0] );

		unset( $envelope['lead']['email'] );
		$envelope['data']['your_email'] = 'J@x.lt';
		$envelope['data']['topics']     = array( 'sales', 'other' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Send4_Queue::table(), array( 'envelope' => wp_json_encode( $envelope ) ), array( 'id' => $id ) );

		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'other' );

		$this->assertCount( 1, Send4_Privacy::export( 'j@x.lt' )['data'] );
		$this->assertTrue( Send4_Privacy::erase( 'j@x.lt' )['items_removed'] );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * Nothing to erase is not an error.
	 *
	 * @return void
	 */
	public function test_the_eraser_reports_nothing_removed_when_there_is_nothing() {
		$result = Send4_Privacy::erase( 'j@x.lt' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Retention: undeliverable rows go after seven days. Pending rows stay while
	 * the retry schedule could still be working them, and go once they are
	 * older than that schedule plus the same seven days — which is what a
	 * disconnected site's queue looks like, and it must not keep a lead's
	 * phone number for ever.
	 *
	 * @return void
	 */
	public function test_retention_deletes_old_failures_and_abandoned_pending_rows() {
		$old_failed     = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_FAILED, 8, 'a' );
		$old_permanent  = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PERMANENT, 30, 'b' );
		$old_pending    = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 30, 'c' );
		$new_failed     = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_FAILED, 1, 'd' );
		$recent_pending = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 8, 'e' );

		// Eight days is past RETENTION_DAYS but inside the retry span on top of
		// it, so the pending row above must survive.
		$this->assertGreaterThan(
			8 * DAY_IN_SECONDS,
			Send4_Queue::retry_span() + Send4_Privacy::RETENTION_DAYS * DAY_IN_SECONDS
		);

		$this->assertSame( 3, Send4_Privacy::enforce_retention() );

		$ids = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$this->queueRows()
		);

		sort( $ids );

		$this->assertSame( array( $new_failed, $recent_pending ), $ids );
		$this->assertNotContains( $old_failed, $ids );
		$this->assertNotContains( $old_permanent, $ids );
		$this->assertNotContains( $old_pending, $ids );
	}

	/**
	 * A pending row an operator requeued is being worked again, however old
	 * the lead itself is, so its created_at alone must not get it swept.
	 *
	 * @return void
	 */
	public function test_retention_keeps_an_old_row_that_was_requeued() {
		global $wpdb;

		$id = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 30, 'requeued' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Send4_Queue::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );

		$this->assertSame( 0, Send4_Privacy::enforce_retention() );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * The two events split the work: the queue event drains what is due, and
	 * the daily event sweeps what is past retention. The every-minute event
	 * only exists while something is pending, so it cannot be the one that
	 * guarantees a sweep.
	 *
	 * Asserted by firing the events rather than by reading has_action(): a
	 * priority literal is true of the wiring and says nothing about whether
	 * either callback runs, or whether a site that never opens wp-admin ends up
	 * holding a lead's phone number for ever.
	 *
	 * @return void
	 */
	public function test_the_queue_event_drains_and_the_daily_event_sweeps() {
		$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'due' );
		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_FAILED, 8, 'stale' );

		$this->assertCount( 2, $this->queueRows() );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		do_action( Send4_Cron::HOOK );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions, 'The due row should have been retried exactly once.' );
		$this->assertSame( 'contact-form-7:1:1700000000:due', $submissions[0]['body']['submission_id'] );
		$this->assertNull(
			Send4_Queue::find_by_submission_id( 'contact-form-7:1:1700000000:due' ),
			'The delivered row should have been removed, not merely marked.'
		);
		$this->assertSame( 'delivered', Send4_Settings::form( 'contact-form-7:1' )['last_status'] );

		// Not the every-minute event's job any more: a DELETE sixty times an
		// hour on an idle site was most of what that event cost.
		$this->assertNotNull( Send4_Queue::find_by_submission_id( 'contact-form-7:1:1700000000:stale' ) );

		do_action( Send4_Forms_Sync::HOOK );

		$this->assertSame(
			array(),
			$this->queueRows(),
			'The stale failure is swept by the daily event, so nothing is left holding lead data.'
		);
	}

	/**
	 * And the sweep happens when there is nothing to deliver, so a site whose
	 * queue has quietly filled with failures still forgets them.
	 *
	 * @return void
	 */
	public function test_the_daily_event_sweeps_even_with_nothing_due() {
		$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PERMANENT, 30, 'a' );

		do_action( Send4_Forms_Sync::HOOK );

		$this->assertSame( array(), $this->queueRows() );
		$this->assertCount(
			0,
			$this->http->requestsTo( '/api/integrations/wordpress/submissions' ),
			'A parked failure is never retried.'
		);
	}

	/**
	 * Retention on an empty queue is a no-op, not a warning.
	 *
	 * @return void
	 */
	public function test_retention_on_an_empty_queue_removes_nothing() {
		$this->assertSame( 0, Send4_Privacy::enforce_retention() );
	}

	/**
	 * A privacy request walks the whole queue, not the newest few hundred rows.
	 *
	 * The oldest row is the one a bounded scan misses, so it is the one seeded
	 * for the person asking. An eraser that answered "done" with that row still
	 * in the table would be retaining their data while reporting it gone.
	 *
	 * @return void
	 */
	public function test_a_privacy_request_reaches_rows_beyond_the_first_batch() {
		$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'oldest' );

		for ( $i = 0; $i < Send4_Privacy::SCAN_BATCH; $i++ ) {
			$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'filler-' . $i );
		}

		$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'newest' );

		$this->assertCount( Send4_Privacy::SCAN_BATCH + 2, $this->queueRows() );

		$this->assertCount( 2, $this->itemsOf( $this->runToTheEnd( 'export', 'j@x.lt' ) ) );

		$pages = $this->runToTheEnd( 'erase', 'j@x.lt' );

		$this->assertContains( true, wp_list_pluck( $pages, 'items_removed' ) );
		$this->assertCount( Send4_Privacy::SCAN_BATCH, $this->queueRows() );
		$this->assertSame( array(), $this->itemsOf( $this->runToTheEnd( 'export', 'j@x.lt' ) ), 'Nothing of theirs is left.' );
	}

	/**
	 * It reaches them a batch per call. Both callbacks used to ignore the page
	 * number and walk the whole table inside one request, decoding every
	 * envelope and holding every match before anything was exported or
	 * deleted. On the queue a long outage or a spam run leaves behind that is
	 * a timeout, and a privacy request that times out has done nothing.
	 *
	 * @return void
	 */
	public function test_each_call_reads_one_batch_and_asks_to_be_called_again() {
		$this->readTwoRowsPerCall();

		$first = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'theirs-1' );
		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'filler-1' );
		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'filler-2' );
		$this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'filler-3' );
		$last = $this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'theirs-2' );

		$one = Send4_Privacy::export( 'j@x.lt', 1 );

		$this->assertFalse( $one['done'], 'Three rows are still unread.' );
		$this->assertSame( array( 'send4-' . $first ), wp_list_pluck( $one['data'], 'item_id' ) );

		$two = Send4_Privacy::export( 'j@x.lt', 2 );

		$this->assertFalse( $two['done'] );
		$this->assertSame( array(), $two['data'] );

		// WordPress repeats a page whose answer it never received. The page
		// starts where it started the first time, not where the last call ended.
		$this->assertSame( $two, Send4_Privacy::export( 'j@x.lt', 2 ) );

		$three = Send4_Privacy::export( 'j@x.lt', 3 );

		$this->assertTrue( $three['done'] );
		$this->assertSame( array( 'send4-' . $last ), wp_list_pluck( $three['data'], 'item_id' ) );
	}

	/**
	 * The eraser deletes as it goes, so the rows still to be read move up the
	 * table underneath it. Paging by position would step over them, and answer
	 * "done" with the person's data still there; paging by id cannot.
	 *
	 * @return void
	 */
	public function test_erasing_as_it_goes_skips_nothing() {
		$this->readTwoRowsPerCall();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'theirs-' . $i );
		}

		$keep = $this->seedRow( 'someone.else@x.lt', Send4_Queue::STATUS_PENDING, 0, 'not-theirs' );

		$pages = $this->runToTheEnd( 'erase', 'j@x.lt' );

		$this->assertCount( 3, $pages, 'Six rows, two per call.' );
		$this->assertSame( array( $keep ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'id' ) ) );
	}

	/**
	 * Where each page starts is kept between calls, and a cache can lose it.
	 * A call that finds no record does not guess where it was: it walks what is
	 * left and answers "done". It may repeat itself; it can neither skip a row
	 * nor ask to be called for ever.
	 *
	 * @return void
	 */
	public function test_a_lost_cursor_ends_in_one_full_pass_rather_than_a_guess() {
		$this->readTwoRowsPerCall();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seedRow( 'j@x.lt', Send4_Queue::STATUS_PENDING, 0, 'theirs-' . $i );
		}

		$this->assertFalse( Send4_Privacy::erase( 'j@x.lt', 1 )['done'] );

		delete_transient( Send4_Privacy::CURSOR_TRANSIENT . md5( 'erase|j@x.lt' ) );

		$result = Send4_Privacy::erase( 'j@x.lt', 2 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * The record of where pages start is the request's, and goes with it. An
	 * export and an erasure for the same person keep separate ones.
	 *
	 * @return void
	 */
	public function test_the_cursor_is_per_request_and_removed_at_the_end() {
		$this->readTwoRowsPerCall();

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->seedRow( 'J@X.lt', Send4_Queue::STATUS_PENDING, 0, 'theirs-' . $i );
		}

		$export = Send4_Privacy::CURSOR_TRANSIENT . md5( 'export|j@x.lt' );
		$erase  = Send4_Privacy::CURSOR_TRANSIENT . md5( 'erase|j@x.lt' );

		Send4_Privacy::export( ' J@x.LT ', 1 );

		$this->assertIsArray( get_transient( $export ), 'Keyed by the address as it is compared: trimmed, lower case.' );
		$this->assertFalse( get_transient( $erase ) );

		Send4_Privacy::export( 'j@x.lt', 2 );

		$this->assertFalse( get_transient( $export ), 'Nothing is left behind once the request is done.' );
	}

	/**
	 * Read two rows per call, so a handful of rows is several pages.
	 *
	 * @return void
	 */
	private function readTwoRowsPerCall() {
		add_filter(
			'send4_privacy_batch',
			static function () {
				return 2;
			}
		);
	}

	/**
	 * Call an exporter or eraser the way WordPress does: page 1, 2, 3… until
	 * it answers "done".
	 *
	 * @param string $callback `export` or `erase`.
	 * @param string $email    The address.
	 * @return array<int,array> Every page's answer.
	 */
	private function runToTheEnd( $callback, $email ) {
		$pages = array();

		for ( $page = 1; $page <= 50; $page++ ) {
			$result  = call_user_func( array( 'Send4_Privacy', $callback ), $email, $page );
			$pages[] = $result;

			if ( ! empty( $result['done'] ) ) {
				return $pages;
			}
		}

		$this->fail( 'The callback never answered "done".' );
	}

	/**
	 * The exported items across every page of an export.
	 *
	 * @param array<int,array> $pages Answers from runToTheEnd( 'export', … ).
	 * @return array<int,array>
	 */
	private function itemsOf( array $pages ) {
		$items = array();

		foreach ( $pages as $page ) {
			$items = array_merge( $items, $page['data'] );
		}

		return $items;
	}
}
