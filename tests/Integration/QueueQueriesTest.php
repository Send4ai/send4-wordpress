<?php
/**
 * Every query the retry queue makes runs, after the move to %i.
 *
 * @package Send4
 */

/**
 * 0.2.1 names the queue table through $wpdb->prepare()'s %i placeholder
 * (WordPress 6.2) instead of interpolating it, so that Plugin Check sees every
 * statement as prepared (#1531). A mistake there is a SQL error the higher-level
 * tests could mistake for "no rows", so each method is run here on its own and
 * the database is asked whether it complained.
 */
class Send4_Queue_Queries_Test extends Send4_Test_Case {

	const FORM_KEY = 'contact-form-7:1';

	/**
	 * Put a row on the queue and make it due.
	 *
	 * @param string      $suffix    Makes the submission id unique.
	 * @param string      $status    Row status.
	 * @param string|null $client_id Owning client, null for the current connection.
	 * @return object The row.
	 */
	private function seed( $suffix, $status = Send4_Queue::STATUS_PENDING, $client_id = null ) {
		$submission_id = 'contact-form-7:1:1700000000:' . $suffix;

		Send4_Queue::enqueue(
			array(
				'submission_id' => $submission_id,
				'form'          => array( 'key' => self::FORM_KEY ),
				'lead'          => array( 'phone' => '+37060012345' ),
			),
			self::FORM_KEY,
			'Send4 returned status 500.',
			$status,
			$client_id
		);

		$this->makeDue( Send4_Queue::find_by_submission_id( $submission_id ) );

		return Send4_Queue::find_by_submission_id( $submission_id );
	}

	/**
	 * Set a row's timestamps, as if it were this old.
	 *
	 * @param object $row     Queue row.
	 * @param int    $seconds Age in seconds.
	 * @return void
	 */
	private function age( $row, $seconds ) {
		global $wpdb;

		$then = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4_Queue::table(),
			array(
				'created_at' => $then,
				'updated_at' => $then,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fail the test if the last statement was refused by the database.
	 *
	 * @param string $what Which call ran.
	 * @return void
	 */
	private function assertNoSqlError( $what ) {
		global $wpdb;

		$this->assertSame( '', (string) $wpdb->last_error, "{$what} made the database complain." );
	}

	/**
	 * The reads.
	 *
	 * @return void
	 */
	public function test_the_reads_run() {
		$this->assertFalse( Send4_Queue::has_pending() );
		$this->assertNoSqlError( 'has_pending, empty' );

		$first  = $this->seed( 'a' );
		$second = $this->seed( 'b', Send4_Queue::STATUS_FAILED );

		$this->assertTrue( Send4_Queue::has_pending() );
		$this->assertNoSqlError( 'has_pending' );

		$this->assertSame( (int) $first->id, (int) Send4_Queue::find_by_submission_id( $first->submission_id )->id );
		$this->assertNoSqlError( 'find_by_submission_id, any client' );

		$this->assertSame( (int) $first->id, (int) Send4_Queue::find_by_submission_id( $first->submission_id, 'test-client-id' )->id );
		$this->assertNoSqlError( 'find_by_submission_id, one client' );

		$this->assertNull( Send4_Queue::find_by_submission_id( $first->submission_id, 'someone-else' ) );

		$this->assertSame( array( (int) $first->id ), array_map( 'intval', wp_list_pluck( Send4_Queue::due( 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'due' );

		$this->assertSame( array( (int) $second->id, (int) $first->id ), array_map( 'intval', wp_list_pluck( Send4_Queue::all( 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'all' );

		$this->assertSame( array( (int) $second->id ), array_map( 'intval', wp_list_pluck( Send4_Queue::after( (int) $first->id, 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'after' );

		$counts = Send4_Queue::counts();
		$this->assertNoSqlError( 'counts' );
		$this->assertSame( 1, $counts[ Send4_Queue::STATUS_PENDING ] );
		$this->assertSame( 1, $counts[ Send4_Queue::STATUS_FAILED ] );
	}

	/**
	 * Claiming and postponing a row.
	 *
	 * @return void
	 */
	public function test_claim_and_postpone_run() {
		$row = $this->seed( 'a' );

		$this->assertTrue( Send4_Queue::claim( $row ) );
		$this->assertNoSqlError( 'claim' );
		$this->assertFalse( Send4_Queue::claim( $row ), 'A claimed row cannot be claimed again.' );

		$row = $this->seed( 'b' );
		Send4_Queue::postpone( (int) $row->id, HOUR_IN_SECONDS );
		$this->assertNoSqlError( 'postpone' );

		$after = Send4_Queue::find_by_submission_id( $row->submission_id );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS - 60 ), $after->next_attempt_at );
	}

	/**
	 * Moving rows between connections and back into line.
	 *
	 * @return void
	 */
	public function test_the_connection_updates_run() {
		$foreign = $this->seed( 'foreign', Send4_Queue::STATUS_PENDING, 'old-client' );
		$unowned = $this->seed( 'unowned', Send4_Queue::STATUS_PENDING, '' );

		$this->assertSame( 1, Send4_Queue::quarantine_foreign( 'test-client-id', 'Queued under another Send4 account.' ) );
		$this->assertNoSqlError( 'quarantine_foreign' );
		$this->assertSame( Send4_Queue::STATUS_PERMANENT, Send4_Queue::find_by_submission_id( $foreign->submission_id )->status );

		$this->assertSame( 1, Send4_Queue::adopt_unowned( 'test-client-id' ) );
		$this->assertNoSqlError( 'adopt_unowned' );
		$this->assertSame( 'test-client-id', Send4_Queue::find_by_submission_id( $unowned->submission_id )->client_id );

		$this->seed( 'dead', Send4_Queue::STATUS_FAILED );
		$this->assertSame( 1, Send4_Queue::requeue( 'test-client-id' ) );
		$this->assertNoSqlError( 'requeue' );
	}

	/**
	 * The sweeps and the clear-out.
	 *
	 * @return void
	 */
	public function test_the_deletes_run() {
		$old_failed    = $this->seed( 'old-failed', Send4_Queue::STATUS_FAILED );
		$old_permanent = $this->seed( 'old-permanent', Send4_Queue::STATUS_PERMANENT );
		$new_failed    = $this->seed( 'new-failed', Send4_Queue::STATUS_FAILED );
		$old_pending   = $this->seed( 'old-pending' );

		$this->age( $old_failed, 40 * DAY_IN_SECONDS );
		$this->age( $old_permanent, 40 * DAY_IN_SECONDS );
		$this->age( $old_pending, 40 * DAY_IN_SECONDS );

		$removed = Send4_Queue::purge_older_than( 30, array( Send4_Queue::STATUS_FAILED, Send4_Queue::STATUS_PERMANENT, Send4_Queue::STATUS_FAILED ) );
		$this->assertNoSqlError( 'purge_older_than' );
		$this->assertSame( 2, $removed, 'Both old dead letters, each status once, counted together.' );
		$this->assertNotNull( Send4_Queue::find_by_submission_id( $new_failed->submission_id ), 'A recent failure stays.' );
		$this->assertNotNull( Send4_Queue::find_by_submission_id( $old_pending->submission_id ), 'A status not asked for stays.' );

		$this->assertSame( 0, Send4_Queue::purge_older_than( 30, array() ) );

		$this->assertSame( 1, Send4_Queue::purge_stale_pending( 30 * DAY_IN_SECONDS ) );
		$this->assertNoSqlError( 'purge_stale_pending' );

		$this->seed( 'pending' );
		Send4_Queue::purge( Send4_Queue::STATUS_FAILED );
		$this->assertNoSqlError( 'purge, one status' );
		$this->assertSame( array( Send4_Queue::STATUS_PENDING ), wp_list_pluck( $this->queueRows(), 'status' ) );

		Send4_Queue::purge();
		$this->assertNoSqlError( 'purge, everything' );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * The schema statements: the legacy key comes off, the table drops and
	 * comes back.
	 *
	 * @return void
	 */
	public function test_the_schema_statements_run() {
		global $wpdb;

		// WP_UnitTestCase rewrites CREATE/DROP TABLE into their TEMPORARY forms,
		// under which dropping the real table is a silent no-op. Lifted for this
		// test only, as UninstallTest does; tear_down() puts them back.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$table = Send4_Queue::table();

		// Put schema 2's one-row-per-submission key back, as an old site has it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY submission_id (submission_id)', $table ) );
		$this->assertNoSqlError( 'adding the legacy key' );

		try {
			Send4_Queue::install();
			$this->assertNoSqlError( 'install' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'submission_id' ) );
			$this->assertSame( array(), $legacy, 'install() dropped the legacy key once submission_client existed.' );

			Send4_Queue::drop();
			$this->assertNoSqlError( 'drop' );
			$this->assertFalse( Send4_Queue::table_exists() );
		} finally {
			Send4_Queue::install();
		}

		$this->assertTrue( Send4_Queue::table_exists() );
		$this->assertNotFalse( $this->seed( 'after-reinstall' ) );
	}
}
