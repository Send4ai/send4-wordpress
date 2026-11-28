<?php
/**
 * What Tools → Site Health says about Send4.
 *
 * @package Send4
 */

/**
 * Site Health is where a site owner looks when something is quietly wrong, and
 * where a support request starts. These tests pin down the two things that
 * make it worth having there: it reports what is actually stored, in the order
 * of how much is at stake, and it does so without asking Send4 anything or
 * showing a secret.
 */
class Send4_Site_Health_Test extends Send4_Test_Case {

	const FORM_KEY = 'contact-form-7:1';

	/**
	 * Start every test with nothing scheduled, so each one says what is.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_clear_scheduled_hook( Send4_Cron::HOOK );
	}

	/**
	 * A queue row.
	 *
	 * @param string $suffix Makes the submission id unique.
	 * @param string $status Queue status.
	 * @return void
	 */
	private function seed( $suffix, $status = Send4_Queue::STATUS_PENDING ) {
		$envelope = array(
			'submission_id' => 'contact-form-7:1:1700000000:' . $suffix,
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => self::FORM_KEY,
				'name' => 'Roof quote',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);

		$this->assertNotFalse( Send4_Queue::enqueue( $envelope, self::FORM_KEY, 'Send4 returned status 500.', $status ) );
	}

	/**
	 * Cache a failed handshake the way a Send4 screen would have.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status, or 0 for none.
	 * @return void
	 */
	private function cacheFailure( $code, $status = 0 ) {
		set_transient(
			Send4_Connection::HANDSHAKE_CACHE,
			array(
				Send4_Connection::HANDSHAKE_FAILURE_KEY => array(
					'code'    => $code,
					'message' => 'It failed.',
					'data'    => $status ? array( 'status' => $status ) : '',
				),
			),
			60
		);
	}

	/**
	 * Both tests are direct tests, next to whatever was there.
	 *
	 * @return void
	 */
	public function test_both_tests_are_registered_through_the_filter() {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array( 'someone_elses' => array( 'label' => 'Theirs' ) ),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( 'someone_elses', $tests['direct'] );
		$this->assertSame( array(), $tests['async'] );

		foreach ( array( 'send4_connection', 'send4_queue' ) as $test ) {
			$this->assertArrayHasKey( $test, $tests['direct'] );
			$this->assertIsCallable( $tests['direct'][ $test ]['test'] );
			$this->assertNotSame( '', $tests['direct'][ $test ]['label'] );
		}
	}

	/**
	 * Another plugin can leave the filter holding anything; that is not ours to
	 * repair, and not a reason to raise a warning on the Site Health screen.
	 *
	 * @return void
	 */
	public function test_a_filter_value_that_is_not_a_list_of_tests_is_left_alone() {
		$this->assertSame( 'broken', Send4_Site_Health::register_tests( 'broken' ) );
		$this->assertSame( array( 'direct' => 'broken' ), Send4_Site_Health::register_tests( array( 'direct' => 'broken' ) ) );
		$this->assertNull( Send4_Site_Health::debug_information( null ) );
	}

	/**
	 * An active plugin connected to nothing sends nothing, and says so.
	 *
	 * @return void
	 */
	public function test_a_site_with_no_connection_is_told_so() {
		Send4_Settings::disconnect();

		$result = Send4_Site_Health::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'send4_connection', $result['test'] );
		$this->assertStringContainsString( 'page=' . Send4_Admin::CONNECTION_SLUG, $result['actions'] );
	}

	/**
	 * A connected site is good, names the account, and admits what it has not
	 * checked.
	 *
	 * @return void
	 */
	public function test_a_connected_site_is_good_and_names_the_account() {
		$result = Send4_Site_Health::test_connection();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'Stogas UAB', $result['description'] );
		$this->assertStringContainsString( 'does not contact Send4', $result['description'] );
	}

	/**
	 * While a refused connection is known, it is the one critical state:
	 * submissions are refused too, not queued.
	 *
	 * @return void
	 */
	public function test_a_refused_connection_is_critical() {
		$this->cacheFailure( 'send4_token_rejected', 401 );

		$result = Send4_Site_Health::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( esc_html( Send4_Connection::outage_message( 'rejected' ) ), $result['description'] );
	}

	/**
	 * An outage queues submissions, so it is a recommendation, not an alarm.
	 *
	 * @return void
	 */
	public function test_an_unreachable_send4_is_a_recommendation() {
		$this->cacheFailure( 'http_request_failed' );

		$result = Send4_Site_Health::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( esc_html( Send4_Connection::outage_message( 'unreachable' ) ), $result['description'] );
	}

	/**
	 * The minimum version Send4 asked for outlives the handshake cache, so
	 * this shows a week later too. Forms keep sending: a recommendation.
	 *
	 * @return void
	 */
	public function test_a_plugin_older_than_send4_asks_for_is_a_recommendation() {
		update_option(
			Send4_Minimum_Version::OPTION,
			array(
				'minimum_version' => '99.0.0',
				'message'         => '',
				'checked_at'      => time(),
			)
		);

		$result = Send4_Site_Health::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '99.0.0', $result['description'] );
		$this->assertStringContainsString( 'update-core.php', $result['actions'] );
	}

	/**
	 * Send4 may word the request itself. That text came over the network and
	 * is drawn as HTML here, so it is escaped whatever it holds.
	 *
	 * @return void
	 */
	public function test_send4s_own_message_is_escaped() {
		update_option(
			Send4_Minimum_Version::OPTION,
			array(
				'minimum_version' => '99.0.0',
				'message'         => '<script>alert(1)</script>Update to 99.',
				'checked_at'      => time(),
			)
		);

		$result = Send4_Site_Health::test_connection();

		$this->assertStringNotContainsString( '<script>', $result['description'] );
		$this->assertStringContainsString( 'Update to 99.', $result['description'] );
	}

	/**
	 * Nothing queued is the ordinary state.
	 *
	 * @return void
	 */
	public function test_an_empty_queue_is_good() {
		$result = Send4_Site_Health::test_queue();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'send4_queue', $result['test'] );
		$this->assertSame( '', $result['actions'] );
	}

	/**
	 * A lead on its way, with its retry scheduled, needs nobody's attention.
	 *
	 * @return void
	 */
	public function test_a_submission_being_retried_on_schedule_is_good() {
		$this->seed( 'a' );
		wp_schedule_event( time() + 30, 'hourly', Send4_Cron::HOOK );

		$result = Send4_Site_Health::test_queue();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '1 form submission ', $result['description'] );
	}

	/**
	 * A lead waiting with no retry scheduled will wait for ever.
	 *
	 * @return void
	 */
	public function test_a_waiting_submission_with_nothing_scheduled_is_critical() {
		$this->seed( 'a' );
		$this->seed( 'b' );

		$result = Send4_Site_Health::test_queue();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '2 form submissions ', $result['description'] );
	}

	/**
	 * Scheduled is not the same as running: an event due every minute that is
	 * twenty minutes late means WP-Cron is not firing.
	 *
	 * @return void
	 */
	public function test_a_waiting_submission_whose_retry_is_long_overdue_is_critical() {
		$this->seed( 'a' );
		wp_schedule_event( time() - 20 * MINUTE_IN_SECONDS, 'hourly', Send4_Cron::HOOK );

		$this->assertSame( 'critical', Send4_Site_Health::test_queue()['status'] );
	}

	/**
	 * An overdue event with an empty queue is nobody's problem: the queue's
	 * event has nothing to do.
	 *
	 * @return void
	 */
	public function test_an_overdue_event_with_nothing_waiting_is_still_good() {
		wp_schedule_event( time() - 20 * MINUTE_IN_SECONDS, 'hourly', Send4_Cron::HOOK );

		$this->assertSame( 'good', Send4_Site_Health::test_queue()['status'] );
	}

	/**
	 * Leads that were never delivered are counted by how they ended, and the
	 * dashboard is where they can be sent again.
	 *
	 * @return void
	 */
	public function test_submissions_that_were_never_delivered_are_a_recommendation() {
		$this->seed( 'a', Send4_Queue::STATUS_FAILED );
		$this->seed( 'b', Send4_Queue::STATUS_FAILED );
		$this->seed( 'c', Send4_Queue::STATUS_PERMANENT );

		$result = Send4_Site_Health::test_queue();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'unreachable: 2.', $result['description'] );
		$this->assertStringContainsString( 'Refused by Send4: 1.', $result['description'] );
		$this->assertStringContainsString( 'page=' . Send4_Admin::PAGE_SLUG, $result['actions'] );
	}

	/**
	 * Without the table a lead submitted during an outage has nowhere to wait.
	 *
	 * @return void
	 */
	public function test_a_missing_queue_table_is_critical() {
		// The suite turns every CREATE and DROP into a TEMPORARY one, which
		// SHOW TABLES does not list: the real table has to go for this.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Send4_Queue::drop();

		try {
			$this->assertFalse( Send4_Queue::table_exists() );

			$result = Send4_Site_Health::test_queue();
			$info   = Send4_Site_Health::debug_information( array() );

			$this->assertSame( 'critical', $result['status'] );
			$this->assertSame( 'missing', $info['send4']['fields']['queue_table']['debug'] );
			$this->assertArrayNotHasKey( 'queue', $info['send4']['fields'] );
		} finally {
			Send4_Queue::install();
		}

		$this->assertTrue( Send4_Queue::table_exists() );
	}

	/**
	 * The Info tab carries what `wp send4 status` prints.
	 *
	 * @return void
	 */
	public function test_the_info_section_reports_the_stored_state() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => 'your-phone' ) );
		$this->seed( 'a' );
		$this->seed( 'b', Send4_Queue::STATUS_PERMANENT );

		$info = Send4_Site_Health::debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertArrayHasKey( 'wp-core', $info, 'The other sections are kept.' );

		$fields = $info['send4']['fields'];

		$this->assertSame( SEND4_VERSION, $fields['version']['value'] );
		$this->assertSame( 'unchecked', $fields['connection']['debug'] );
		$this->assertSame( 'Stogas UAB', $fields['account']['value'] );
		$this->assertSame( 'test-client-id', $fields['client_id']['value'] );
		$this->assertSame( Send4_Settings::base_url(), $fields['api_url']['value'] );
		$this->assertSame( '1 of 1', $fields['forms']['debug'] );
		$this->assertSame( Send4_Queue::table(), $fields['queue_table']['value'] );
		$this->assertSame( 'pending 1, failed 0, failed_permanent 1', $fields['queue']['debug'] );
		$this->assertSame( 'not scheduled', $fields['next_retry']['debug'] );
		$this->assertSame( 'active', $fields['adapter_contact-form-7']['debug'] );

		// One line per form plugin the plugin knows, installed or not.
		foreach ( Send4_Adapters::all() as $adapter ) {
			$field = $fields[ 'adapter_' . $adapter->slug() ];

			$this->assertSame( $adapter->name(), $field['label'] );
			$this->assertSame( $adapter->is_available() ? 'active' : 'not installed', $field['debug'] );
		}
	}

	/**
	 * "Copy site info to clipboard" is pasted into forums and tickets. What
	 * identifies the account stays out of it, and the secret is nowhere at all.
	 *
	 * @return void
	 */
	public function test_the_info_section_keeps_the_account_private_and_never_shows_the_secret() {
		$info   = Send4_Site_Health::debug_information( array() );
		$fields = $info['send4']['fields'];

		$this->assertTrue( $fields['account']['private'] );
		$this->assertTrue( $fields['client_id']['private'] );

		$everything = (string) wp_json_encode( $info ) . wp_json_encode( Send4_Site_Health::test_connection() ) . wp_json_encode( Send4_Site_Health::test_queue() );

		$this->assertStringNotContainsString( 'test-client-secret', $everything );
	}

	/**
	 * What "Copy site info to clipboard" actually produces, through WordPress's
	 * own formatter rather than by reading the fields back.
	 *
	 * It prints any value that is empty() as "undefined", a bare 0 included,
	 * which is how an empty queue first read as three unknowns.
	 *
	 * @return void
	 */
	public function test_the_copied_text_has_no_undefined_value_and_no_private_one() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';

		$copied = WP_Debug_Data::format( Send4_Site_Health::debug_information( array() ), 'debug' );

		$this->assertStringContainsString( 'queue: pending 0, failed 0, failed_permanent 0', $copied );
		$this->assertStringContainsString( 'connection: unchecked', $copied );
		$this->assertStringNotContainsString( 'undefined', $copied );
		$this->assertStringNotContainsString( 'Stogas UAB', $copied );
		$this->assertStringNotContainsString( 'test-client-id', $copied );
	}

	/**
	 * Opening Site Health, or WordPress running its weekly check, must not be
	 * a reason for a request to Send4: the readme lists every one the plugin
	 * makes, and this is not among them.
	 *
	 * @return void
	 */
	public function test_nothing_here_asks_send4_anything() {
		$this->seed( 'a' );

		Send4_Site_Health::test_connection();
		Send4_Site_Health::test_queue();
		Send4_Site_Health::debug_information( array() );

		$this->assertSame( 0, $this->http->requestCount() );
	}
}
