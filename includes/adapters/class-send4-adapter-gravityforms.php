<?php
/**
 * Gravity Forms.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks gform_after_submission, which runs once the entry has been saved, so
 * every submission carries an entry id and the idempotency key is stable.
 */
class Send4_Adapter_Gravityforms implements Send4_Form_Adapter, Send4_Validating_Adapter {

	/**
	 * Field types that are never sent: those that carry no answer, and the two
	 * that carry a secret. A card number (even the masked one Gravity Forms
	 * keeps) and a password have no place in a messaging flow.
	 *
	 * @var string[]
	 */
	private static $ignored_types = array( 'html', 'section', 'page', 'captcha', 'submit', 'creditcard', 'password' );

	/**
	 * Transient that remembers a US-only phone field was already reported, so
	 * the warning lands in the log once a day rather than on every submission.
	 */
	const US_FORMAT_NOTICE = 'send4_gf_us_phone_';

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'gravityforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'Gravity Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( 'GFAPI' ) && class_exists( 'GFForms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect_forms() {
		if ( ! $this->is_available() ) {
			return array();
		}

		$forms  = array();
		$stored = GFAPI::get_forms();

		foreach ( (array) $stored as $form ) {
			if ( ! is_array( $form ) || empty( $form['id'] ) ) {
				continue;
			}

			$forms[] = array(
				'key'    => $this->slug() . ':' . $form['id'],
				'id'     => (string) $form['id'],
				'title'  => isset( $form['title'] ) ? (string) $form['title'] : '',
				'fields' => $this->fields_for( $form ),
			);
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( callable $on_submit ) {
		add_action(
			'gform_after_submission',
			function ( $entry, $form ) use ( $on_submit ) {
				$captured = $this->capture( $entry, $form );

				if ( $captured instanceof Send4_Submission ) {
					call_user_func( $on_submit, $captured );
				}
			},
			10,
			2
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The gform_field_validation filter runs per field during validation, before the entry
	 * exists. Unlike the other three this is not an earlier stage of the hook
	 * capture uses — gform_after_submission fires only once an entry is saved
	 * — but the outcome is the same: a refused submission saves no entry, so
	 * that hook never fires. Gravity Forms skips the filter for a field hidden
	 * by conditional logic, which is right: a hidden field is empty, and empty
	 * is accepted anyway.
	 *
	 * @see https://docs.gravityforms.com/gform_field_validation/
	 */
	public function register_validation( Send4_Phone_Guard $guard ) {
		add_filter(
			'gform_field_validation',
			function ( $result, $value, $form, $field ) use ( $guard ) {
				return $this->validate( $guard, $result, $value, $form, $field );
			},
			20,
			4
		);
	}

	/**
	 * Refuse the mapped phone field when it cannot be texted.
	 *
	 * @param Send4_Phone_Guard $guard  The phone guard.
	 * @param array             $result array{is_valid:bool,message:string}.
	 * @param mixed             $value  The submitted value.
	 * @param array             $form   The form definition.
	 * @param mixed             $field  GF_Field, or an array in older releases.
	 * @return array
	 */
	public function validate( Send4_Phone_Guard $guard, $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || ! is_array( $form ) || empty( $form['id'] ) ) {
			return $result;
		}

		// Already refused by Gravity Forms itself: one message per field.
		if ( isset( $result['is_valid'] ) && ! $result['is_valid'] ) {
			return $result;
		}

		$form_key = $this->slug() . ':' . $form['id'];
		$field_id = $guard->field_id( $form_key );

		// The filter only ever runs for fields the form has, so a mapping that
		// points at a deleted field simply never matches — failing open.
		if ( '' === $field_id || (string) $this->read( $field, 'id' ) !== $field_id ) {
			return $result;
		}

		// Gravity Forms' "standard" phone format accepts only (###) ###-####,
		// and Send4 accepts only numbers with a country code, so with both on
		// no number could ever pass and the form would refuse every visitor.
		// Gravity Forms' own format wins — the owner chose it on the field —
		// and the log says why Send4's check is not running.
		if ( $this->is_us_only( $field ) ) {
			$this->report_us_format( $form_key, $field_id );

			return $result;
		}

		$message = $guard->reject( $form_key, $value );

		if ( '' === $message ) {
			return $result;
		}

		return array(
			'is_valid' => false,
			'message'  => $message,
		);
	}

	/**
	 * Does Gravity Forms hold this field to its "standard" phone format?
	 *
	 * That format, the one a new phone field starts with, accepts
	 * (###) ###-#### and nothing else. A number with a country code can never
	 * pass it, so neither of Send4's phone checks can run beside it: validate()
	 * above stands aside, and Send4_Phone_Field attaches no picker.
	 *
	 * @param mixed $field GF_Field, or an array in older releases.
	 * @return bool
	 */
	private function is_us_only( $field ) {
		return 'standard' === (string) $this->read( $field, 'phoneFormat' );
	}

	/**
	 * The same question of one of a form's fields, by id.
	 *
	 * @param array  $form     The form definition.
	 * @param string $field_id Field id.
	 * @return bool False as well when the form has no such field.
	 */
	public function field_is_us_only( array $form, $field_id ) {
		if ( '' === (string) $field_id || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( (string) $this->read( $field, 'id' ) === (string) $field_id ) {
				return $this->is_us_only( $field );
			}
		}

		return false;
	}

	/**
	 * Say, once a day per field, that one of the phone checks is off for it.
	 *
	 * @param string $form_key Form key.
	 * @param string $field_id Field id.
	 * @param string $check    `validation`, or `picker` for the country picker.
	 * @return void
	 */
	public function report_us_format( $form_key, $field_id, $check = 'validation' ) {
		$picker = 'picker' === $check;

		// The two are reported apart: a field can have either check on, and one
		// warning a day must not swallow the other.
		$flag = self::US_FORMAT_NOTICE . md5( $form_key . '|' . $field_id . ( $picker ? '|picker' : '' ) );

		if ( get_transient( $flag ) ) {
			return;
		}

		set_transient( $flag, 1, DAY_IN_SECONDS );

		Send4_Logger::log(
			'warning',
			$picker
				? 'The country picker is off for this field: its Gravity Forms phone format is "standard", which only accepts US numbers written (###) ###-#### and would refuse the number the picker sends. Switch the field to "international" to use the picker.'
				: 'Phone validation is off for this field: its Gravity Forms phone format is "standard", which only accepts US numbers without a country code. Switch the field to "international" to let Send4 check numbers.',
			array(
				'form'  => $form_key,
				'field' => $field_id,
			)
		);
	}

	/**
	 * Build a submission from a Gravity Forms entry.
	 *
	 * @param array $entry The saved entry.
	 * @param array $form  The form definition.
	 * @return Send4_Submission|null
	 */
	public function capture( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['id'] ) ) {
			return null;
		}

		// Gravity Forms is the only one of the four that still fires its hook
		// for an entry the spam filter rejected. Texting that person would be
		// the worst possible outcome, so the guard lives here rather than in
		// shared code where it would read as paranoia.
		if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return null;
		}

		$fields = array();

		foreach ( $this->fields_for( $form ) as $field ) {
			$fields[] = Send4_Submission::field(
				$field['id'],
				$field['label'],
				$this->value_for( $entry, $field )
			);
		}

		return new Send4_Submission(
			$this->slug() . ':' . $form['id'],
			isset( $form['title'] ) ? (string) $form['title'] : '',
			$fields,
			empty( $entry['id'] ) ? null : (string) $entry['id']
		);
	}

	/**
	 * Read one field's value out of the entry.
	 *
	 * Gravity Forms stores some fields under sub-input keys like "1.3" and
	 * others under the field id, and which one depends on the field type rather
	 * than on whether the field declares inputs:
	 *
	 * - name, address: one sub-input per part, stitched back with a space.
	 * - checkbox: one sub-input per ticked choice, joined with ", " — the
	 *   separator Send4_Normalizer::flatten() gives every other plugin's
	 *   multi-value field. A space made "New York Boston" ambiguous.
	 * - consent: X.1 is the tick ("1"), X.2 the agreement text and X.3 its
	 *   revision id. Only the tick is the answer, which is what the `boolean`
	 *   the descriptor declares for it expects.
	 * - time, and date in its field or dropdown styles: declare inputs, but the
	 *   combined value lives under the field id. Hence the fallback to it
	 *   whenever the sub-inputs come back empty.
	 * - multiselect: a JSON array string, decoded and joined like a checkbox.
	 * - list: a serialized PHP array. Sent as stored. Visitor data is never
	 *   unserialized here — that is an object-injection hole for the sake of
	 *   formatting — so a List field arrives in Send4 as Gravity Forms keeps it.
	 *
	 * @see https://docs.gravityforms.com/entry-object/
	 *
	 * @param array $entry Entry.
	 * @param array $field Field descriptor produced by fields_for().
	 * @return string
	 */
	private function value_for( array $entry, array $field ) {
		$type = isset( $field['input_type'] ) && '' !== $field['input_type'] ? $field['input_type'] : $field['type'];

		if ( 'consent' === $type ) {
			$tick = $field['id'] . '.1';

			return isset( $entry[ $tick ] ) ? trim( (string) $entry[ $tick ] ) : '';
		}

		$stitched = '';

		if ( ! empty( $field['inputs'] ) ) {
			$parts = array();

			foreach ( $field['inputs'] as $input_id ) {
				$value = isset( $entry[ $input_id ] ) ? trim( (string) $entry[ $input_id ] ) : '';

				if ( '' !== $value ) {
					$parts[] = $value;
				}
			}

			$stitched = implode( 'checkbox' === $type ? ', ' : ' ', $parts );
		}

		if ( '' !== $stitched ) {
			return $stitched;
		}

		$value = isset( $entry[ $field['id'] ] ) ? (string) $entry[ $field['id'] ] : '';

		if ( 'multiselect' === $type ) {
			return $this->multiselect_value( $value );
		}

		return $value;
	}

	/**
	 * A multiselect's stored JSON array, as a comma-separated string.
	 *
	 * Gravity Forms 2.5 and later store `["a","b"]`; earlier releases stored
	 * "a,b". Anything that does not decode to a list of scalars is passed on
	 * untouched rather than guessed at.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private function multiselect_value( $value ) {
		if ( '' === $value || '[' !== $value[0] ) {
			return $value;
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return $value;
		}

		foreach ( $decoded as $item ) {
			if ( ! is_scalar( $item ) ) {
				return $value;
			}
		}

		return Send4_Normalizer::flatten( array_values( $decoded ) );
	}

	/**
	 * Describe the form's fields, flattening GF_Field objects into arrays.
	 *
	 * @param array $form Form definition.
	 * @return array<int,array{id:string,label:string,type:string,input_type:string,inputs:string[],options:string[],us_only?:bool}>
	 */
	private function fields_for( array $form ) {
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $form['fields'] as $field ) {
			$id    = $this->read( $field, 'id' );
			$type  = (string) $this->read( $field, 'type' );
			$label = (string) $this->read( $field, 'label' );

			if ( '' === (string) $id || in_array( $type, self::$ignored_types, true ) ) {
				continue;
			}

			$inputs    = $this->read( $field, 'inputs' );
			$input_ids = array();

			if ( is_array( $inputs ) ) {
				foreach ( $inputs as $input ) {
					$input_id = $this->read( $input, 'id' );

					if ( '' !== (string) $input_id ) {
						$input_ids[] = (string) $input_id;
					}
				}
			}

			$described = array(
				'id'         => (string) $id,
				'label'      => $label,
				'type'       => $type,
				// What the field really is when it wraps another: a Product
				// or Post Custom Field drawn as a checkbox stores its value the
				// way a checkbox does. GF_Field::get_input_type() reads the same.
				'input_type' => (string) $this->read( $field, 'inputType' ),
				'inputs'     => $input_ids,
				'options'    => $this->choices_for( $field ),
			);

			// Set only when true, so every other field keeps the shape it had.
			// The mapping screen reads it to say why the phone checks are off.
			if ( $this->is_us_only( $field ) ) {
				$described['us_only'] = true;
			}

			$fields[] = $described;
		}

		return $fields;
	}

	/**
	 * Read a property from a GF_Field object or a plain array.
	 *
	 * @param mixed  $field Field.
	 * @param string $key   Property.
	 * @return mixed
	 */
	private function read( $field, $key ) {
		if ( is_array( $field ) ) {
			return isset( $field[ $key ] ) ? $field[ $key ] : '';
		}

		if ( is_object( $field ) && isset( $field->{$key} ) ) {
			return $field->{$key};
		}

		return '';
	}

	/**
	 * The values of a pick-list field's choices, so Send4's conditional card can
	 * offer them (#0687). Gravity Forms stores them on the field as
	 * `choices => [['text' => …, 'value' => …]]`, on an object or an array.
	 *
	 * @param mixed $field Field.
	 * @return array<int,string>
	 */
	private function choices_for( $field ) {
		$choices = $this->read( $field, 'choices' );

		if ( ! is_array( $choices ) ) {
			return array();
		}

		$options = array();

		foreach ( $choices as $choice ) {
			// GF submits the value when one is set, and the text otherwise.
			$value = (string) $this->read( $choice, 'value' );

			if ( '' === $value ) {
				$value = (string) $this->read( $choice, 'text' );
			}

			if ( '' !== $value ) {
				$options[] = $value;
			}
		}

		return $options;
	}
}
