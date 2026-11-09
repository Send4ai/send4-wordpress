<?php
/**
 * Elementor Pro: from the plugin's own hook through to the POST.
 *
 * @package Send4
 */

/**
 * Elementor Pro is a paid plugin, so the record is a double and the class the
 * adapter checks for is one too. What is real is the hook: this goes in through
 * do_action( 'elementor_pro/forms/new_record', … ) after asking Send4_Adapters
 * to wire the adapters up, so a renamed or mis-prioritised hook fails here.
 */
class Send4_Elementor_Submit_Test extends Send4_Test_Case {

	const FORM_KEY = 'elementor:a1b2c3d';

	/**
	 * The double has to make the adapter look installed.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			( new Send4_Adapter_Elementor() )->is_available(),
			'tests/Support/ElementorProDouble.php should make the adapter available.'
		);
	}

	/**
	 * Enable the fixture's form with the mapping an operator would choose.
	 *
	 * @return void
	 */
	private function enableFixtureForm() {
		$this->enableForm(
			self::FORM_KEY,
			array(
				'phone' => 'phone',
				'name'  => 'name',
				'email' => 'email',
			),
			'Contact'
		);
	}

	/**
	 * Fire elementor_pro/forms/new_record the way Elementor Pro does: the record
	 * first, then the form action handler the adapter has no use for.
	 *
	 * @return void
	 */
	private function fireHook() {
		do_action(
			'elementor_pro/forms/new_record',
			new Send4_Elementor_Record_Double( $this->fixture( 'elementor/record.php' ) ),
			null
		);
	}

	/**
	 * One submission, one POST, carrying the golden envelope.
	 *
	 * @return void
	 */
	public function test_a_submission_posts_the_golden_envelope_exactly_once() {
		$this->enableFixtureForm();
		$this->registerAdapterHooks();

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$this->fireHook();

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions, 'elementor_pro/forms/new_record must reach Send4 exactly once.' );
		$this->assertSame( 'POST', $submissions[0]['method'] );

		$envelope = $submissions[0]['body'];

		// The record carries no submission id, so the key is time plus random.
		$this->assertStringStartsWith( self::FORM_KEY . ':', $envelope['submission_id'] );

		unset( $envelope['submission_id'] );

		$this->assertSame(
			array(
				'site_url' => 'http://example.org/',
				'form'     => array(
					'key'  => self::FORM_KEY,
					'name' => 'Contact',
				),
				'lead'     => array(
					'name'  => 'Jonas',
					'phone' => '+37060012345',
					'email' => 'j@x.lt',
				),
				'data'     => array(
					'what_do_you_need'        => 'I want a quote for a new roof',
					'city'                    => 'Vilnius',
					'i_agree_to_be_contacted' => 'on',
				),
			),
			$envelope
		);

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'delivered', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * A form nobody enabled costs the site nothing.
	 *
	 * @return void
	 */
	public function test_a_submission_on_a_form_nobody_enabled_sends_nothing() {
		$this->registerAdapterHooks();

		$this->fireHook();

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * A Send4 outage queues the lead rather than losing it.
	 *
	 * @return void
	 */
	public function test_an_outage_queues_the_lead() {
		$this->enableFixtureForm();
		$this->registerAdapterHooks();

		$this->http->queueToken();
		$this->http->queue500();

		$this->fireHook();

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( self::FORM_KEY, $row->form_key );
		$this->assertSame( '+37060012345', Send4_Queue::envelope( $row )['lead']['phone'] );
	}
}
