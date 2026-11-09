<?php
/**
 * Contact Form 7.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks wpcf7_before_send_mail.
 *
 * That hook fires once validation and spam checks have passed, and it still
 * fires when the form is in demo mode or otherwise skips mail — which is what
 * we want, because a lead is a lead whether or not the site's own notification
 * email went out. The alternative (wpcf7_mail_sent) silently drops leads on
 * sites where wp_mail is broken, which is exactly the site that needs SMS
 * follow-up most.
 */
class Send4_Adapter_Cf7 implements Send4_Form_Adapter, Send4_Validating_Adapter, Send4_Findable_Adapter {

	/**
	 * Tag types that carry no answer worth sending.
	 *
	 * @var string[]
	 */
	private static $ignored_types = array( 'submit', 'file', 'captchac', 'captchar', 'recaptcha', 'quiz' );

	/**
	 * Tag types whose quoted values are choices. Every other type puts something
	 * else in the same property — a text field's placeholder or default value —
	 * which Send4 would otherwise offer as a pick-list option.
	 *
	 * @var string[]
	 */
	private static $choice_types = array( 'checkbox', 'radio', 'select' );

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'contact-form-7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A page at a time, up to Send4_Adapters::detection_limit(). This was one
	 * query for 100 forms, so the 101st could not be set up from the forms
	 * list and was missing from what Send4 is told about the site. By id, as
	 * Contact Form 7's own default is: an order in which nothing moves between
	 * two pages.
	 */
	public function detect_forms() {
		if ( ! $this->is_available() ) {
			return array();
		}

		$forms = array();
		$limit = Send4_Adapters::detection_limit();

		for ( $offset = 0; $offset < $limit; $offset += Send4_Adapters::DETECTION_PAGE ) {
			$page = WPCF7_ContactForm::find(
				array(
					'posts_per_page' => min( Send4_Adapters::DETECTION_PAGE, $limit - $offset ),
					'offset'         => $offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $page as $form ) {
				$forms[] = $this->describe( $form );
			}

			if ( count( $page ) < Send4_Adapters::DETECTION_PAGE ) {
				break;
			}
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Through find() rather than get_instance(): that one also makes the form
	 * Contact Form 7's "current" form, which is not this method's to change,
	 * and find() holds the lookup to the same post type and statuses the list
	 * above is built from.
	 */
	public function find_form( $form_id ) {
		$form_id = (string) $form_id;

		if ( ! $this->is_available() || ! ctype_digit( $form_id ) ) {
			return null;
		}

		$found = WPCF7_ContactForm::find(
			array(
				'p'              => (int) $form_id,
				'posts_per_page' => 1,
			)
		);

		return empty( $found ) ? null : $this->describe( $found[0] );
	}

	/**
	 * One form, as detect_forms() lists it.
	 *
	 * @param WPCF7_ContactForm $form The form.
	 * @return array{key:string,id:string,title:string,fields:array}
	 */
	private function describe( $form ) {
		return array(
			'key'    => $this->slug() . ':' . $form->id(),
			'id'     => (string) $form->id(),
			'title'  => $form->title(),
			'fields' => $this->fields_for( $form ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( callable $on_submit ) {
		add_action(
			'wpcf7_before_send_mail',
			function ( $contact_form, &$abort, $submission ) use ( $on_submit ) {
				// Another listener — an OTP check, a blocklist, a payment
				// add-on — may abort the submission here, and Contact Form 7
				// then tells the visitor it failed. Delivering it anyway would
				// text someone whose form the site refused. Hence the late
				// priority below: $abort is only final once they have all run.
				if ( $abort ) {
					return;
				}

				$captured = $this->capture( $contact_form, $submission );

				if ( $captured instanceof Send4_Submission ) {
					call_user_func( $on_submit, $captured );
				}
			},
			// Late, so every other listener has had its say on $abort, but not
			// PHP_INT_MAX itself: something that must run after Send4 still can.
			PHP_INT_MAX - 10,
			3
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The global wpcf7_validate filter, not wpcf7_validate_{$type}: the
	 * per-type names carry the required marker (tel and tel*), and the mapped
	 * field may not be a tel tag at all. It runs after setup_posted_data() and
	 * with the submission instance already assigned, so the posted value is
	 * there to read. A refusal here means wpcf7_before_send_mail never fires,
	 * so capture is skipped with no code of ours involved.
	 */
	public function register_validation( Send4_Phone_Guard $guard ) {
		add_filter(
			'wpcf7_validate',
			function ( $result, $tags ) use ( $guard ) {
				return $this->validate( $guard, $result, $tags );
			},
			20,
			2
		);
	}

	/**
	 * Refuse the submission when its mapped phone field cannot be texted.
	 *
	 * @param Send4_Phone_Guard $guard  The phone guard.
	 * @param object            $result WPCF7_Validation.
	 * @param array             $tags   WPCF7_FormTag objects being validated.
	 * @return object The same WPCF7_Validation.
	 */
	public function validate( Send4_Phone_Guard $guard, $result, $tags ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'invalidate' ) || ! is_array( $tags ) ) {
			return $result;
		}

		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;

		if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_contact_form' ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return $result;
		}

		$contact_form = $submission->get_contact_form();

		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return $result;
		}

		$form_key = $this->slug() . ':' . $contact_form->id();
		$field_id = $guard->field_id( $form_key );

		if ( '' === $field_id ) {
			return $result;
		}

		$tag = null;

		foreach ( $tags as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->name ) && $field_id === (string) $candidate->name ) {
				$tag = $candidate;
				break;
			}
		}

		// Fail open. Invalidating by name instead would make Contact Form 7
		// rescan the form for a tag that is not there; passing the object also
		// spares it that rescan when the tag is.
		if ( null === $tag ) {
			return $result;
		}

		// One message per field: an empty [tel*] already said so.
		if ( method_exists( $result, 'is_valid' ) && ! $result->is_valid( $field_id ) ) {
			return $result;
		}

		$posted  = $submission->get_posted_data();
		$message = $guard->reject( $form_key, is_array( $posted ) && isset( $posted[ $field_id ] ) ? $posted[ $field_id ] : '' );

		if ( '' !== $message ) {
			$result->invalidate( $tag, $message );
		}

		return $result;
	}

	/**
	 * Build a submission from CF7's objects.
	 *
	 * @param object      $contact_form WPCF7_ContactForm.
	 * @param object|null $submission   WPCF7_Submission.
	 * @return Send4_Submission|null
	 */
	public function capture( $contact_form, $submission = null ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return null;
		}

		if ( ! is_object( $submission ) && class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
		}

		if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return null;
		}

		$posted = $submission->get_posted_data();
		$posted = is_array( $posted ) ? $posted : array();
		$fields = array();

		foreach ( $this->fields_for( $contact_form ) as $field ) {
			$fields[] = Send4_Submission::field(
				$field['id'],
				$field['label'],
				isset( $posted[ $field['id'] ] ) ? $posted[ $field['id'] ] : ''
			);
		}

		// Contact Form 7 stores no entries, so there is no id to key on.
		return new Send4_Submission(
			$this->slug() . ':' . $contact_form->id(),
			$contact_form->title(),
			$fields,
			null
		);
	}

	/**
	 * The mappable fields of one form.
	 *
	 * @param object $contact_form WPCF7_ContactForm.
	 * @return array<int,array{id:string,label:string,type:string}>
	 */
	private function fields_for( $contact_form ) {
		if ( ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return array();
		}

		$fields = array();

		// The same filter setup_posted_data() uses: tags that have a name and
		// are not excluded from mail. Reading $_POST keys directly instead would
		// pull in Contact Form 7's own internals and free-text companions.
		$tags = $contact_form->scan_form_tags(
			array(
				'feature' => array( 'name-attr', '! not-for-mail' ),
			)
		);

		foreach ( $tags as $tag ) {
			$name     = isset( $tag->name ) ? (string) $tag->name : '';
			$basetype = isset( $tag->basetype ) ? (string) $tag->basetype : '';

			if ( '' === $name || in_array( $basetype, self::$ignored_types, true ) ) {
				continue;
			}

			if ( isset( $fields[ $name ] ) ) {
				continue;
			}

			$fields[ $name ] = array(
				'id'      => $name,
				'label'   => $this->label_for( $name ),
				'type'    => $basetype,
				// Pick-list choices, so Send4's conditional card can offer them
				// instead of asking the customer to retype every value (#0687).
				'options' => $this->options_for( $tag, $basetype ),
			);
		}

		return array_values( $fields );
	}

	/**
	 * A pick-list tag's choices, and nothing for any other tag.
	 *
	 * @param object $tag      WPCF7_FormTag.
	 * @param string $basetype Tag type without the required marker.
	 * @return array<int,string>
	 */
	private function options_for( $tag, $basetype ) {
		if ( ! in_array( $basetype, self::$choice_types, true ) || ! isset( $tag->values ) || ! is_array( $tag->values ) ) {
			return array();
		}

		$values = array_values( $tag->values );

		// A pipe ("Sales|sales@example.com") shows the visitor the part before
		// the bar, but setup_posted_data() runs do_pipe() and the submission
		// carries the part AFTER it — that is the value that reaches Send4.
		// Offering "Sales" would build a condition that never matches.
		if ( defined( 'WPCF7_USE_PIPE' ) && WPCF7_USE_PIPE && isset( $tag->pipes ) && $tag->pipes instanceof WPCF7_Pipes ) {
			$values = array_values( $tag->pipes->collect_afters() );
		}

		// first_as_label turns the first value into the select's prompt, posted
		// as an empty string. It is not a choice anyone can submit. With
		// include_blank Contact Form 7 adds its own prompt instead and keeps
		// the first value as a real option.
		if ( 'select' === $basetype && method_exists( $tag, 'has_option' ) && $tag->has_option( 'first_as_label' ) && ! $tag->has_option( 'include_blank' ) ) {
			array_shift( $values );
		}

		return $values;
	}

	/**
	 * CF7 has no field labels — the markup around a tag is free-form HTML — so
	 * the tag name is the best label available.
	 *
	 * @param string $name Tag name.
	 * @return string
	 */
	private function label_for( $name ) {
		// A run of delimiters is one gap: `user-info__name` reads "User info name".
		$label = (string) preg_replace( '/[-_]+/', ' ', $name );

		return ucfirst( trim( $label ) );
	}
}
