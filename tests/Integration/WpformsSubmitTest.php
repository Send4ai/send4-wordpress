<?php
/**
 * WPForms: from the plugin's own hook through to the POST.
 *
 * @package Send4
 */

/**
 * The envelope tests call the adapter's capture() directly, which proves the
 * mapping and proves nothing at all about the wiring. Rename the hook in
 * Send4_Adapter_Wpforms and every one of them still passes while the plugin
 * silently stops seeing WPForms submissions on a real site.
 *
 * So this one goes in through do_action(), with the arguments WPForms really
 * passes, after asking Send4_Adapters to register the hooks.
 */
class Send4_Wpforms_Submit_Test extends Send4_Test_Case {

	const FORM_KEY = 'wpforms:31';

	/**
	 * WPForms is a real dev dependency, so the adapter must consider it live.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			( new Send4_Adapter_Wpforms() )->is_available(),
			'WPForms Lite should be loaded by the test bootstrap.'
		);
	}

	/**
	 * A form stored as a wpforms post is discoverable.
	 *
	 * detect_forms() used to go through wpforms()->form, an internal that is
	 * null in some contexts — under WP-CLI, for one — so a site's forms were
	 * invisible on the settings screen with nothing to explain why. Reading the
	 * posts is the thing that actually holds still across versions.
	 *
	 * @return void
	 */
	public function test_a_stored_form_is_detected() {
		$fixture = require dirname( __DIR__ ) . '/fixtures/wpforms/submission.php';

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => 'Roof quote',
				'post_content' => wpforms_encode( $fixture['form_data'] ),
			)
		);

		$forms = ( new Send4_Adapter_Wpforms() )->detect_forms();
		$keys  = wp_list_pluck( $forms, 'key' );

		$this->assertContains( 'wpforms:' . $post_id, $keys );

		$found = $forms[ array_search( 'wpforms:' . $post_id, $keys, true ) ];

		$this->assertSame( 'Roof quote', $found['title'] );
		$this->assertSame(
			array( '0', '1', '2', '3', '4' ),
			wp_list_pluck( $found['fields'], 'id' ),
			'The divider field carries no answer and must not be offered for mapping.'
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
				'phone' => '2',
				'name'  => '0',
				'email' => '1',
			),
			'Roof quote'
		);
	}

	/**
	 * Fire wpforms_process_complete exactly as WPForms does.
	 *
	 * @param array|null $fields Processed fields, defaults to the fixture's.
	 * @return void
	 */
	private function fireHook( $fields = null ) {
		$fixture = $this->fixture( 'wpforms/submission.php' );

		// The second argument is the raw entry. The adapter deliberately ignores
		// it — the processed fields are the ones with labels and clean values —
		// but the hook passes it, so the test does too.
		do_action(
			'wpforms_process_complete',
			null === $fields ? $fixture['fields'] : $fields,
			array( 'fields' => wp_list_pluck( $fixture['fields'], 'value' ) ),
			$fixture['form_data'],
			$fixture['entry_id']
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

		$this->assertCount( 1, $submissions, 'wpforms_process_complete must reach Send4 exactly once.' );
		$this->assertSame( 'POST', $submissions[0]['method'] );

		$envelope = $submissions[0]['body'];

		// WPForms Pro stores entries, so the id is stable and Send4 can dedupe.
		$this->assertStringStartsWith( self::FORM_KEY . ':8171:', $envelope['submission_id'] );

		unset( $envelope['submission_id'] );

		$this->assertSame(
			array(
				'site_url' => 'http://example.org/',
				'form'     => array(
					'key'  => self::FORM_KEY,
					'name' => 'Roof quote',
				),
				'lead'     => array(
					'name'  => 'Jonas Jonaitis',
					'email' => 'j@x.lt',
					'phone' => '+37060012345',
				),
				'data'     => array(
					'what_do_you_need' => 'I want a quote for a new roof',
					'city'             => 'Vilnius',
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
	 * A Send4 outage queues the lead rather than losing it, and — because this
	 * hook runs inside the visitor's submit — must not raise anything.
	 *
	 * @return void
	 */
	public function test_an_outage_queues_the_lead() {
		$this->enableFixtureForm();
		$this->registerAdapterHooks();

		$this->http->queueToken();
		$this->http->queueTimeout();

		$this->fireHook();

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( self::FORM_KEY, $row->form_key );
		$this->assertSame( '+37060012345', Send4_Queue::envelope( $row )['lead']['phone'] );
	}
}
