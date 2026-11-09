<?php
/**
 * WPForms: adapter output through to the finished envelope.
 *
 * @package Send4
 */

/**
 * The golden envelope for the WPForms fixture.
 */
class Send4_Wpforms_Envelope_Test extends Send4_Test_Case {

	/**
	 * The whole envelope, field by field.
	 *
	 * @return void
	 */
	public function test_it_builds_the_expected_envelope() {
		$fixture  = $this->fixture( 'wpforms/submission.php' );
		$captured = ( new Send4_Adapter_Wpforms() )->capture(
			$fixture['fields'],
			$fixture['form_data'],
			$fixture['entry_id']
		);

		$this->assertInstanceOf( 'Send4_Submission', $captured );
		$this->assertSame( 'wpforms:31', $captured->form_key );

		// WPForms Pro stores entries, so the id is stable and dedupe works.
		$this->assertSame( '8171', $captured->entry_id );

		$envelope = Send4_Normalizer::build(
			$captured,
			array(
				'phone' => '2',
				'name'  => '0',
				'email' => '1',
			),
			'wpforms:31:8171:abcdef123456'
		);

		$this->assertSame(
			array(
				'submission_id' => 'wpforms:31:8171:abcdef123456',
				'site_url'      => 'http://example.org/',
				'form'          => array(
					'key'  => 'wpforms:31',
					'name' => 'Roof quote',
				),
				'lead'          => array(
					'name'  => 'Jonas Jonaitis',
					'email' => 'j@x.lt',
					'phone' => '+37060012345',
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
	 * A form key with no id is not a form key.
	 *
	 * @return void
	 */
	public function test_it_refuses_form_data_without_an_id() {
		$fixture = $this->fixture( 'wpforms/submission.php' );

		unset( $fixture['form_data']['id'] );

		$this->assertNull(
			( new Send4_Adapter_Wpforms() )->capture( $fixture['fields'], $fixture['form_data'], 0 )
		);
	}

	/**
	 * A password and a card are never sent, and the container and note fields
	 * that never hold a value are never declared (#0698). Both sides skip the
	 * same types, so the descriptor still matches the payload.
	 *
	 * @return void
	 */
	public function test_secrets_and_valueless_fields_are_neither_declared_nor_sent() {
		$skipped = array( 'password', 'credit-card', 'stripe-credit-card', 'square', 'authorize_net', 'layout', 'repeater', 'internal-information' );
		$fields  = array(
			'1' => array(
				'id'    => '1',
				'type'  => 'phone',
				'label' => 'Phone',
			),
		);

		foreach ( $skipped as $index => $type ) {
			$fields[ (string) ( $index + 2 ) ] = array(
				'id'    => (string) ( $index + 2 ),
				'type'  => $type,
				'label' => 'Skipped ' . $type,
			);
		}

		$form_id = wp_insert_post(
			array(
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => 'Checkout',
				'post_content' => wp_slash(
					(string) wp_json_encode(
						array(
							'id'       => 1,
							'settings' => array( 'form_title' => 'Checkout' ),
							'fields'   => $fields,
						)
					)
				),
			)
		);

		$adapter  = new Send4_Adapter_Wpforms();
		$declared = array();

		foreach ( $adapter->detect_forms() as $form ) {
			if ( 'wpforms:' . $form_id === $form['key'] ) {
				$declared = wp_list_pluck( $form['fields'], 'type' );
			}
		}

		$this->assertSame( array( 'phone' ), $declared );

		$processed = array();

		foreach ( $fields as $id => $field ) {
			$processed[ $id ] = array(
				'name'  => $field['label'],
				'type'  => $field['type'],
				'value' => 'phone' === $field['type'] ? '+37060012345' : 'hunter2',
			);
		}

		$captured = $adapter->capture( $processed, array( 'id' => $form_id ), 0 );

		$this->assertSame( array( '1' ), wp_list_pluck( $captured->fields, 'id' ) );
	}
}
