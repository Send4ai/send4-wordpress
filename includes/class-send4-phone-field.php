<?php
/**
 * The country picker on a customer's own phone field.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The front-end half of phone validation (#0688): the plugin's first
 * front-end asset, loaded only on a page that renders a form that asked for it.
 *
 * Nothing is enqueued on wp_enqueue_scripts unconditionally. Each form plugin
 * has a hook that fires while it renders one form, and that is the only moment
 * this class learns a picker is wanted — which also bakes correctly into a
 * page cache, because "does this page contain form 12" is a property of the
 * page, not of the visitor. Parsing post content for shortcodes and blocks was
 * rejected: widgets, template parts, builder trees and popups make it wrong
 * often enough to be useless.
 *
 * ⚠️ One case this cannot cover: an Elementor popup fetched over admin-ajax
 * after the page loaded. Elementor's asset manager owns that response and
 * drops anything enqueued inside it. A site in that position declares the
 * form through the send4_phone_targets filter (see readme.txt).
 */
class Send4_Phone_Field {

	/**
	 * The bundled intl-tel-input release. Versions the vendor handles on their
	 * own, so a plugin release does not bust a 50 KB cache entry, and names the
	 * major the controller insists on.
	 */
	const ITI_VERSION = '29.2.3';

	const LIBRARY_HANDLE = 'send4-intl-tel-input';
	const HANDLE         = 'send4-phone-field';
	const FLAGS_HANDLE   = 'send4-phone-flags';
	const CONFIG_ID      = 'send4-phone-config';

	/**
	 * Every country the bundled picker can select, as lowercase ISO 3166-1
	 * alpha-2 codes (plus `ac` and `xk`, which intl-tel-input carries).
	 *
	 * Kept in lockstep with public/lib/intl-tel-input by
	 * PhoneFieldSelectorsTest::test_the_country_list_matches_the_picker, so a
	 * saved default can never name a country the picker would ignore.
	 *
	 * One string rather than an array literal: 244 two-letter items one per line
	 * would bury the class.
	 *
	 * @var string
	 */
	const COUNTRIES = '
		ac ad ae af ag ai al am ao ar as at au aw ax az ba bb bd be bf bg bh bi
		bj bl bm bn bo bq br bs bt bw by bz ca cc cd cf cg ch ci ck cl cm cn co
		cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg eh er es et fi fj fk fm
		fo fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gt gu gw gy hk hn hr ht
		hu id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw
		ky kz la lb lc li lk lr ls lt lu lv ly ma mc md me mf mg mh mk ml mm mn
		mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz
		om pa pe pf pg ph pk pl pm pr ps pt pw py qa re ro rs ru rw sa sb sc sd
		se sg sh si sj sk sl sm sn so sr ss st sv sx sy sz tc td tg th tj tk tl
		tm tn to tr tt tv tw tz ua ug us uy uz va vc ve vg vi vn vu wf ws xk ye
		yt za zm zw
	';

	/**
	 * Forms rendered on this request that want a picker, keyed by form key.
	 *
	 * @var array<string,array>
	 */
	private static $targets = array();

	/**
	 * COUNTRIES split into a list, once per request.
	 *
	 * @var string[]|null
	 */
	private static $countries = null;

	/**
	 * The same list as keys, for is_country().
	 *
	 * @var array<string,int>|null
	 */
	private static $country_lookup = null;

	/**
	 * Register the render hooks. Reads no options: send4_forms is not
	 * autoloaded, so it is read the first time a form actually renders.
	 *
	 * Called on every request, not only when ! is_admin(): Elementor renders
	 * popups through admin-ajax.php, where is_admin() is true.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_declared' ), 20 );

		// Contact Form 7: filters the form's inner markup once per render,
		// with the form as WPCF7_ContactForm::get_current(). Returned
		// untouched. Fallback if it moves: wpcf7_form_class_attr.
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'on_cf7_render' ), 20 );

		// WPForms: the action that prints the form, carrying $form_data.
		add_action( 'wpforms_frontend_output', array( __CLASS__, 'on_wpforms_render' ), 30, 1 );

		// Gravity Forms: exists for exactly this, once per form on the page.
		// Fallback if it moves: gform_pre_render.
		add_action( 'gform_enqueue_scripts', array( __CLASS__, 'on_gravityforms_render' ), 10, 1 );

		// Elementor Pro: fired from the Form widget's render() with the settings
		// and the widget, outside the editor. ⚠️ A HYPHEN, unlike its
		// submission hooks (elementor_pro/forms/validation, …/new_record):
		// modules/forms/widgets/form.php calls
		// do_action( 'elementor-pro/forms/pre_render', $instance, $this ), and
		// Elementor Pro's own popup form action listens on the same name. The
		// underscore spelling fires nowhere: registered here until #0697, it
		// left every Elementor form without a picker. Fallback if it moves:
		// elementor/frontend/widget/before_render, filtered on
		// $widget->get_name() === 'form'.
		add_action( 'elementor-pro/forms/pre_render', array( __CLASS__, 'on_elementor_render' ), 10, 2 );

		// After Elementor's wp_footer:10 popups, before core prints footer
		// scripts at wp_print_footer_scripts:10.
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'print_config' ), 5 );
	}

	/**
	 * Register the handles without enqueueing them, early enough for
	 * optimisers to see the dependency graph.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		$lib = SEND4_PLUGIN_URL . 'public/lib/intl-tel-input/';
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Not the vendored stylesheet: its selectors are bare, so on a site that
		// runs its own copy of the picker ours restyled theirs, wrong flags and
		// all. This is the same sheet limited to .send4-iti, generated by
		// bin/build-scoped-picker-css.php.
		wp_register_style( self::LIBRARY_HANDLE, Send4_Assets::url( 'public/css/intl-tel-input-scoped.css' ), array(), SEND4_VERSION . '-' . self::ITI_VERSION );
		// Generated by bin/build-flag-css.php: the flag table again, scoped to
		// our picker, so another plugin's older copy of the stylesheet cannot
		// repaint every flag as the first one in the sprite.
		wp_register_style( self::FLAGS_HANDLE, Send4_Assets::url( 'public/css/phone-flags.css' ), array( self::LIBRARY_HANDLE ), SEND4_VERSION . '-' . self::ITI_VERSION );
		wp_register_style( self::HANDLE, Send4_Assets::url( 'public/css/phone-field.css' ), array( self::LIBRARY_HANDLE, self::FLAGS_HANDLE ), SEND4_VERSION );

		// A plain true rather than array( 'strategy' => 'defer' ): the floor is
		// WordPress 6.0, where the fifth argument is a bool.
		wp_register_script( self::LIBRARY_HANDLE, $lib . 'js/intlTelInput' . $min . '.js', array(), self::ITI_VERSION, true );
		wp_register_script( self::HANDLE, Send4_Assets::url( 'public/js/phone-field.js' ), array( self::LIBRARY_HANDLE ), SEND4_VERSION, true );

		// Our copy must never be what window.intlTelInput answers to. WPForms
		// Pro's Smart phone field ships an older one under that name; and a site's
		// own snippet calling intlTelInput( input, … ) on a page where its library
		// is not loaded would otherwise find ours, and build a second, unstyled
		// picker out of it on a field that already has one (#0692).
		//
		// The library ends `var intlTelInput = …`, which on a fresh window makes a
		// non-configurable property nothing can take back. So the name is claimed
		// first, as an accessor: while our file runs, what it assigns goes to
		// window.send4Iti, and whoever held the name before, or takes it after,
		// keeps it. If somebody else's `var` got there first the accessor cannot
		// be defined, and the global is handed back the old way.
		wp_add_inline_script(
			self::LIBRARY_HANDLE,
			'(function(w){var held=w.intlTelInput,loading=true;w.send4ItiLoaded=function(){loading=false;};'
			. 'try{Object.defineProperty(w,"intlTelInput",{configurable:true,enumerable:true,'
			. 'get:function(){return held;},set:function(v){if(loading){w.send4Iti=v;}else{held=v;}}});}'
			. 'catch(e){w.send4ItiPrevious=held;w.send4ItiSwap=true;}})(window);',
			'before'
		);
		wp_add_inline_script(
			self::LIBRARY_HANDLE,
			'(function(w){if(w.send4ItiSwap){w.send4Iti=w.intlTelInput;if(w.send4ItiPrevious){w.intlTelInput=w.send4ItiPrevious;}}'
			. 'if(w.send4ItiLoaded){w.send4ItiLoaded();}})(window);',
			'after'
		);
	}

	/**
	 * Enqueue once, whenever the first form that wants a picker renders.
	 *
	 * @return void
	 */
	public static function enqueue() {
		self::register_assets();

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Enqueue for forms a site declared by hand through send4_phone_targets —
	 * the escape hatch for a form this class cannot see render.
	 *
	 * @return void
	 */
	public static function enqueue_declared() {
		if ( ! empty( self::targets() ) ) {
			self::enqueue();
		}
	}

	/**
	 * Contact Form 7 render hook.
	 *
	 * @param string $elements The form's inner markup.
	 * @return string Unchanged.
	 */
	public static function on_cf7_render( $elements ) {
		$form = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_current() : null;

		if ( $form instanceof WPCF7_ContactForm ) {
			self::register_target( 'contact-form-7', (string) $form->id() );
		}

		return $elements;
	}

	/**
	 * WPForms render hook.
	 *
	 * @param array $form_data The form definition.
	 * @return void
	 */
	public static function on_wpforms_render( $form_data ) {
		if ( is_array( $form_data ) && ! empty( $form_data['id'] ) ) {
			self::register_target( 'wpforms', (string) $form_data['id'] );
		}
	}

	/**
	 * Gravity Forms render hook.
	 *
	 * @param array $form The form definition.
	 * @return void
	 */
	public static function on_gravityforms_render( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return;
		}

		$form_key = 'gravityforms:' . $form['id'];

		// Gravity Forms' "standard" phone format, the one a new phone field
		// starts with, accepts (###) ###-#### and nothing else, and the picker
		// rewrites the field to +12025550123. With both on, Gravity Forms itself
		// refused every submission, whether or not Send4's own check was on.
		// The owner chose the format on the field, so it wins here as it does
		// for validation: no picker, and the log and the mapping screen say why.
		if ( Send4_Settings::form_uses_phone_picker( $form_key ) ) {
			$adapter  = Send4_Adapters::get( 'gravityforms' );
			$field_id = Send4_Settings::form( $form_key )['mapping']['phone'];

			if ( $adapter instanceof Send4_Adapter_Gravityforms && $adapter->field_is_us_only( $form, $field_id ) ) {
				$adapter->report_us_format( $form_key, $field_id, 'picker' );

				return;
			}
		}

		self::register_target( 'gravityforms', (string) $form['id'] );
	}

	/**
	 * Elementor Pro render hook.
	 *
	 * @param array  $instance Widget settings.
	 * @param object $widget   The Form widget.
	 * @return void
	 */
	public static function on_elementor_render( $instance, $widget = null ) {
		unset( $instance );

		if ( is_object( $widget ) && method_exists( $widget, 'get_id' ) ) {
			self::register_target( 'elementor', (string) $widget->get_id() );
		}
	}

	/**
	 * Note that a rendered form wants a picker, and load the assets.
	 *
	 * The same form twice on one page is one target: its selector matches
	 * every copy.
	 *
	 * @param string $slug    Adapter slug.
	 * @param string $form_id The form plugin's id for the form.
	 * @return void
	 */
	public static function register_target( $slug, $form_id ) {
		$form_key = $slug . ':' . $form_id;

		if ( isset( self::$targets[ $form_key ] ) || ! Send4_Settings::form_uses_phone_picker( $form_key ) ) {
			return;
		}

		$selectors = self::selectors_for( $slug, $form_id, Send4_Settings::form( $form_key )['mapping']['phone'] );

		if ( null === $selectors ) {
			return;
		}

		self::$targets[ $form_key ] = array(
			'key'      => $form_key,
			'scope'    => $selectors['scope'],
			'input'    => $selectors['input'],
			'country'  => self::default_country( $form_key ),
			// The browser refuses only when the site owner opted in to refusing.
			// With just the picker on, it tidies the number and never blocks.
			'validate' => Send4_Settings::form_validates_phone( $form_key ),
			'message'  => Send4_Phone_Guard::message( 'client', $form_key ),
		);

		self::enqueue();
	}

	/**
	 * Where a form's phone input is on the page: a scope matching each
	 * rendered copy of the form, and the input inside it.
	 *
	 * Always a name selector, never an id: Gravity Forms and Elementor repeat
	 * input ids when a form appears twice, and #input_1_5 would quietly match
	 * only the first. A Gravity Forms phone mapping is always a parent field id
	 * — sub-inputs like 1.3 never reach the mapping — so a composite field
	 * mapped as the phone matches nothing and gets no picker, which is right.
	 *
	 * @param string $slug     Adapter slug.
	 * @param string $form_id  The form plugin's id for the form.
	 * @param string $field_id The mapped phone field id.
	 * @return array{scope:string,input:string}|null Null for an adapter this does not know.
	 */
	public static function selectors_for( $slug, $form_id, $field_id ) {
		$form  = self::css_string( (string) $form_id );
		$field = (string) $field_id;

		if ( '' === $field || '' === (string) $form_id ) {
			return null;
		}

		switch ( $slug ) {
			case 'contact-form-7':
				// data-wpcf7-id is recent Contact Form 7 markup: a 5.x wrapper
				// carries only its unit tag, id="wpcf7-f42-p10-o1" (wpcf7-f42-o1
				// outside a post), and got no picker at all. 6.x renders both, so
				// both are matched. The trailing dash keeps
				// form 42 from matching form 420, and a selector list never
				// returns the same wrapper twice.
				return array(
					'scope' => '.wpcf7[data-wpcf7-id="' . $form . '"], .wpcf7[id^="' . self::css_string( 'wpcf7-f' . $form_id . '-' ) . '"]',
					'input' => '[name="' . self::css_string( $field ) . '"]',
				);

			case 'wpforms':
				return array(
					'scope' => 'form.wpforms-form[data-formid="' . $form . '"]',
					'input' => '[name="' . self::css_string( 'wpforms[fields][' . $field . ']' ) . '"]',
				);

			case 'gravityforms':
				return array(
					'scope' => 'form[id="' . self::css_string( 'gform_' . $form_id ) . '"]',
					'input' => '[name="' . self::css_string( 'input_' . $field ) . '"]',
				);

			case 'elementor':
				return array(
					'scope' => '[data-id="' . $form . '"]',
					'input' => '[name="' . self::css_string( 'form_fields[' . $field . ']' ) . '"]',
				);
		}

		return null;
	}

	/**
	 * Escape a value for a double-quoted CSS attribute selector.
	 *
	 * Form and field ids are customer-authored, so a quote or a backslash in
	 * one must not end the string early.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function css_string( $value ) {
		return str_replace(
			array( '\\', '"', "\n", "\r", "\f" ),
			array( '\\\\', '\\"', '\\a ', '\\d ', '\\c ' ),
			(string) $value
		);
	}

	/**
	 * The country the picker starts on.
	 *
	 * The saved setting, then the region of the site's locale, then nothing. A
	 * site's phone geography is not its language — a UK trade site running
	 * en_US is common — which is why the setting exists. There is deliberately
	 * no hardcoded fallback: a wrong preselection is worse than none, because
	 * strict mode caps the length at that country's maximum and the visitor
	 * cannot tell why their number is being cut short.
	 *
	 * @param string $form_key Form key.
	 * @return string Lowercase ISO 3166-1 alpha-2, or ''.
	 */
	public static function default_country( $form_key ) {
		$country = Send4_Settings::form( $form_key )['phone_field']['default_country'];

		if ( ! self::is_country( $country ) ) {
			$parts   = explode( '_', (string) get_locale() );
			$region  = isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
			$country = self::is_country( $region ) ? $region : '';
		}

		/**
		 * Filter the country a form's phone picker starts on.
		 *
		 * @param string $country  Lowercase ISO 3166-1 alpha-2, or '' for none.
		 * @param string $form_key The form key.
		 */
		$country = apply_filters( 'send4_phone_default_country', $country, $form_key );

		return self::is_country( $country ) ? $country : '';
	}

	/**
	 * Every target the controller should attach to on this page.
	 *
	 * @return array<int,array>
	 */
	public static function targets() {
		/**
		 * Filter the forms that get a phone picker on this page.
		 *
		 * For a form this plugin cannot see render — an Elementor popup
		 * fetched over admin-ajax after the page loaded is the known case.
		 * Each target is an array: `scope` (CSS selector for each copy of the
		 * form) and `input` (CSS selector for the phone input inside it) are
		 * required; `key`, `country`, `validate` and `message` are optional.
		 * Adding a target on a page also loads the picker there.
		 *
		 * @param array<int,array> $targets Targets found so far.
		 */
		$targets = apply_filters( 'send4_phone_targets', array_values( self::$targets ) );
		$clean   = array();

		foreach ( is_array( $targets ) ? $targets : array() as $target ) {
			if ( ! is_array( $target ) || empty( $target['scope'] ) || empty( $target['input'] ) ) {
				continue;
			}

			$key = isset( $target['key'] ) ? (string) $target['key'] : '';

			$clean[] = array(
				'key'      => $key,
				'scope'    => (string) $target['scope'],
				'input'    => (string) $target['input'],
				'country'  => isset( $target['country'] ) && self::is_country( $target['country'] ) ? $target['country'] : '',
				'validate' => ! empty( $target['validate'] ),
				'message'  => isset( $target['message'] ) ? (string) $target['message'] : Send4_Phone_Guard::message( 'client', $key ),
			);
		}

		return $clean;
	}

	/**
	 * The controller's configuration, or null when this page has no target.
	 *
	 * @return array|null
	 */
	public static function config() {
		$targets = self::targets();

		if ( empty( $targets ) ) {
			return null;
		}

		return array(
			'major'    => strtok( self::ITI_VERSION, '.' ),
			// Absolute: utils.js is an ES module loaded with import(), and a
			// relative specifier would resolve against the page, not this file.
			'utilsUrl' => SEND4_PLUGIN_URL . 'public/lib/intl-tel-input/js/utils.js?ver=' . self::ITI_VERSION,
			'locale'   => str_replace( '_', '-', determine_locale() ),
			'targets'  => $targets,
			'i18n'     => array(
				/* translators: Screen-reader name of the country button. Keep ${countryName} and ${dialCode} exactly as written; the picker fills them in. */
				'selectedCountryAriaLabel' => __( 'Change the country of the phone number, currently ${countryName} (${dialCode})', 'send4' ),
				'noCountrySelected'        => __( 'Choose the country of the phone number', 'send4' ),
				'countryListAriaLabel'     => __( 'List of countries', 'send4' ),
				'searchPlaceholder'        => __( 'Search countries', 'send4' ),
				'clearSearchAriaLabel'     => __( 'Clear search', 'send4' ),
				'searchEmptyState'         => __( 'No countries found', 'send4' ),
				'searchOneResult'          => __( '1 country found', 'send4' ),
				/* translators: %d: number of countries matching the search. */
				'searchManyResults'        => __( '%d countries found', 'send4' ),
			),
		);
	}

	/**
	 * Print the configuration as a JSON block.
	 *
	 * Not wp_localize_script() or wp_add_inline_script(): both emit executable
	 * JavaScript, which concatenators hoist, reorder, defer or delay until the
	 * visitor interacts. A type="application/json" block is not JavaScript, so
	 * no optimiser touches it.
	 *
	 * @return void
	 */
	public static function print_config() {
		$config = self::config();

		if ( null === $config ) {
			return;
		}

		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		// The hex flags ARE the escaping: <, >, &, ' and " inside customer-
		// authored form and field ids come out as < and friends, so the
		// block cannot be closed early by a </script> in a field name, and
		// esc_html() would corrupt the JSON the controller parses.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script type="application/json" id="' . esc_attr( self::CONFIG_ID ) . '">' . $json . "</script>\n";
	}

	/**
	 * Every country the picker can select.
	 *
	 * @return string[]
	 */
	public static function countries() {
		if ( null === self::$countries ) {
			self::$countries = (array) preg_split( '/\s+/', trim( self::COUNTRIES ) );
		}

		return self::$countries;
	}

	/**
	 * Is this a country code the picker knows?
	 *
	 * A key lookup rather than in_array() over the list: this runs for every
	 * target on every page that renders a form, and several times per save.
	 *
	 * @param mixed $iso2 Candidate code.
	 * @return bool
	 */
	public static function is_country( $iso2 ) {
		if ( ! is_string( $iso2 ) || 2 !== strlen( $iso2 ) ) {
			return false;
		}

		if ( null === self::$country_lookup ) {
			self::$country_lookup = array_flip( self::countries() );
		}

		return isset( self::$country_lookup[ $iso2 ] );
	}

	/**
	 * Forget this request's targets. Tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$targets = array();
	}
}
