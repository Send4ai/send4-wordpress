<?php
/**
 * The dashboard's outcome breakdown and per-form table.
 *
 * @package Send4
 */

/**
 * Numbers the plugin can stand behind: what settled, how much of it Send4
 * accepted, and which form it came from.
 */
class Send4_Dashboard_Outcomes_Test extends Send4_Test_Case {

	/**
	 * Run as an administrator, connected, with one detectable form.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);

		set_transient(
			Send4_Admin::HANDSHAKE_CACHE,
			array(
				'client' => array( 'name' => 'Stogas UAB' ),
				'flows'  => array(),
			),
			300
		);
	}

	/**
	 * Undo the request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Record one outcome several times.
	 *
	 * @param string $form_key Form key.
	 * @param string $status   Delivery status.
	 * @param int    $times    How many.
	 * @return void
	 */
	private function deliver( $form_key, $status, $times ) {
		for ( $i = 0; $i < $times; $i++ ) {
			Send4_Settings::record_delivery( $form_key, $status );
		}
	}

	/**
	 * The dashboard's markup.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();

		try {
			( new Send4_Admin() )->render_dashboard();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	public function test_with_nothing_settled_there_is_no_rate_rather_than_a_zero() {
		// Still on its way: neither accepted nor lost yet.
		$this->deliver( 'fake-forms:1', 'queued', 3 );

		$data = Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' );

		$this->assertNull( $data['rate'] );
		$this->assertSame( 0, $data['outcomes']['total'] );
	}

	public function test_the_rate_is_what_send4_accepted_out_of_what_settled() {
		$this->deliver( 'fake-forms:1', 'delivered', 196 );
		$this->deliver( 'fake-forms:1', 'duplicate', 3 );
		$this->deliver( 'fake-forms:1', 'failed', 1 );
		$this->deliver( 'fake-forms:1', 'queued', 40 );

		$data = Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' );

		// 199 of 200 is 99.5%, and with a failure on screen that is not "100%".
		$this->assertSame( 99, $data['rate'] );
		$this->assertSame( 200, $data['outcomes']['total'] );
		$this->assertSame(
			array(
				'delivered' => 196,
				'duplicate' => 3,
				'unrouted'  => 0,
				'failed'    => 1,
			),
			wp_list_pluck( $data['outcomes']['segments'], 'count', 'status' )
		);
	}

	public function test_forms_are_listed_busiest_first_with_their_last_outcome() {
		$this->deliver( 'fake-forms:1', 'delivered', 2 );
		$this->deliver( 'fake-forms:2', 'delivered', 5 );
		$this->deliver( 'fake-forms:2', 'unrouted', 1 );

		$forms = Send4_Admin_Dashboard::build( new Send4_Admin(), '7d' )['forms'];

		$this->assertSame( array( 'fake-forms:2', 'fake-forms:1' ), wp_list_pluck( $forms, 'key' ) );
		$this->assertSame( 5, $forms[0]['sent'] );
		$this->assertSame( 1, $forms[0]['unrouted'] );
		$this->assertSame( 'warn', $forms[0]['last_tone'] );
		$this->assertSame( 'ok', $forms[1]['last_tone'] );
	}

	public function test_the_table_stops_at_its_row_limit() {
		for ( $i = 1; $i <= Send4_Admin_Dashboard::FORM_ROWS + 3; $i++ ) {
			$this->deliver( 'fake-forms:' . $i, 'delivered', 1 );
		}

		$this->assertCount( Send4_Admin_Dashboard::FORM_ROWS, Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' )['forms'] );
	}

	/**
	 * Per-form counting starts at the upgrade, so until the range has passed
	 * the table adds up to less than the headline. It says so.
	 *
	 * @return void
	 */
	public function test_the_table_says_when_it_started_counting_until_the_range_has_passed() {
		$this->deliver( 'fake-forms:1', 'delivered', 1 );

		$this->assertNotSame( '', Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' )['forms_note'] );

		$stored                = get_option( Send4_Stats::OPTION );
		$stored['forms_since'] = time() - ( 8 * DAY_IN_SECONDS );
		update_option( Send4_Stats::OPTION, $stored, false );

		$this->assertSame( '', Send4_Admin_Dashboard::build( new Send4_Admin(), '7d' )['forms_note'] );
		$this->assertNotSame( '', Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' )['forms_note'] );
	}

	public function test_a_fresh_install_renders_both_cards_empty() {
		$html = $this->render();

		$this->assertStringContainsString( 'What happened to submissions', $html );
		$this->assertStringContainsString( 'No submissions in this range.', $html );
		$this->assertStringContainsString( 'No form has been submitted in this range.', $html );
		$this->assertStringNotContainsString( 'send4-bar__seg', $html );
	}

	public function test_the_cards_render_the_breakdown_the_hints_and_the_table() {
		$this->deliver( 'fake-forms:1', 'delivered', 8 );
		$this->deliver( 'fake-forms:1', 'unrouted', 2 );
		Send4_Stats::record( 'refused', 'fake-forms:1' );

		$html = $this->render();

		$this->assertStringContainsString( '80%', $html );
		$this->assertStringContainsString( 'send4-bar__seg--ok" style="flex-grow: 8;"', $html );
		$this->assertStringContainsString( 'send4-bar__seg--warn" style="flex-grow: 2;"', $html );
		$this->assertStringNotContainsString( 'send4-bar__seg--bad" style', $html );
		$this->assertStringContainsString( 'no flow was listening', $html );
		$this->assertStringContainsString( 'stopped by the phone check', $html );
		$this->assertStringContainsString( 'send4-table__num--warn', $html );
		$this->assertStringContainsString( 'Counted per form since', $html );
	}
}
