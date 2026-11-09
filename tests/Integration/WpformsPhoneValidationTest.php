<?php
/**
 * WPForms: the phone check, through the two hooks WPForms really fires.
 *
 * @package Send4
 */

/**
 * WPForms Lite is loaded for real by the bootstrap, so these go in through
 * do_action( 'wpforms_process_before' ) and apply_filters(
 * 'wpforms_process_initial_errors' ) with the arguments WPForms_Process passes,
 * in the order it passes them (#0688).
 */
class Send4_Wpforms_Phone_Validation_Test extends Send4_Test_Case {

	const FORM_KEY = 'wpforms:31';

	/**
	 * A national number. WPForms Lite has no phone field at all, so a Lite
	 * site collects this in a plain text field with no validation whatsoever.
	 */
	const BAD_PHONE = '060012345';

	/**
	 * WPForms is real, and the binding is registered fresh.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue( ( new Send4_Adapter_Wpforms() )->is_available() );

		$this->registerAdapterHooks();
	}

	/**
	 * Enable the fixture's form, phone check on.
	 *
	 * @param string $phone Field id mapped as the phone.
	 * @return void
	 */
	private function enableChecking( $phone = '2' ) {
		$this->enableForm( self::FORM_KEY, array( 'phone' => $phone ), 'Roof quote' );
		Send4_Settings::update_form( self::FORM_KEY, array( 'validate_phone' => true ) );
	}

	/**
	 * Run the two hooks the way WPForms_Process::process() does and return the
	 * errors it would act on.
	 *
	 * @param array $overrides Raw field values to change, by field id.
	 * @param array $form_data Form definition, defaults to the fixture's.
	 * @return array
	 */
	private function process( array $overrides = array(), $form_data = null ) {
		$fixture   = $this->fixture( 'wpforms/submission.php' );
		$form_data = null === $form_data ? $fixture['form_data'] : $form_data;
		$raw       = wp_list_pluck( $fixture['fields'], 'value' );

		foreach ( $overrides as $id => $value ) {
			$raw[ $id ] = $value;
		}

		$entry = array(
			'id'     => $form_data['id'],
			'fields' => $raw,
		);

		do_action( 'wpforms_process_before', $entry, $form_data );

		return apply_filters( 'wpforms_process_initial_errors', array(), $form_data );
	}

	/**
	 * A national number stops processing with our message on the phone field.
	 *
	 * @return void
	 */
	public function test_a_national_number_gets_an_error_on_the_phone_field() {
		$this->enableChecking();

		$errors = $this->process( array( 2 => self::BAD_PHONE ) );

		$this->assertSame(
			array(
				31 => array(
					2 => Send4_Phone_Guard::message( Send4_Phone_Validator::NO_COUNTRY_CODE, self::FORM_KEY ),
				),
			),
			$errors
		);
	}

	/**
	 * A good number leaves the errors exactly as WPForms had them.
	 *
	 * @return void
	 */
	public function test_a_number_with_a_country_code_adds_no_error() {
		$this->enableChecking();

		$this->assertSame( array(), $this->process() );
	}

	/**
	 * With the flag off, nothing is checked.
	 *
	 * @return void
	 */
	public function test_with_the_check_off_nothing_is_added() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => '2' ), 'Roof quote' );

		$this->assertSame( array(), $this->process( array( 2 => self::BAD_PHONE ) ) );
	}

	/**
	 * The mapping decides, not the type: a Lite site's phone is a text field.
	 *
	 * @return void
	 */
	public function test_it_validates_a_plain_text_field_mapped_as_the_phone() {
		$this->enableChecking( '4' );

		$errors = $this->process( array( 4 => self::BAD_PHONE ) );

		$this->assertArrayHasKey( 4, $errors[31] );
		$this->assertArrayNotHasKey( 2, $errors[31] );
	}

	/**
	 * Fail open: a mapped field the form no longer defines never gets an error,
	 * because it would render nowhere and the form could never be sent.
	 *
	 * @return void
	 */
	public function test_a_mapped_field_that_no_longer_exists_does_not_block_the_form() {
		$this->enableChecking( '9' );

		$this->assertSame( array(), $this->process( array( 9 => self::BAD_PHONE ) ) );
	}

	/**
	 * Two forms processed in one request keep their own answers.
	 *
	 * @return void
	 */
	public function test_two_forms_on_one_page_do_not_share_answers() {
		$this->enableChecking();

		$fixture         = $this->fixture( 'wpforms/submission.php' );
		$other_form      = $fixture['form_data'];
		$other_form['id'] = '32';

		// Form 32 is stashed first with a bad number and never checked; form
		// 31 then processes with a good one and must not inherit it.
		do_action( 'wpforms_process_before', array( 'fields' => array( 2 => self::BAD_PHONE ) ), $other_form );

		$this->assertSame( array(), $this->process() );
	}

	/**
	 * A field WPForms has already flagged keeps its own message.
	 *
	 * @return void
	 */
	public function test_it_does_not_stack_on_an_existing_error() {
		$this->enableChecking();

		$fixture = $this->fixture( 'wpforms/submission.php' );

		do_action( 'wpforms_process_before', array( 'fields' => array( 2 => self::BAD_PHONE ) ), $fixture['form_data'] );

		$errors = apply_filters( 'wpforms_process_initial_errors', array( 31 => array( 2 => 'This field is required.' ) ), $fixture['form_data'] );

		$this->assertSame( 'This field is required.', $errors[31][2] );
	}

	/**
	 * A refusal stops WPForms before wpforms_process_complete, so nothing is
	 * sent; this pins that the check itself makes no request either.
	 *
	 * @return void
	 */
	public function test_a_refusal_makes_no_request() {
		$this->enableChecking();

		$this->process( array( 2 => self::BAD_PHONE ) );

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( '', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}
}
