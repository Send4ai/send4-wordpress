<?php
/**
 * Gravity Forms: the phone check, through gform_field_validation.
 *
 * @package Send4
 */

/**
 * Gravity Forms is paid, so the filter is fired by hand with its documented
 * arguments ($result, $value, $form, $field) (#0688). Each case runs twice:
 * once with the fixture's plain field array, and once with an object carrying
 * ->id and ->type, because real Gravity Forms passes GF_Field objects.
 */
class Send4_Gravity_Forms_Phone_Validation_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * A national number. Gravity Forms' own phone field in "international"
	 * format checks characters, not whether a country code is there.
	 */
	const BAD_PHONE = '060012345';

	/**
	 * The doubles make the adapter available; the binding is registered fresh.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue( ( new Send4_Adapter_Gravityforms() )->is_available() );

		$this->registerAdapterHooks();
	}

	/**
	 * The field shapes Gravity Forms may hand over.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function fieldShapeProvider() {
		return array(
			'plain array'     => array( false ),
			'GF_Field object' => array( true ),
		);
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
	 * Fire gform_field_validation for one field of the fixture form.
	 *
	 * @param int   $field_id  Field id.
	 * @param mixed $value     Submitted value.
	 * @param bool  $as_object Pass the field as an object.
	 * @param array $result    The result so far.
	 * @return array
	 */
	private function validateField( $field_id, $value, $as_object, array $result = array( 'is_valid' => true, 'message' => '' ) ) {
		$form  = $this->fixture( 'gravityforms/form.php' );
		$field = null;

		foreach ( $form['fields'] as $candidate ) {
			if ( $field_id === $candidate['id'] ) {
				$field = $candidate;
			}
		}

		$this->assertNotNull( $field, 'The fixture form has no field ' . $field_id );

		if ( $as_object ) {
			$field = (object) $field;
		}

		return apply_filters( 'gform_field_validation', $result, $value, $form, $field );
	}

	/**
	 * A national number in the mapped field is refused with our message.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_a_national_number_is_refused( $as_object ) {
		$this->enableChecking();

		$this->assertSame(
			array(
				'is_valid' => false,
				'message'  => Send4_Phone_Guard::message( Send4_Phone_Validator::NO_COUNTRY_CODE, self::FORM_KEY ),
			),
			$this->validateField( 2, self::BAD_PHONE, $as_object )
		);
	}

	/**
	 * A good number, and every other field, pass untouched.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_a_good_number_and_other_fields_pass( $as_object ) {
		$this->enableChecking();

		$valid = array(
			'is_valid' => true,
			'message'  => '',
		);

		$this->assertSame( $valid, $this->validateField( 2, '+370 (600) 12-345', $as_object ) );
		$this->assertSame( $valid, $this->validateField( 5, self::BAD_PHONE, $as_object ), 'City is not the mapped phone.' );
	}

	/**
	 * With the flag off, nothing is refused.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_with_the_check_off_nothing_is_refused( $as_object ) {
		$this->enableForm( self::FORM_KEY, array( 'phone' => '2' ), 'Roof quote' );

		$this->assertTrue( $this->validateField( 2, self::BAD_PHONE, $as_object )['is_valid'] );
	}

	/**
	 * A plain text field mapped as the phone is the one checked.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_it_validates_whatever_field_is_mapped( $as_object ) {
		$this->enableChecking( '5' );

		$this->assertFalse( $this->validateField( 5, self::BAD_PHONE, $as_object )['is_valid'] );
		$this->assertTrue( $this->validateField( 2, self::BAD_PHONE, $as_object )['is_valid'] );
	}

	/**
	 * Fail open: a mapping to a field the form no longer has refuses nothing.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_a_mapped_field_that_no_longer_exists_does_not_block_the_form( $as_object ) {
		$this->enableChecking( '99' );

		foreach ( array( 1, 2, 3, 4, 5 ) as $field_id ) {
			$this->assertTrue( $this->validateField( $field_id, self::BAD_PHONE, $as_object )['is_valid'] );
		}
	}

	/**
	 * A field Gravity Forms already refused keeps its own message.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_it_does_not_replace_gravity_forms_own_message( $as_object ) {
		$this->enableChecking();

		$already = array(
			'is_valid' => false,
			'message'  => 'This field is required.',
		);

		$this->assertSame( $already, $this->validateField( 2, '', $as_object, $already ) );
	}

	/**
	 * Gravity Forms' "standard" phone format accepts only (###) ###-####, and
	 * Send4 only numbers with a country code: with both on, no number passes
	 * and the form refuses every visitor. The field's own format wins, and the
	 * log says once why Send4 is not checking (#0698).
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the field as an object.
	 * @return void
	 */
	public function test_a_us_only_phone_field_is_left_to_gravity_forms( $as_object ) {
		$this->enableChecking();
		Send4_Logger::clear();

		$form  = $this->fixture( 'gravityforms/form.php' );
		$field = array(
			'id'          => 2,
			'label'       => 'Phone',
			'type'        => 'phone',
			'phoneFormat' => 'standard',
		);

		if ( $as_object ) {
			$field = (object) $field;
		}

		$valid = array(
			'is_valid' => true,
			'message'  => '',
		);

		// What Gravity Forms' standard format requires, and Send4 would refuse.
		$this->assertSame( $valid, apply_filters( 'gform_field_validation', $valid, '(415) 555-0132', $form, $field ) );
		$this->assertSame( $valid, apply_filters( 'gform_field_validation', $valid, '(415) 555-0133', $form, $field ) );

		$warnings = array_values(
			array_filter(
				Send4_Logger::entries(),
				static function ( $entry ) {
					return 'warning' === $entry['level'];
				}
			)
		);

		$this->assertCount( 1, $warnings, 'The warning is logged once, not per submission.' );
		$this->assertSame( self::FORM_KEY, $warnings[0]['context']['form'] );
		$this->assertSame( '2', $warnings[0]['context']['field'] );

		// The international format is still checked.
		$field = array(
			'id'          => 2,
			'label'       => 'Phone',
			'type'        => 'phone',
			'phoneFormat' => 'international',
		);

		$this->assertFalse( apply_filters( 'gform_field_validation', $valid, self::BAD_PHONE, $form, $as_object ? (object) $field : $field )['is_valid'] );
	}
}
