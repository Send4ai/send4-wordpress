<?php
/**
 * Elementor Pro: the phone check, through elementor_pro/forms/validation.
 *
 * @package Send4
 */

/**
 * Elementor Pro is paid, so the record and the AJAX handler are doubles; what
 * is real is the hook, fired with the arguments Elementor Pro passes (#0688).
 */
class Send4_Elementor_Phone_Validation_Test extends Send4_Test_Case {

	const FORM_KEY = 'elementor:a1b2c3d';

	/**
	 * A national number. Elementor's tel field is an <input type="tel"> and
	 * checks nothing about a country code.
	 */
	const BAD_PHONE = '060012345';

	/**
	 * The double makes the adapter available; the binding is registered fresh.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue( ( new Send4_Adapter_Elementor() )->is_available() );

		$this->registerAdapterHooks();
	}

	/**
	 * Enable the fixture's form, phone check on.
	 *
	 * @param string $phone Field id mapped as the phone.
	 * @return void
	 */
	private function enableChecking( $phone = 'phone' ) {
		$this->enableForm( self::FORM_KEY, array( 'phone' => $phone ), 'Contact' );
		Send4_Settings::update_form( self::FORM_KEY, array( 'validate_phone' => true ) );
	}

	/**
	 * Fire the validation hook with the fixture record and return the handler.
	 *
	 * @param array $values Field values to change, by field id.
	 * @param array $errors Errors the handler already holds.
	 * @return Send4_Elementor_Ajax_Handler_Double
	 */
	private function fireValidation( array $values = array(), array $errors = array() ) {
		$data = $this->fixture( 'elementor/record.php' );

		foreach ( $values as $id => $value ) {
			$data['fields'][ $id ]['value']     = $value;
			$data['fields'][ $id ]['raw_value'] = $value;
		}

		$handler         = new Send4_Elementor_Ajax_Handler_Double();
		$handler->errors = $errors;

		do_action( 'elementor_pro/forms/validation', new Send4_Elementor_Record_Double( $data ), $handler );

		return $handler;
	}

	/**
	 * A national number gets our message on the mapped field.
	 *
	 * @return void
	 */
	public function test_a_national_number_gets_an_error() {
		$this->enableChecking();

		$this->assertSame(
			array( 'phone' => Send4_Phone_Guard::message( Send4_Phone_Validator::NO_COUNTRY_CODE, self::FORM_KEY ) ),
			$this->fireValidation( array( 'phone' => self::BAD_PHONE ) )->errors
		);
	}

	/**
	 * A good number adds nothing.
	 *
	 * @return void
	 */
	public function test_a_number_with_a_country_code_adds_no_error() {
		$this->enableChecking();

		$this->assertSame( array(), $this->fireValidation()->errors );
	}

	/**
	 * With the flag off, nothing is checked.
	 *
	 * @return void
	 */
	public function test_with_the_check_off_nothing_is_added() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => 'phone' ), 'Contact' );

		$this->assertSame( array(), $this->fireValidation( array( 'phone' => self::BAD_PHONE ) )->errors );
	}

	/**
	 * The mapping decides: a text field mapped as the phone is the one checked.
	 *
	 * @return void
	 */
	public function test_it_validates_whatever_field_is_mapped() {
		$this->enableChecking( 'city' );

		$errors = $this->fireValidation(
			array(
				'city'  => self::BAD_PHONE,
				'phone' => self::BAD_PHONE,
			)
		)->errors;

		$this->assertSame( array( 'city' ), array_keys( $errors ) );
	}

	/**
	 * Fail open: a mapping to a field the form no longer has adds no error.
	 *
	 * @return void
	 */
	public function test_a_mapped_field_that_no_longer_exists_does_not_block_the_form() {
		$this->enableChecking( 'fax' );

		$this->assertSame( array(), $this->fireValidation( array( 'phone' => self::BAD_PHONE ) )->errors );
	}

	/**
	 * A field Elementor already flagged keeps its own message.
	 *
	 * @return void
	 */
	public function test_it_does_not_replace_an_existing_error() {
		$this->enableChecking();

		$this->assertSame(
			array( 'phone' => 'This field is required.' ),
			$this->fireValidation( array( 'phone' => self::BAD_PHONE ), array( 'phone' => 'This field is required.' ) )->errors
		);
	}

	/**
	 * A handler without add_error() — a changed Elementor internal — is left
	 * alone rather than fatal inside somebody's form submit.
	 *
	 * @return void
	 */
	public function test_an_unexpected_handler_is_ignored() {
		$this->enableChecking();

		do_action(
			'elementor_pro/forms/validation',
			new Send4_Elementor_Record_Double( $this->fixture( 'elementor/record.php' ) ),
			new stdClass()
		);

		$this->assertSame( 0, $this->http->requestCount() );
	}
}
