<?php
/**
 * The per-form phone check settings, from the mapping screen to the option.
 *
 * @package Send4
 */

/**
 * Both flags are opt-in, both survive every save path, and neither can be on
 * for a form that is off or has no phone mapping (#0688).
 */
class Send4_Phone_Settings_Test extends Send4_Test_Case {

	/**
	 * A real Contact Form 7 form, so find_form() recognises the key.
	 *
	 * @var WPCF7_ContactForm
	 */
	private $cf7;

	/**
	 * Its form key.
	 *
	 * @var string
	 */
	private $form_key;

	/**
	 * An administrator, a real form, and a network that accepts the forms sync
	 * every save triggers.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->cf7      = Send4_Cf7_Factory::create();
		$this->form_key = 'contact-form-7:' . $this->cf7->id();

		$this->http->fallback( Send4_Http_Recorder::response( 200, array( 'access_token' => 't', 'expires_in' => 3600 ) ) );
	}

	/**
	 * Leave no posted data behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		unset( $_GET['form'] );
		GFAPI::$forms = array();

		parent::tear_down();
	}

	/**
	 * Both checks stand aside for a Gravity Forms phone field in its "standard"
	 * format, which takes (###) ###-#### and nothing else. The screen that
	 * switches them on is where the owner has to hear that, not the activity
	 * log a day later.
	 *
	 * @return void
	 */
	public function test_the_mapping_screen_says_when_a_us_only_field_turns_the_checks_off() {
		$this->enableForm( 'gravityforms:7', array( 'phone' => '2' ) );

		$_GET['form'] = 'gravityforms:7';

		$this->assertStringContainsString( 'Phone Format to International', $this->mappingScreen( 'standard' ) );
		$this->assertStringNotContainsString( 'Phone Format to International', $this->mappingScreen( 'international' ) );
	}

	/**
	 * The mapping screen of the Gravity Forms fixture form.
	 *
	 * @param string $format The phone field's Gravity Forms format.
	 * @return string
	 */
	private function mappingScreen( $format ) {
		$form = $this->fixture( 'gravityforms/form.php' );

		foreach ( $form['fields'] as $index => $field ) {
			if ( 'phone' === $field['type'] ) {
				$form['fields'][ $index ]['phoneFormat'] = $format;
			}
		}

		GFAPI::$forms = array( $form );
		Send4_Adapters::forget_forms();

		ob_start();

		try {
			( new Send4_Admin() )->render_forms();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * A form nobody configured checks nothing.
	 *
	 * @return void
	 */
	public function test_both_checks_are_off_by_default() {
		$form = Send4_Settings::form( $this->form_key );

		$this->assertFalse( $form['validate_phone'] );
		$this->assertSame(
			array(
				'picker'          => false,
				'default_country' => '',
			),
			$form['phone_field']
		);
	}

	/**
	 * The flags mean nothing on a form that is not sending, or that has no
	 * phone field to check.
	 *
	 * @return void
	 */
	public function test_a_form_that_is_off_or_unmapped_never_validates() {
		$flags = array(
			'validate_phone' => true,
			'phone_field'    => array( 'picker' => true ),
		);

		Send4_Settings::update_form( $this->form_key, array_merge( array( 'enabled' => false, 'mapping' => array( 'phone' => 'your-phone' ) ), $flags ) );

		$this->assertFalse( Send4_Settings::form_validates_phone( $this->form_key ) );
		$this->assertFalse( Send4_Settings::form_uses_phone_picker( $this->form_key ) );

		Send4_Settings::update_form( $this->form_key, array( 'enabled' => true, 'mapping' => array( 'phone' => '' ) ) );

		$this->assertFalse( Send4_Settings::form_validates_phone( $this->form_key ) );
		$this->assertFalse( Send4_Settings::form_uses_phone_picker( $this->form_key ) );

		Send4_Settings::update_form( $this->form_key, array( 'mapping' => array( 'phone' => 'your-phone' ) ) );

		$this->assertTrue( Send4_Settings::form_validates_phone( $this->form_key ) );
		$this->assertTrue( Send4_Settings::form_uses_phone_picker( $this->form_key ) );
	}

	/**
	 * Ticking the boxes on the mapping screen stores them.
	 *
	 * @return void
	 */
	public function test_saving_the_mapping_screen_stores_the_checks() {
		$this->save(
			array(
				'map_phone'             => 'your-phone',
				'enabled'               => '1',
				'validate_phone'        => '1',
				'phone_picker'          => '1',
				'phone_default_country' => 'lt',
			)
		);

		$form = Send4_Settings::form( $this->form_key );

		$this->assertTrue( $form['validate_phone'] );
		$this->assertTrue( $form['phone_field']['picker'] );
		$this->assertSame( 'lt', $form['phone_field']['default_country'] );
		$this->assertTrue( Send4_Settings::form_validates_phone( $this->form_key ) );
	}

	/**
	 * The enable-without-a-phone path redirects early with its own
	 * update_form() call. Forgetting the checks there would silently switch
	 * them off the next time the operator saved.
	 *
	 * @return void
	 */
	public function test_the_enable_without_phone_path_keeps_the_checks_too() {
		$location = $this->save(
			array(
				'map_phone'             => '',
				'enabled'               => '1',
				'validate_phone'        => '1',
				'phone_picker'          => '1',
				'phone_default_country' => 'gb',
			)
		);

		$this->assertStringContainsString( 'send4_notice=error', $location );

		$form = Send4_Settings::form( $this->form_key );

		$this->assertTrue( $form['validate_phone'] );
		$this->assertTrue( $form['phone_field']['picker'] );
		$this->assertSame( 'gb', $form['phone_field']['default_country'] );
	}

	/**
	 * Unticking turns them off again, and a country the picker does not know is
	 * not stored.
	 *
	 * @return void
	 */
	public function test_unticking_clears_them_and_an_unknown_country_is_dropped() {
		$this->save(
			array(
				'map_phone'             => 'your-phone',
				'enabled'               => '1',
				'validate_phone'        => '1',
				'phone_picker'          => '1',
				'phone_default_country' => 'lt',
			)
		);

		$this->save(
			array(
				'map_phone'             => 'your-phone',
				'enabled'               => '1',
				'phone_default_country' => 'zz',
			)
		);

		$form = Send4_Settings::form( $this->form_key );

		$this->assertFalse( $form['validate_phone'] );
		$this->assertFalse( $form['phone_field']['picker'] );
		$this->assertSame( '', $form['phone_field']['default_country'] );
	}

	/**
	 * Saving the checks does not change what Send4 is told about the form: the
	 * descriptors carry no flag, so no re-push is forced for them.
	 *
	 * @return void
	 */
	public function test_the_checks_do_not_change_the_form_descriptors() {
		$this->enableForm( $this->form_key, array( 'phone' => 'your-phone' ) );

		$before = Send4_Form_Descriptors::hash( Send4_Form_Descriptors::build() );

		Send4_Settings::update_form(
			$this->form_key,
			array(
				'validate_phone' => true,
				'phone_field'    => array(
					'picker'          => true,
					'default_country' => 'lt',
				),
			)
		);

		$this->assertSame( $before, Send4_Form_Descriptors::hash( Send4_Form_Descriptors::build() ) );
	}

	/**
	 * Post the mapping form to its handler and return where it redirected.
	 *
	 * @param array $fields Posted fields, beyond the action, nonce and key.
	 * @return string
	 */
	private function save( array $fields ) {
		$_POST = array_merge(
			array(
				'action'   => 'send4_save_mapping',
				'form_key' => $this->form_key,
				'_wpnonce' => wp_create_nonce( 'send4_save_mapping' ),
			),
			$fields
		);

		$_REQUEST = $_POST;

		$spy = static function ( $location ) {
			throw new Send4_Phone_Settings_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			( new Send4_Admin() )->handle_save_mapping();
		} catch ( Send4_Phone_Settings_Redirected $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->fail( 'handle_save_mapping() always redirects.' );
	}
}

/**
 * Thrown in place of the exit that follows the handler's redirect.
 */
class Send4_Phone_Settings_Redirected extends Exception {

	/**
	 * Where the redirect was headed.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * @param string $location Redirect target.
	 */
	public function __construct( $location ) {
		parent::__construct( 'Redirected to ' . $location );

		$this->location = $location;
	}
}
