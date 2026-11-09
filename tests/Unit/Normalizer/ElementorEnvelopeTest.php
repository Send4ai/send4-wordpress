<?php
/**
 * Elementor Pro: adapter output through to the finished envelope.
 *
 * @package Send4
 */

/**
 * The golden envelope for the Elementor fixture.
 */
class Send4_Elementor_Envelope_Test extends Send4_Test_Case {

	/**
	 * The whole envelope, field by field.
	 *
	 * @return void
	 */
	public function test_it_builds_the_expected_envelope() {
		$record   = $this->fixture( 'elementor/record.php' );
		$captured = ( new Send4_Adapter_Elementor() )->capture( new Send4_Elementor_Record_Double( $record ) );

		$this->assertInstanceOf( 'Send4_Submission', $captured );
		$this->assertSame( 'elementor:a1b2c3d', $captured->form_key );

		// Elementor keeps submissions of its own, but the record does not carry
		// their id, so there is nothing stable to key on.
		$this->assertNull( $captured->entry_id );

		$envelope = Send4_Normalizer::build(
			$captured,
			array(
				'phone' => 'phone',
				'name'  => 'name',
				'email' => 'email',
			),
			'elementor:a1b2c3d:1700000000:abcdef123456'
		);

		$this->assertSame(
			array(
				'submission_id' => 'elementor:a1b2c3d:1700000000:abcdef123456',
				'site_url'      => 'http://example.org/',
				'form'          => array(
					'key'  => 'elementor:a1b2c3d',
					'name' => 'Contact',
				),
				'lead'          => array(
					'name'  => 'Jonas',
					'phone' => '+37060012345',
					'email' => 'j@x.lt',
				),
				'data'          => array(
					'what_do_you_need'        => 'I want a quote for a new roof',
					'city'                    => 'Vilnius',
					'i_agree_to_be_contacted' => 'on',
				),
			),
			$envelope
		);
	}
}
