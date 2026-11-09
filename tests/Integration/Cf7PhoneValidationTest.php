<?php
/**
 * Contact Form 7: the phone check, through CF7's real submit pipeline.
 *
 * @package Send4
 */

/**
 * The step that proves the design (#0688): a real WPCF7_ContactForm, a real
 * submit(), and wpcf7_validate as Contact Form 7 itself fires it — so the hook
 * name, its arity, the tag object and the error shape are all checked for real.
 */
class Send4_Cf7_Phone_Validation_Test extends Send4_Test_Case {

	/**
	 * A national number: well-formed, dialable inside Lithuania, and exactly
	 * what arrives when a visitor leaves off the country code. Contact Form 7's
	 * own tel rule accepts it, which is the gap this closes.
	 */
	const BAD_PHONE = '060012345';

	/**
	 * Clear Contact Form 7's submission singleton, and look like a browser.
	 *
	 * See Send4_Cf7_Submit_Test::set_up() for why both are load-bearing.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Send4 test suite)';

		$instance = new ReflectionProperty( 'WPCF7_Submission', 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->registerAdapterHooks();
	}

	/**
	 * Leave no posted data behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		unset( $_SERVER['HTTP_USER_AGENT'] );

		parent::tear_down();
	}

	/**
	 * Put the fixture's answers where Contact Form 7 reads them.
	 *
	 * @param WPCF7_ContactForm $form  The form.
	 * @param array             $extra Values to override.
	 * @return void
	 */
	private function postFixture( $form, array $extra = array() ) {
		$posted = array_merge( $this->fixture( 'cf7/posted.php' ), $extra );

		$posted['_wpcf7']          = $form->id();
		$posted['_wpcf7_version']  = WPCF7_VERSION;
		$posted['_wpcf7_unit_tag'] = 'wpcf7-f' . $form->id() . '-o1';

		$_POST    = wp_slash( $posted );
		$_REQUEST = $_POST;
	}

	/**
	 * Enable a form with the phone check on or off.
	 *
	 * @param WPCF7_ContactForm $form     The form.
	 * @param bool              $validate Whether to check the phone.
	 * @param string            $phone    The field mapped as the phone.
	 * @return string Form key.
	 */
	private function enable( $form, $validate, $phone = 'your-phone' ) {
		$key = 'contact-form-7:' . $form->id();

		$this->enableForm( $key, array( 'phone' => $phone ), 'Send4 test form' );
		Send4_Settings::update_form( $key, array( 'validate_phone' => $validate ) );

		return $key;
	}

	/**
	 * With the check on, a number without a country code is sent back to the
	 * visitor with our message, and nothing reaches Send4.
	 *
	 * @return void
	 */
	public function test_a_national_number_is_refused_and_never_sent() {
		$form = Send4_Cf7_Factory::create();
		$key  = $this->enable( $form, true );

		$this->postFixture( $form, array( 'your-phone' => self::BAD_PHONE ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'validation_failed', $result['status'] );
		$this->assertSame(
			Send4_Phone_Guard::message( Send4_Phone_Validator::NO_COUNTRY_CODE, $key ),
			$result['invalid_fields']['your-phone']['reason']
		);
		$this->assertSame( 0, $this->http->requestCount() );

		// Not a delivery outcome: the form is working exactly as configured.
		$this->assertSame( '', Send4_Settings::form( $key )['last_status'] );

		$entry = Send4_Logger::entries()[0];

		$this->assertSame( $key, $entry['context']['form'] );
		$this->assertSame( Send4_Phone_Validator::NO_COUNTRY_CODE, $entry['context']['reason'] );
		$this->assertStringNotContainsString( '600', wp_json_encode( $entry ), 'The log promises no lead data.' );

		// Counted once, however many hooks Contact Form 7 validates through.
		$this->assertSame( array( 'refused' => 1 ), Send4_Stats::by_form( '30d' )[ $key ] );
	}

	/**
	 * With the check on, a good number goes through and is delivered once.
	 *
	 * @return void
	 */
	public function test_a_number_with_a_country_code_is_delivered() {
		$form = Send4_Cf7_Factory::create();
		$this->enable( $form, true );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$this->postFixture( $form );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
	}

	/**
	 * The opt-in proof: with the check off, the same national number submits
	 * and is delivered exactly as it was typed.
	 *
	 * @return void
	 */
	public function test_with_the_check_off_a_national_number_still_goes_through() {
		$form = Send4_Cf7_Factory::create();
		$this->enable( $form, false );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$this->postFixture( $form, array( 'your-phone' => self::BAD_PHONE ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions );
		$this->assertSame( self::BAD_PHONE, $submissions[0]['body']['lead']['phone'] );
	}

	/**
	 * The critical invariant: the check follows the mapping, never the field
	 * type. A customer collecting phone numbers in a plain [text] tag is who
	 * this is for.
	 *
	 * @return void
	 */
	public function test_it_validates_whatever_field_is_mapped_not_the_tel_tag() {
		$form = Send4_Cf7_Factory::create();
		$this->enable( $form, true, 'your-city' );

		$this->postFixture( $form, array( 'your-city' => self::BAD_PHONE ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'validation_failed', $result['status'] );
		$this->assertArrayHasKey( 'your-city', $result['invalid_fields'] );
		$this->assertArrayNotHasKey( 'your-phone', $result['invalid_fields'], 'The tel tag is not the mapped phone here.' );
	}

	/**
	 * Fail open: a mapping that points at a field the form no longer has must
	 * never block the form.
	 *
	 * @return void
	 */
	public function test_a_mapped_field_that_no_longer_exists_does_not_block_the_form() {
		$form = Send4_Cf7_Factory::create();
		$this->enable( $form, true, 'your-fax' );

		$this->postFixture( $form, array( 'your-fax' => self::BAD_PHONE ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
	}

	/**
	 * An optional phone field left blank still submits.
	 *
	 * @return void
	 */
	public function test_an_optional_phone_left_blank_still_submits() {
		$form = Send4_Cf7_Factory::create(
			'Optional phone',
			'<label>Your name [text* your-name]</label>
<label>Your phone [tel your-phone]</label>
[submit "Send"]'
		);
		$this->enable( $form, true );

		$this->postFixture( $form, array( 'your-phone' => '' ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
	}

	/**
	 * A required phone left blank gets Contact Form 7's own message, not a
	 * second one of ours stacked on the same field.
	 *
	 * @return void
	 */
	public function test_a_field_already_invalid_keeps_contact_form_7_s_message() {
		$form = Send4_Cf7_Factory::create();
		$key  = $this->enable( $form, true );

		$this->postFixture( $form, array( 'your-phone' => '' ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'validation_failed', $result['status'] );
		$this->assertNotSame(
			Send4_Phone_Guard::message( Send4_Phone_Validator::NO_COUNTRY_CODE, $key ),
			$result['invalid_fields']['your-phone']['reason']
		);
	}

	/**
	 * A form that has not been enabled for Send4 is never checked, whatever
	 * its stored flag says.
	 *
	 * @return void
	 */
	public function test_a_disabled_form_is_never_checked() {
		$form = Send4_Cf7_Factory::create();
		$key  = $this->enable( $form, true );

		Send4_Settings::update_form( $key, array( 'enabled' => false ) );

		$this->postFixture( $form, array( 'your-phone' => self::BAD_PHONE ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
		$this->assertSame( 0, $this->http->requestCount() );
	}
}
