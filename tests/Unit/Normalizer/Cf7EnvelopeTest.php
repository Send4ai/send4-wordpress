<?php
/**
 * Contact Form 7: adapter output through to the finished envelope.
 *
 * @package Send4
 */

/**
 * The golden envelope for the seeded Contact Form 7 form.
 */
class Send4_Cf7_Envelope_Test extends Send4_Test_Case {

	/**
	 * The whole envelope, field by field.
	 *
	 * @return void
	 */
	public function test_it_builds_the_expected_envelope() {
		$form     = Send4_Cf7_Factory::create();
		$posted   = $this->fixture( 'cf7/posted.php' );
		$adapter  = new Send4_Adapter_Cf7();
		$captured = $adapter->capture( $form, new Send4_Cf7_Submission_Double( $posted ) );

		$this->assertInstanceOf( 'Send4_Submission', $captured );
		$this->assertSame( 'contact-form-7:' . $form->id(), $captured->form_key );

		// Contact Form 7 stores no entries, so there is nothing to key on.
		$this->assertNull( $captured->entry_id );

		$envelope = Send4_Normalizer::build(
			$captured,
			array(
				'phone' => 'your-phone',
				'name'  => 'your-name',
				'email' => 'your-email',
			),
			'contact-form-7:' . $form->id() . ':1700000000:abcdef123456'
		);

		$this->assertSame(
			array(
				'submission_id' => 'contact-form-7:' . $form->id() . ':1700000000:abcdef123456',
				'site_url'      => 'http://example.org/',
				'form'          => array(
					'key'  => 'contact-form-7:' . $form->id(),
					'name' => 'Send4 test form',
				),
				'lead'          => array(
					'name'  => 'Jonas',
					'email' => 'j@x.lt',
					'phone' => '+37060012345',
				),
				'data'          => array(
					'your_message' => 'I want a quote for a new roof',
					'your_city'    => 'Vilnius',
					'your_topics'  => 'roof, gutters',
				),
			),
			$envelope
		);
	}

	/**
	 * The submit button and other answerless tags never reach the envelope.
	 *
	 * @return void
	 */
	public function test_it_ignores_tags_that_carry_no_answer() {
		$form     = Send4_Cf7_Factory::create();
		$captured = ( new Send4_Adapter_Cf7() )->capture(
			$form,
			new Send4_Cf7_Submission_Double( $this->fixture( 'cf7/posted.php' ) )
		);

		$ids = array_map(
			static function ( $field ) {
				return $field['id'];
			},
			$captured->fields
		);

		$this->assertSame(
			array( 'your-name', 'your-email', 'your-phone', 'your-message', 'your-city', 'your-topics' ),
			$ids
		);
	}
}
