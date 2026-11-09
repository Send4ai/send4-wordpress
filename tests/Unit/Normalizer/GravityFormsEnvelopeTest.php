<?php
/**
 * Gravity Forms: adapter output through to the finished envelope.
 *
 * @package Send4
 */

/**
 * The golden envelope for the Gravity Forms fixture.
 */
class Send4_Gravity_Forms_Envelope_Test extends Send4_Test_Case {

	/**
	 * The whole envelope, field by field.
	 *
	 * @return void
	 */
	public function test_it_builds_the_expected_envelope() {
		$form     = $this->fixture( 'gravityforms/form.php' );
		$entry    = $this->fixture( 'gravityforms/entry.php' );
		$captured = ( new Send4_Adapter_Gravityforms() )->capture( $entry, $form );

		$this->assertInstanceOf( 'Send4_Submission', $captured );
		$this->assertSame( 'gravityforms:7', $captured->form_key );
		$this->assertSame( '512', $captured->entry_id );

		$envelope = Send4_Normalizer::build(
			$captured,
			array(
				'phone' => '2',
				'name'  => '1',
				'email' => '3',
			),
			'gravityforms:7:512:abcdef123456'
		);

		$this->assertSame(
			array(
				'submission_id' => 'gravityforms:7:512:abcdef123456',
				'site_url'      => 'http://example.org/',
				'form'          => array(
					'key'  => 'gravityforms:7',
					'name' => 'Roof quote',
				),
				'lead'          => array(
					// The composite name field is stitched back together from
					// its sub-inputs, 1.3 and 1.6.
					'name'  => 'Jonas Jonaitis',
					'phone' => '+37060012345',
					'email' => 'j@x.lt',
				),
				'data'          => array(
					'what_do_you_need' => 'I want a quote for a new roof',
					'city'             => 'Vilnius',
				),
			),
			$envelope
		);
	}

	/**
	 * Gravity Forms still fires its hook for spam. Texting that person would be
	 * the worst possible outcome.
	 *
	 * @return void
	 */
	public function test_it_drops_entries_the_spam_filter_rejected() {
		$form           = $this->fixture( 'gravityforms/form.php' );
		$entry          = $this->fixture( 'gravityforms/entry.php' );
		$entry['status'] = 'spam';

		$this->assertNull( ( new Send4_Adapter_Gravityforms() )->capture( $entry, $form ) );
	}

	/**
	 * The data a Gravity Forms entry carries for each field type whose storage
	 * is not simply "the value under the field id" (#0698).
	 *
	 * Built from the Gravity Forms entry-object docs; the plugin itself is paid
	 * and not in the suite, so these shapes are documented, not observed.
	 *
	 * @see https://docs.gravityforms.com/entry-object/
	 *
	 * @return void
	 */
	public function test_each_field_type_arrives_as_its_real_value() {
		$form = array(
			'id'     => '9',
			'title'  => 'Everything',
			'fields' => array(
				array(
					'id'    => 1,
					'label' => 'Phone',
					'type'  => 'phone',
				),
				array(
					'id'      => 2,
					'label'   => 'Cities',
					'type'    => 'checkbox',
					'inputs'  => array(
						array( 'id' => '2.1' ),
						array( 'id' => '2.2' ),
						array( 'id' => '2.3' ),
					),
					'choices' => array(
						array( 'text' => 'New York', 'value' => 'New York' ),
						array( 'text' => 'Boston', 'value' => 'Boston' ),
						array( 'text' => 'Chicago', 'value' => 'Chicago' ),
					),
				),
				array(
					'id'     => 3,
					'label'  => 'Terms',
					'type'   => 'consent',
					'inputs' => array(
						array( 'id' => '3.1' ),
						array( 'id' => '3.2' ),
						array( 'id' => '3.3' ),
					),
				),
				array(
					'id'     => 4,
					'label'  => 'Call time',
					'type'   => 'time',
					'inputs' => array(
						array( 'id' => '4.1' ),
						array( 'id' => '4.2' ),
						array( 'id' => '4.3' ),
					),
				),
				array(
					'id'       => 5,
					'label'    => 'Visit date',
					'type'     => 'date',
					'dateType' => 'datedropdown',
					'inputs'   => array(
						array( 'id' => '5.1' ),
						array( 'id' => '5.2' ),
						array( 'id' => '5.3' ),
					),
				),
				array(
					'id'    => 6,
					'label' => 'Services',
					'type'  => 'multiselect',
				),
				array(
					'id'    => 7,
					'label' => 'Card',
					'type'  => 'creditcard',
				),
				array(
					'id'    => 8,
					'label' => 'Password',
					'type'  => 'password',
				),
				// A Post Custom Field drawn as checkboxes stores like one.
				array(
					'id'        => 9,
					'label'     => 'Extras',
					'type'      => 'post_custom_field',
					'inputType' => 'checkbox',
					'inputs'    => array(
						array( 'id' => '9.1' ),
						array( 'id' => '9.2' ),
					),
				),
				array(
					'id'    => 10,
					'label' => 'Legacy services',
					'type'  => 'multiselect',
				),
			),
		);

		$entry = array(
			'id'  => '77',
			'1'   => '+37060012345',
			'2.1' => 'New York',
			'2.2' => '',
			'2.3' => 'Boston',
			'3.1' => '1',
			'3.2' => 'I agree to the privacy policy',
			'3.3' => '3',
			'4'   => '10:30 am',
			'5'   => '2026-10-01',
			'6'   => '["install","repair"]',
			'7'   => 'XXXXXXXXXXXX1111',
			'7.4' => 'Visa',
			'8'   => 'hunter2',
			'9.1' => 'Gutters',
			'9.2' => 'Chimney',
			'10'  => 'install,repair',
		);

		$captured = ( new Send4_Adapter_Gravityforms() )->capture( $entry, $form );
		$envelope = Send4_Normalizer::build( $captured, array( 'phone' => '1' ), 'gravityforms:9:77:abc' );

		$this->assertSame(
			array(
				// ", " as every other plugin's multi-value field: "New York
				// Boston" was one city or two.
				'cities'          => 'New York, Boston',
				// The tick, not "1 I agree to the privacy policy 3".
				'terms'           => '1',
				// Stored under the field id despite declaring inputs.
				'call_time'       => '10:30 am',
				'visit_date'      => '2026-10-01',
				'services'        => 'install, repair',
				'extras'          => 'Gutters, Chimney',
				// Pre-2.5 storage is not JSON and passes through untouched.
				'legacy_services' => 'install,repair',
			),
			$envelope['data']
		);
	}

	/**
	 * An unticked consent sends nothing rather than its agreement text.
	 *
	 * @return void
	 */
	public function test_an_unticked_consent_is_empty() {
		$form = array(
			'id'     => '9',
			'title'  => 'Consent',
			'fields' => array(
				array(
					'id'     => 3,
					'label'  => 'Terms',
					'type'   => 'consent',
					'inputs' => array(
						array( 'id' => '3.1' ),
						array( 'id' => '3.2' ),
						array( 'id' => '3.3' ),
					),
				),
			),
		);

		$captured = ( new Send4_Adapter_Gravityforms() )->capture(
			array(
				'id'  => '78',
				'3.1' => '',
				'3.2' => 'I agree to the privacy policy',
				'3.3' => '3',
			),
			$form
		);

		$this->assertSame( '', $captured->fields[0]['value'] );
	}
}
