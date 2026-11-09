<?php
/**
 * Gravity Forms: from the plugin's own hook through to the POST.
 *
 * @package Send4
 */

/**
 * Gravity Forms is a paid plugin, so the suite reaches it through the class
 * doubles in tests/Support and through gform_after_submission itself. That is
 * enough to pin the two things the envelope tests cannot see: that the adapter
 * is hooked to the right action at all, and that a submission the spam filter
 * rejected produces no request.
 *
 * The spam guard matters more here than anywhere else in the plugin. Gravity
 * Forms is the only one of the four that still fires its hook for spam, and the
 * consequence of getting it wrong is not a lost lead — it is texting somebody
 * whose number a spambot typed in.
 */
class Send4_Gravity_Forms_Submit_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * The doubles have to make the adapter look installed.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			( new Send4_Adapter_Gravityforms() )->is_available(),
			'tests/Support/GravityFormsDouble.php should make the adapter available.'
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
				'name'  => '1',
				'email' => '3',
			),
			'Roof quote'
		);
	}

	/**
	 * Fire gform_after_submission exactly as Gravity Forms does.
	 *
	 * @param array $entry_overrides Values to change on the fixture entry.
	 * @return void
	 */
	private function fireHook( array $entry_overrides = array() ) {
		$entry = $this->fixture( 'gravityforms/entry.php' );

		// Assigned one by one rather than array_merge()d: Gravity Forms keys an
		// entry by field id, and array_merge() renumbers integer-like keys, so
		// merging would quietly move every answer to a different field.
		foreach ( $entry_overrides as $key => $value ) {
			$entry[ $key ] = $value;
		}

		do_action( 'gform_after_submission', $entry, $this->fixture( 'gravityforms/form.php' ) );
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

		$this->assertCount( 1, $submissions, 'gform_after_submission must reach Send4 exactly once.' );
		$this->assertSame( 'POST', $submissions[0]['method'] );

		$envelope = $submissions[0]['body'];

		// The hook runs after the entry is saved, so the id is stable.
		$this->assertStringStartsWith( self::FORM_KEY . ':512:', $envelope['submission_id'] );

		unset( $envelope['submission_id'] );

		$this->assertSame(
			array(
				'site_url' => 'http://example.org/',
				'form'     => array(
					'key'  => self::FORM_KEY,
					'name' => 'Roof quote',
				),
				'lead'     => array(
					// Stitched back together from the 1.3 and 1.6 sub-inputs.
					'name'  => 'Jonas Jonaitis',
					'phone' => '+37060012345',
					'email' => 'j@x.lt',
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
	 * Spam gets nothing: no token exchange, no POST, no queue row.
	 *
	 * @return void
	 */
	public function test_an_entry_the_spam_filter_rejected_sends_nothing() {
		$this->enableFixtureForm();
		$this->registerAdapterHooks();

		// Scripted, so that a lost guard shows up as a delivered submission
		// rather than as an unrelated "no response left" error.
		$this->http->queueToken();
		$this->http->queue202();

		$this->fireHook( array( 'status' => 'spam' ) );

		$this->assertSame(
			0,
			$this->http->requestCount(),
			'Gravity Forms fires this hook for spam too; texting that person would be the worst possible outcome.'
		);
		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( '', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * An entry the spam filter passed is not held back by the same guard.
	 *
	 * @return void
	 */
	public function test_an_entry_marked_active_is_still_delivered() {
		$this->enableFixtureForm();
		$this->registerAdapterHooks();

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$this->fireHook( array( 'status' => 'active' ) );

		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
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
}
