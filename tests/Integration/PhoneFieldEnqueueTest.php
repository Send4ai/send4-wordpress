<?php
/**
 * The phone picker loads where a form asked for it, and nowhere else.
 *
 * @package Send4
 */

/**
 * The lazy-enqueue decision, pinned (#0688): the plugin's first front-end
 * asset must never cost a page that has no form wanting it.
 */
class Send4_Phone_Field_Enqueue_Test extends Send4_Test_Case {

	/**
	 * Fresh script and style registries, and no targets left from a previous
	 * test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->resetAssets();
	}

	/**
	 * Leave the registries as WordPress would build them.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->resetAssets();

		parent::tear_down();
	}

	/**
	 * @return void
	 */
	private function resetAssets() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		Send4_Phone_Field::reset();
	}

	/**
	 * A Contact Form 7 form enabled for Send4 with the given checks.
	 *
	 * @param bool   $picker   Country picker on.
	 * @param bool   $validate Refusing on.
	 * @param string $country  Saved default country.
	 * @return WPCF7_ContactForm
	 */
	private function cf7Form( $picker, $validate = false, $country = '' ) {
		$form = Send4_Cf7_Factory::create();

		$this->enableForm( 'contact-form-7:' . $form->id(), array( 'phone' => 'your-phone' ), 'Send4 test form' );

		Send4_Settings::update_form(
			'contact-form-7:' . $form->id(),
			array(
				'validate_phone' => $validate,
				'phone_field'    => array(
					'picker'          => $picker,
					'default_country' => $country,
				),
			)
		);

		return $form;
	}

	/**
	 * The config block as the footer would print it.
	 *
	 * @return string
	 */
	private function printedConfig() {
		ob_start();
		Send4_Phone_Field::print_config();

		return (string) ob_get_clean();
	}

	/**
	 * The decoded config, failing the test when there is none.
	 *
	 * @return array
	 */
	private function decodedConfig() {
		$printed = $this->printedConfig();

		$this->assertSame( 1, preg_match( '#<script type="application/json" id="send4-phone-config">(.*)</script>#s', $printed, $match ), 'No config block was printed.' );

		return json_decode( $match[1], true );
	}

	/**
	 * A form that opted in to the picker loads both handles and one target.
	 *
	 * @return void
	 */
	public function test_a_form_with_the_picker_on_loads_it() {
		$form = $this->cf7Form( true, true, 'lt' );

		$form->form_html();

		$this->assertTrue( wp_script_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertContains( Send4_Phone_Field::LIBRARY_HANDLE, wp_scripts()->registered[ Send4_Phone_Field::HANDLE ]->deps );

		$config = $this->decodedConfig();

		$this->assertCount( 1, $config['targets'] );
		$this->assertSame(
			array(
				'key'      => 'contact-form-7:' . $form->id(),
				'scope'    => '.wpcf7[data-wpcf7-id="' . $form->id() . '"], .wpcf7[id^="wpcf7-f' . $form->id() . '-"]',
				'input'    => '[name="your-phone"]',
				'country'  => 'lt',
				'validate' => true,
				'message'  => Send4_Phone_Guard::message( 'client', 'contact-form-7:' . $form->id() ),
			),
			$config['targets'][0]
		);
		$this->assertSame( '29', $config['major'] );
		$this->assertStringEndsWith( 'public/lib/intl-tel-input/js/utils.js?ver=' . Send4_Phone_Field::ITI_VERSION, $config['utilsUrl'] );
		$this->assertStringStartsWith( 'http', $config['utilsUrl'], 'utils.js is import()ed, so its URL must be absolute.' );
	}

	/**
	 * A form enabled for Send4 but without the picker loads nothing.
	 *
	 * @return void
	 */
	public function test_a_form_without_the_picker_loads_nothing() {
		$form = $this->cf7Form( false, true );

		$form->form_html();

		$this->assertFalse( wp_script_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->printedConfig() );
	}

	/**
	 * The regression test for the whole lazy-enqueue decision: a page with no
	 * form gets no script, no style and no config block, even on a site where
	 * a form elsewhere has the picker on.
	 *
	 * @return void
	 */
	public function test_a_page_with_no_form_loads_nothing() {
		$this->cf7Form( true, true );

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Send4_Phone_Field::HANDLE, 'registered' ), 'Registered early, so optimisers can see it.' );
		$this->assertFalse( wp_script_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Send4_Phone_Field::LIBRARY_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->printedConfig() );
	}

	/**
	 * The same form twice on a page is one target; its selector finds both.
	 *
	 * @return void
	 */
	public function test_the_same_form_twice_is_one_target() {
		$form = $this->cf7Form( true );

		$form->form_html();
		$form->form_html();

		$this->assertCount( 1, $this->decodedConfig()['targets'] );
	}

	/**
	 * WPForms, through its real render.
	 *
	 * @return void
	 */
	public function test_a_wpforms_form_registers_through_its_render_hook() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wpforms_encode(
					array(
						'id'       => (string) $post_id,
						'fields'   => array(
							'1' => array(
								'id'    => '1',
								'type'  => 'text',
								'label' => 'Phone',
							),
						),
						'settings' => array( 'form_title' => 'Roof quote' ),
					)
				),
			)
		);

		$this->enableForm( 'wpforms:' . $post_id, array( 'phone' => '1' ) );
		Send4_Settings::update_form( 'wpforms:' . $post_id, array( 'phone_field' => array( 'picker' => true ) ) );

		ob_start();
		wpforms_display( $post_id );
		ob_end_clean();

		$this->assertSame( 'wpforms:' . $post_id, $this->decodedConfig()['targets'][0]['key'] );
	}

	/**
	 * Gravity Forms and Elementor Pro, through their documented render hooks.
	 *
	 * @return void
	 */
	public function test_gravity_forms_and_elementor_register_through_their_render_hooks() {
		foreach ( array( 'gravityforms:7' => '2', 'elementor:a1b2c3d' => 'phone' ) as $key => $phone ) {
			$this->enableForm( $key, array( 'phone' => $phone ) );
			Send4_Settings::update_form( $key, array( 'phone_field' => array( 'picker' => true ) ) );
		}

		do_action( 'gform_enqueue_scripts', $this->fixture( 'gravityforms/form.php' ), false );

		$widget = new class() {
			/**
			 * Mirror of Element_Base::get_id().
			 *
			 * @return string
			 */
			public function get_id() {
				return 'a1b2c3d';
			}
		};

		// The name Elementor Pro's Form::render() really fires: a hyphen, where
		// its submission hooks use an underscore (#0697).
		do_action( 'elementor-pro/forms/pre_render', array(), $widget );

		$this->assertSame(
			array( 'gravityforms:7', 'elementor:a1b2c3d' ),
			wp_list_pluck( $this->decodedConfig()['targets'], 'key' )
		);
	}

	/**
	 * Gravity Forms' "standard" phone format, the one a new phone field starts
	 * with, accepts (###) ###-#### and nothing else. The picker rewrites the
	 * field to +12025550123, so with both on Gravity Forms itself refused every
	 * submission, whether or not Send4's own check was on. The field's format
	 * wins, as it does for validation (#0698): no picker, and the log says why.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Gravity Forms hands fields over as GF_Field objects;
	 *                        older releases and some add-ons as arrays.
	 * @return void
	 */
	public function test_a_gravity_forms_field_held_to_the_us_format_gets_no_picker( $as_object ) {
		$this->enableForm( 'gravityforms:7', array( 'phone' => '2' ) );
		Send4_Settings::update_form( 'gravityforms:7', array( 'phone_field' => array( 'picker' => true ) ) );
		Send4_Logger::clear();

		$form = $this->gravityForm( 'standard', $as_object );

		do_action( 'gform_enqueue_scripts', $form, false );
		do_action( 'gform_enqueue_scripts', $form, false );

		$this->assertNull( Send4_Phone_Field::config(), 'No target: the picker would make Gravity Forms refuse every number.' );
		$this->assertFalse( wp_script_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );

		$warnings = array_values(
			array_filter(
				Send4_Logger::entries(),
				static function ( $entry ) {
					return 'warning' === $entry['level'];
				}
			)
		);

		$this->assertCount( 1, $warnings, 'Said once a day, not on every page view.' );
		$this->assertStringContainsString( 'picker', $warnings[0]['message'] );
		$this->assertSame( 'gravityforms:7', $warnings[0]['context']['form'] );
		$this->assertSame( '2', $warnings[0]['context']['field'] );
	}

	/**
	 * The international format takes any number, so it keeps its picker.
	 *
	 * @dataProvider fieldShapeProvider
	 *
	 * @param bool $as_object Pass the fields as objects.
	 * @return void
	 */
	public function test_a_gravity_forms_field_in_the_international_format_keeps_its_picker( $as_object ) {
		$this->enableForm( 'gravityforms:7', array( 'phone' => '2' ) );
		Send4_Settings::update_form( 'gravityforms:7', array( 'phone_field' => array( 'picker' => true ) ) );

		do_action( 'gform_enqueue_scripts', $this->gravityForm( 'international', $as_object ), false );

		$this->assertSame( array( 'gravityforms:7' ), wp_list_pluck( $this->decodedConfig()['targets'], 'key' ) );
	}

	/**
	 * A form with the picker off is not inspected, and says nothing.
	 *
	 * @return void
	 */
	public function test_a_us_format_field_without_the_picker_is_not_reported() {
		$this->enableForm( 'gravityforms:7', array( 'phone' => '2' ) );
		Send4_Logger::clear();

		do_action( 'gform_enqueue_scripts', $this->gravityForm( 'standard', false ), false );

		$this->assertNull( Send4_Phone_Field::config() );
		$this->assertSame( array(), Send4_Logger::entries() );
	}

	/**
	 * The fixture form with its phone field set to a Gravity Forms format.
	 *
	 * @param string $format    `standard` or `international`.
	 * @param bool   $as_object Hand the fields over as objects.
	 * @return array
	 */
	private function gravityForm( $format, $as_object ) {
		$form = $this->fixture( 'gravityforms/form.php' );

		foreach ( $form['fields'] as $index => $field ) {
			if ( 'phone' === $field['type'] ) {
				$field['phoneFormat'] = $format;
			}

			$form['fields'][ $index ] = $as_object ? (object) $field : $field;
		}

		return $form;
	}

	/**
	 * Fields as arrays and as objects.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function fieldShapeProvider() {
		return array(
			'fields as arrays'  => array( false ),
			'fields as objects' => array( true ),
		);
	}

	/**
	 * The library lives under its own name and is kept off the global, which
	 * WPForms Pro's older copy may claim — and which a site's own snippet may
	 * call, on a page where its library is not loaded, to build a second picker
	 * out of ours (#0692). The name is claimed before our file runs, because
	 * the file ends `var intlTelInput = …` and that cannot be taken back.
	 *
	 * @return void
	 */
	public function test_the_library_is_aliased_away_from_the_shared_global() {
		Send4_Phone_Field::register_assets();

		$before = implode( '', (array) wp_scripts()->get_data( Send4_Phone_Field::LIBRARY_HANDLE, 'before' ) );
		$after  = implode( '', (array) wp_scripts()->get_data( Send4_Phone_Field::LIBRARY_HANDLE, 'after' ) );

		$this->assertStringContainsString( 'Object.defineProperty(w,"intlTelInput"', $before );
		$this->assertStringContainsString( 'if(loading){w.send4Iti=v;}', $before );
		// Where somebody else's `var` holds the name, the old swap still runs.
		$this->assertStringContainsString( 'w.send4Iti=w.intlTelInput', $after );
		$this->assertStringContainsString( 'w.send4ItiLoaded()', $after );
		$this->assertSame( Send4_Phone_Field::ITI_VERSION, wp_scripts()->registered[ Send4_Phone_Field::LIBRARY_HANDLE ]->ver );
	}

	/**
	 * A form or field id cannot close the config block early.
	 *
	 * @return void
	 */
	public function test_the_config_block_cannot_be_broken_out_of() {
		$attack = static function ( $targets ) {
			$targets[] = array(
				'scope' => '</script><script>alert(1)</script>',
				'input' => '[name="it\'s & <b>"]',
			);

			return $targets;
		};

		add_filter( 'send4_phone_targets', $attack );

		$printed = $this->printedConfig();

		remove_filter( 'send4_phone_targets', $attack );

		$this->assertSame( 1, substr_count( strtolower( $printed ), '</script>' ) );
		$this->assertStringNotContainsString( '<script>alert', $printed );

		$this->assertSame( '</script><script>alert(1)</script>', $this->decodedConfigFrom( $printed )['targets'][0]['scope'] );
	}

	/**
	 * A form declared by hand through the filter loads the picker too.
	 *
	 * @return void
	 */
	public function test_a_target_declared_through_the_filter_loads_the_picker() {
		$declare = static function ( $targets ) {
			$targets[] = array(
				'scope' => '[data-id="popup1"]',
				'input' => '[name="form_fields[phone]"]',
			);

			return $targets;
		};

		add_filter( 'send4_phone_targets', $declare );

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Send4_Phone_Field::HANDLE, 'enqueued' ) );

		remove_filter( 'send4_phone_targets', $declare );
	}

	/**
	 * Saved country, then the locale's region, then nothing — never a guess.
	 *
	 * @return void
	 */
	public function test_the_default_country_never_falls_back_to_a_guess() {
		$key = 'contact-form-7:77';

		$this->enableForm( $key, array( 'phone' => 'your-phone' ) );

		$locale = static function () {
			return 'en_GB';
		};

		add_filter( 'locale', $locale );
		$this->assertSame( 'gb', Send4_Phone_Field::default_country( $key ), 'The locale region when nothing is saved.' );

		Send4_Settings::update_form( $key, array( 'phone_field' => array( 'default_country' => 'lt' ) ) );
		$this->assertSame( 'lt', Send4_Phone_Field::default_country( $key ), 'A saved country beats the locale.' );
		remove_filter( 'locale', $locale );

		Send4_Settings::update_form( $key, array( 'phone_field' => array( 'default_country' => '' ) ) );

		$no_region = static function () {
			return 'ja';
		};

		add_filter( 'locale', $no_region );
		$this->assertSame( '', Send4_Phone_Field::default_country( $key ), 'No region, no country — and certainly not us.' );
		remove_filter( 'locale', $no_region );
	}

	/**
	 * Decode a printed config block.
	 *
	 * @param string $printed Printed markup.
	 * @return array
	 */
	private function decodedConfigFrom( $printed ) {
		preg_match( '#<script type="application/json" id="send4-phone-config">(.*)</script>#s', $printed, $match );

		return json_decode( $match[1], true );
	}
}
