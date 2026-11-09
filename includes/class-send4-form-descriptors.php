<?php
/**
 * The form inventory this site reports to Send4.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns what the adapters detect into the descriptors Send4's trigger picker
 * renders (#0687).
 *
 * This is the one thing only the plugin can answer. Send4 learns a form key when
 * a submission arrives carrying one, which is far too late to offer it in the
 * flow builder — so the customer used to copy the key out of wp-admin and retype
 * it there, and a typo produced a trigger that silently never fired.
 *
 * The contract is defined by Send4's WordPress integration API.
 */
class Send4_Form_Descriptors {

	/**
	 * The API's own limits, mirrored here so an oversized site degrades instead
	 * of being rejected wholesale.
	 *
	 * Send4 answers 422 for the WHOLE payload if any one of these is exceeded,
	 * and the retry would send the same invalid body forever — so one country
	 * dropdown with 200 choices would cost the site every other form in its
	 * builder. These constants are half of a coordinated contract: they must
	 * match the limits Send4 enforces on the forms push.
	 */
	const MAX_FORMS   = 200;
	const MAX_FIELDS  = 100;
	const MAX_OPTIONS = 50;

	/**
	 * Characters, not bytes: Laravel's `max:` on a string counts characters,
	 * so a Lithuanian or Cyrillic name may carry 191 letters.
	 *
	 * The API holds form_key, adapter_name, name, fields.*.key, fields.*.label
	 * and each option to `max:191`, and adapter to `max:32`.
	 */
	const MAX_STRING  = 191;
	const MAX_ADAPTER = 32;

	/**
	 * Send4 rejects anything outside this vocabulary, and it is deliberately
	 * small: it drives which operators the flow builder's conditional card
	 * offers, not how the value is stored.
	 *
	 * `boolean` earns its place because a WordPress checkbox arrives as the
	 * string "on", which Send4's condition evaluator would never match against
	 * a real boolean.
	 *
	 * @var array<string,string>
	 */
	private static $type_map = array(
		// Contact Form 7 basetypes.
		'email'         => 'email',
		'tel'           => 'phone',
		'number'        => 'number',
		'range'         => 'number',
		'select'        => 'option',
		'radio'         => 'option',
		'checkbox'      => 'option',
		'acceptance'    => 'boolean',

		// WPForms / Gravity Forms / Elementor type names.
		'phone'         => 'phone',
		'url'           => 'text',
		'textarea'      => 'text',
		'text'          => 'text',
		'name'          => 'text',
		'address'       => 'text',
		'date'          => 'text',
		'time'          => 'text',
		'date-time'     => 'text',
		'multiselect'   => 'option',
		'checkboxes'    => 'option',
		'radio-list'    => 'option',
		'consent'       => 'boolean',
		'quantity'      => 'number',

		// WPForms: the slider posts a number, and the GDPR agreement is a
		// single yes that only arrives when it was ticked.
		'number-slider' => 'number',
		'gdpr-checkbox' => 'boolean',
	);

	/**
	 * Every detected form, as Send4 wants it.
	 *
	 * @return array<int,array>
	 */
	public static function build() {
		$descriptors = array();
		$detected    = Send4_Adapters::detect_forms();

		// Send4 takes MAX_FORMS and the rest are cut below. What is cut must not
		// be a form that delivers, so on a site over the limit those go first
		// and everything else keeps its order. A site under it is left exactly
		// as it was: reordering would change the hash, and force a push, for
		// nothing.
		if ( count( $detected ) > self::MAX_FORMS ) {
			$live = array();
			$rest = array();

			foreach ( $detected as $form ) {
				if ( isset( $form['key'] ) && Send4_Settings::form_is_active( (string) $form['key'] ) ) {
					$live[] = $form;
				} else {
					$rest[] = $form;
				}
			}

			$detected = array_merge( $live, $rest );
		}

		foreach ( $detected as $form ) {
			$key = isset( $form['key'] ) ? (string) $form['key'] : '';

			if ( '' === $key ) {
				continue;
			}

			// Reporting fewer forms is a worse picker; reporting too many is a
			// 422 and NO picker at all. Say so rather than truncating in silence.
			if ( count( $descriptors ) >= self::MAX_FORMS ) {
				Send4_Logger::log(
					'warning',
					'This site has more forms than Send4 accepts; the rest were not reported.',
					array(
						'detected' => count( $detected ),
						'reported' => self::MAX_FORMS,
					)
				);

				break;
			}

			$settings = Send4_Settings::form( $key );

			// Capped rather than trusted. A form title is whatever the site
			// owner typed, and one title over the limit was a 422 for the whole
			// inventory — which the daily sync then resent unchanged, forever.
			// form_key is capped exactly as Send4_Normalizer caps the envelope's
			// form.key, so a long key still names the same form on both paths.
			$descriptors[] = array(
				'form_key'     => self::cap( $key, self::MAX_STRING ),
				'adapter'      => self::cap( isset( $form['adapter'] ) ? (string) $form['adapter'] : '', self::MAX_ADAPTER ),
				'adapter_name' => self::cap( isset( $form['adapter_name'] ) ? (string) $form['adapter_name'] : '', self::MAX_STRING ),
				'name'         => self::cap( isset( $form['title'] ) && '' !== $form['title'] ? (string) $form['title'] : $key, self::MAX_STRING ),
				// The honest answer to "will this form's submissions reach
				// Send4": enabled here AND carrying the phone mapping delivery
				// requires. A form switched on but missing its phone field
				// sends nothing, and Send4 should show it as off.
				'enabled'      => Send4_Settings::form_is_active( $key ),
				'fields'       => self::fields_for( $form, $settings['mapping'] ),
			);
		}

		return $descriptors;
	}

	/**
	 * A fingerprint of the current inventory, so a sync that would change
	 * nothing can be skipped.
	 *
	 * @param array $descriptors Descriptors from build().
	 * @return string
	 */
	public static function hash( array $descriptors ) {
		return md5( (string) wp_json_encode( $descriptors ) );
	}

	/**
	 * The declared fields of one form.
	 *
	 * ⚠️ The keys here MUST be the keys a submission will actually carry, so
	 * they come from Send4_Normalizer::data_keys_for() rather than from slugging
	 * the label again. The normalizer renames a slug that collides with the
	 * envelope, disambiguates two fields with the same label, and falls back to
	 * the field id and then to a position. Guessing any of that would offer the
	 * customer a variable that is never populated, and nothing would error.
	 *
	 * Fields mapped to phone/name/email are absent on purpose: they arrive under
	 * `lead.*`, not at the top level, and Send4 already knows those paths.
	 *
	 * @param array $form    Detected form.
	 * @param array $mapping phone/name/email field ids.
	 * @return array<int,array>
	 */
	private static function fields_for( array $form, array $mapping ) {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? array_values( $form['fields'] ) : array();
		$keys   = Send4_Normalizer::data_keys_for( $fields, $mapping );

		$declared = array();

		foreach ( $fields as $index => $field ) {
			if ( ! isset( $keys[ $index ] ) ) {
				continue;
			}

			// The declaration is a hint, not a contract — an undeclared key
			// still reaches the run, it just does not appear in Send4's
			// variable picker. So dropping the tail of an enormous form costs
			// far less than a 422 that loses the whole site's inventory.
			if ( count( $declared ) >= self::MAX_FIELDS ) {
				break;
			}

			$declared[] = array(
				'key'     => $keys[ $index ],
				'label'   => self::label_for( $field, $keys[ $index ] ),
				'type'    => self::type_for( $field ),
				'options' => self::options_for( $field ),
			);
		}

		return $declared;
	}

	/**
	 * The label to show for a field, falling back to its key.
	 *
	 * @param array  $field    Detected field.
	 * @param string $fallback Key to fall back to.
	 * @return string
	 */
	private static function label_for( array $field, $fallback ) {
		$label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';

		// A label is display text in Send4's picker, so a shortened one still
		// does its job. The key, not the label, is what a flow binds to.
		return self::cap( '' !== $label ? $label : $fallback, self::MAX_STRING );
	}

	/**
	 * Narrow a form plugin's own type name to Send4's vocabulary.
	 *
	 * Unknown types become `text` rather than being dropped: a field whose type
	 * we do not recognise is still a field the customer wants to branch on, and
	 * `text` is the type that constrains the conditional card least.
	 *
	 * @param array $field Detected field.
	 * @return string
	 */
	private static function type_for( array $field ) {
		$type = isset( $field['type'] ) ? strtolower( trim( (string) $field['type'] ) ) : '';

		// Contact Form 7 marks required tags with a trailing asterisk
		// (`email*`), which is a validation rule, not a different type.
		$type = rtrim( $type, '*' );

		if ( ! isset( self::$type_map[ $type ] ) ) {
			return 'text';
		}

		$mapped = self::$type_map[ $type ];

		// A checkbox with no choices is a single yes/no, which is a boolean; one
		// with choices is a pick-list. Both arrive as `checkbox`. Judged on the
		// choices the adapter reported, not the list options_for() is willing
		// to send: a checkbox group whose list was withheld for being too long
		// is still a pick-list, not a yes/no.
		if ( 'option' === $mapped && 'checkbox' === $type && array() === self::choices( $field ) ) {
			return 'boolean';
		}

		return $mapped;
	}

	/**
	 * The choices of a pick-list field, where the adapter reported them.
	 *
	 * @param array $field Detected field.
	 * @return array<int,string>
	 */
	private static function options_for( array $field ) {
		$values = self::choices( $field );

		// Over the limit the whole list is DROPPED, not truncated. A truncated
		// list is a lie: Send4's builder would render the first 50 countries as
		// though they were the choices, and a condition on any other one would
		// look impossible. No list means "we are not telling you the choices",
		// which the API already treats as normal — the field is still declared,
		// still typed, and the value can still be typed by hand.
		if ( count( $values ) > self::MAX_OPTIONS ) {
			return array();
		}

		// One over-long choice drops the list for the same reason. Truncating
		// it would offer a value no submission ever carries, and a condition
		// built on it could never match; leaving it out would misstate the
		// choices; sending it whole is a 422 for the entire inventory.
		foreach ( $values as $value ) {
			if ( self::length( $value ) > self::MAX_STRING ) {
				return array();
			}
		}

		return $values;
	}

	/**
	 * Every distinct, non-blank choice the adapter reported, before limits.
	 *
	 * @param array $field Detected field.
	 * @return array<int,string>
	 */
	private static function choices( array $field ) {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$values  = array();

		foreach ( $options as $option ) {
			if ( is_array( $option ) || is_object( $option ) ) {
				continue;
			}

			$option = trim( (string) $option );

			// '0' is a legitimate choice, hence the comparison against ''.
			if ( '' !== $option && ! in_array( $option, $values, true ) ) {
				$values[] = $option;
			}
		}

		return $values;
	}

	/**
	 * Truncate to a number of characters, never splitting a multibyte one.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function cap( $value, $length ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Length in characters, as the API counts it.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
