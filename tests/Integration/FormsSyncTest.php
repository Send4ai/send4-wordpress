<?php
/**
 * Reporting this site's forms to Send4.
 *
 * @package Send4
 */

/**
 * The sync is metadata, not a lead. So it must be stingy about when it fires,
 * silent when it fails, and it must never borrow the submissions retry queue —
 * putting a form list ahead of a customer's enquiry would be the wrong trade
 * every time.
 */
class Send4_Forms_Sync_Test extends Send4_Test_Case {

	/**
	 * Give the site one detectable form.
	 *
	 * @param bool $enabled Whether the form is switched on and mapped.
	 * @return void
	 */
	private function seedForm( $enabled = true ) {
		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);

		Send4_Settings::update_form(
			'fake-forms:1',
			array(
				'enabled' => $enabled,
				'mapping' => array( 'phone' => $enabled ? 'phone' : '' ),
			)
		);
	}

	/**
	 * Forget what a previous push recorded.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Send4_Forms_Sync::forget();
	}

	/**
	 * A token exchange plus an accepted report.
	 *
	 * @return void
	 */
	private function queueAcceptedReport() {
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );
	}

	public function test_it_reports_the_detected_forms() {
		$this->seedForm();
		$this->queueAcceptedReport();

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 1, $requests );

		// PUT, because it replaces the whole inventory: a form deleted in
		// WordPress must stop appearing on Send4's side, and there is no
		// per-form delete to send.
		$this->assertSame( 'PUT', $requests[0]['method'] );
		$this->assertSame( 'Bearer test-token', $requests[0]['headers']['Authorization'] );

		$body = $requests[0]['body'];
		$this->assertCount( 1, $body['forms'] );
		$this->assertSame( 'fake-forms:1', $body['forms'][0]['form_key'] );
		$this->assertTrue( $body['forms'][0]['enabled'] );
	}

	/**
	 * A site that never edits its forms should not pay a request per admin page
	 * view, which is the whole reason the hash exists.
	 *
	 * @return void
	 */
	public function test_an_unchanged_inventory_is_not_sent_again() {
		$this->seedForm();
		$this->queueAcceptedReport();
		Send4_Forms_Sync::maybe_push();

		// No responses queued: a second call would fail the recorder, which is
		// exactly the assertion.
		$this->assertNull( Send4_Forms_Sync::maybe_push() );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/forms' ) );
	}

	public function test_a_changed_inventory_is_sent_again() {
		$this->seedForm();
		$this->queueAcceptedReport();
		Send4_Forms_Sync::maybe_push();

		// Switching the form off changes what Send4 renders, so it has to go.
		Send4_Settings::update_form( 'fake-forms:1', array( 'enabled' => false ) );
		$this->queueAcceptedReport();

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 2, $requests );
		$this->assertFalse( $requests[1]['body']['forms'][0]['enabled'] );
	}

	public function test_a_forced_push_ignores_the_hash() {
		$this->seedForm();
		$this->queueAcceptedReport();
		Send4_Forms_Sync::maybe_push();

		$this->queueAcceptedReport();
		$this->assertTrue( Send4_Forms_Sync::maybe_push( true ) );
		$this->assertCount( 2, $this->http->requestsTo( '/api/integrations/wordpress/forms' ) );
	}

	/**
	 * A stale hash is what makes the next save or the daily sweep try again.
	 * Storing it on failure would strand the site until something changed.
	 *
	 * @return void
	 */
	public function test_a_failed_push_leaves_the_hash_alone_so_the_next_one_retries() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue500();

		$this->assertWPError( Send4_Forms_Sync::maybe_push() );
		$this->assertSame( 0, Send4_Forms_Sync::last_synced_at() );

		$this->queueAcceptedReport();
		$this->assertTrue( Send4_Forms_Sync::maybe_push() );
	}

	public function test_a_failure_is_logged_rather_than_thrown() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue500();

		Send4_Forms_Sync::maybe_push();

		$log = get_option( Send4_Logger::OPTION, array() );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString( 'forms', strtolower( $log[0]['message'] ) );
	}

	/**
	 * The submissions queue is for leads. A form list that could not be
	 * delivered must never take a slot in it.
	 *
	 * @return void
	 */
	public function test_a_failed_push_is_never_queued_for_retry() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue500();

		Send4_Forms_Sync::maybe_push();

		$this->assertSame( array( 0, 0, 0 ), array_values( Send4_Queue::counts() ) );
	}

	/**
	 * A hand-minted key authenticates but has no installation row, so Send4
	 * answers 409. Retrying would never help — the fix is to reconnect, and the
	 * message says so.
	 *
	 * @return void
	 */
	public function test_a_credential_with_no_install_explains_how_to_fix_it() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue(
			Send4_Http_Recorder::response(
				409,
				array(
					'status'  => 'rejected',
					'message' => 'This API key was not created by connecting a site, so there is nowhere to record its forms. Reconnect from the plugin to report them.',
				)
			)
		);

		$result = Send4_Forms_Sync::maybe_push();

		$this->assertWPError( $result );
		$this->assertSame( 'send4_no_install', $result->get_error_code() );
		// Send4's copy is product prose aimed at the site admin — show it, do
		// not paraphrase it into a status code.
		$this->assertStringContainsString( 'Reconnect', $result->get_error_message() );
	}

	public function test_a_missing_ability_says_which_permission_to_tick() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue403();

		$result = Send4_Forms_Sync::maybe_push();

		$this->assertWPError( $result );
		$this->assertSame( 'send4_missing_ability', $result->get_error_code() );
	}

	/**
	 * The cached token can expire between the transient and the call.
	 *
	 * @return void
	 */
	public function test_an_expired_token_is_refreshed_once_and_the_report_retried() {
		$this->seedForm();
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueToken( 'fresh-token' );
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 2, $requests );
		$this->assertSame( 'Bearer fresh-token', $requests[1]['headers']['Authorization'] );
	}

	public function test_a_disconnected_site_reports_nothing_at_all() {
		$this->seedForm();
		Send4_Settings::disconnect();

		// No responses queued — any request would fail the recorder.
		$this->assertNull( Send4_Forms_Sync::maybe_push( true ) );
		$this->assertSame( array(), $this->http->requestsTo( '/api/integrations/wordpress/forms' ) );
	}

	/**
	 * The next connection may be a different workspace, which has never been
	 * told anything about this site — so the next push must not be skipped as
	 * "unchanged".
	 *
	 * @return void
	 */
	public function test_forgetting_makes_the_next_push_unconditional() {
		$this->seedForm();
		$this->queueAcceptedReport();
		Send4_Forms_Sync::maybe_push();

		Send4_Forms_Sync::forget();
		$this->queueAcceptedReport();

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );
	}

	public function test_a_site_with_no_forms_still_reports_an_empty_list() {
		Send4_Adapters::reset();
		add_filter( 'send4_form_adapters', '__return_empty_array' );
		$this->queueAcceptedReport();

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );

		// An empty inventory is a real answer — it is how a form deleted in
		// WordPress stops appearing in Send4's builder.
		$this->assertSame( array(), $this->http->lastBody()['forms'] );
	}

	public function test_the_daily_sweep_is_scheduled_on_activation() {
		Send4_Forms_Sync::unschedule();
		$this->assertFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );

		Send4_Forms_Sync::ensure_scheduled();

		// Forms are created, renamed and deleted in the CMS with no hook this
		// plugin sees, so nothing else would ever notice.
		$this->assertNotFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}

	/**
	 * Updating a plugin runs NO activation hook. A site that was activated
	 * before this event existed would otherwise never schedule it, and would get
	 * no automatic form discovery at all until somebody deactivated and
	 * reactivated — which nobody would ever think to do.
	 *
	 * The queue's own event is not ensured here at all: it only exists while
	 * something is pending (see the tests below).
	 *
	 * @return void
	 */
	public function test_an_existing_install_schedules_the_sweep_on_a_normal_request() {
		Send4_Forms_Sync::unschedule();
		Send4_Cron::unschedule();

		// The schema is already current, so maybe_upgrade() takes its early
		// return — which is why scheduling cannot live behind that check.
		update_option( Send4_Installer::DB_VERSION_OPTION, Send4_Installer::DB_VERSION );
		Send4_Installer::maybe_upgrade();

		Send4_Installer::ensure_events();

		$this->assertNotFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );

		// An idle site must not wake WP-Cron every minute for an empty queue:
		// each tick used to cost a SELECT, a SHOW TABLES and a DELETE.
		$this->assertFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * The daily sweep is the queue event's safety net: whatever dropped it —
	 * a deactivation, a cron option rewritten by another plugin — a pending
	 * lead gets its worker back within a day.
	 *
	 * @return void
	 */
	public function test_the_daily_sweep_restores_the_queue_event_when_leads_are_pending() {
		Send4_Queue::enqueue(
			array(
				'submission_id' => 'fake-forms:1:1:pending',
				'lead'          => array( 'phone' => '+37060012345' ),
			),
			'fake-forms:1',
			'Send4 returned status 500.'
		);
		Send4_Cron::unschedule();

		$this->http->fallback( new WP_Error( 'http_request_failed', 'offline' ) );

		do_action( Send4_Forms_Sync::HOOK );

		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * And with nothing pending it leaves the queue event unscheduled.
	 *
	 * @return void
	 */
	public function test_the_daily_sweep_leaves_an_empty_queue_unscheduled() {
		Send4_Cron::unschedule();

		$this->http->fallback( new WP_Error( 'http_request_failed', 'offline' ) );

		do_action( Send4_Forms_Sync::HOOK );

		$this->assertFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
	}

	/**
	 * Scheduling runs the `cron_schedules` filter, and Send4_Cron's display name
	 * is translated — so doing it before the textdomain loads prints a
	 * `_load_textdomain_just_in_time` notice on the customer's own site, once
	 * after every plugin update. It belongs on `init`, never on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function test_events_are_ensured_on_init_not_before_the_textdomain() {
		$plugin = new Send4_Plugin();
		$plugin->boot();

		$this->assertNotFalse(
			has_action( 'init', array( $plugin, 'on_init' ) ),
			'on_init must be hooked, since that is where events are ensured.'
		);
		$this->assertFalse(
			has_action( 'plugins_loaded', array( 'Send4_Installer', 'ensure_events' ) ),
			'Scheduling must not run at plugins_loaded — the cron_schedules filter translates.'
		);

		Send4_Forms_Sync::unschedule();
		$plugin->on_init();

		$this->assertNotFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}

	/**
	 * The other half of the textdomain problem: another plugin can read the
	 * schedules itself before init (wp_get_schedules() from its own
	 * plugins_loaded code), which runs our filter however carefully we time
	 * our own scheduling. The display name must not be translated then.
	 *
	 * @return void
	 */
	public function test_the_schedule_name_is_not_translated_before_init() {
		global $wp_actions;

		$translated = 0;
		$counter    = static function ( $translation, $text, $domain ) use ( &$translated ) {
			if ( 'send4' === $domain ) {
				++$translated;
			}

			return $translation;
		};

		add_filter( 'gettext', $counter, 10, 3 );

		$init_count = isset( $wp_actions['init'] ) ? $wp_actions['init'] : null;
		unset( $wp_actions['init'] );

		$before = Send4_Cron::add_schedule( array() );

		$wp_actions['init'] = $init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( 0, $translated, 'Translating before init loads the textdomain just in time.' );
		$this->assertSame( 60, $before[ Send4_Cron::SCHEDULE ]['interval'] );
		$this->assertSame( 'Every minute (Send4)', $before[ Send4_Cron::SCHEDULE ]['display'] );

		Send4_Cron::add_schedule( array() );

		$this->assertSame( 1, $translated, 'After init the name is translated as usual.' );
	}

	public function test_scheduling_twice_does_not_double_book_the_sweep() {
		Send4_Forms_Sync::unschedule();
		Send4_Forms_Sync::ensure_scheduled();
		$first = wp_next_scheduled( Send4_Forms_Sync::HOOK );

		Send4_Forms_Sync::ensure_scheduled();

		$this->assertSame( $first, wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}
}
