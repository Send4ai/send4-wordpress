<?php
/**
 * WPForms (Lite and Pro).
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks wpforms_process_complete, which runs after validation and after the
 * entry (Pro) has been stored.
 */
class Send4_Adapter_Wpforms implements Send4_Form_Adapter, Send4_Validating_Adapter, Send4_Findable_Adapter {

	/**
	 * Field types that are never sent, in either direction: detect_forms() and
	 * capture() both skip them, so the declared keys and the payload agree.
	 *
	 * Three kinds:
	 *
	 * - Carry no answer: layout, markup and navigation. `layout`, `repeater`
	 *   and `internal-information` are WPForms Pro containers and builder-only
	 *   notes — declared on the form, never holding a value of their own, so
	 *   declaring them offered Send4 variables no submission ever fills. A
	 *   repeater's or layout's children are fields in their own right.
	 * - Secrets: `password` (Pro) carries the visitor's cleartext password.
	 *   It must never leave the site, let alone reach a messaging flow.
	 * - Payment cards: the card fields of Stripe, Square and Authorize.Net.
	 *   Whatever part of a card they record, it is cardholder data with no
	 *   place in an SMS flow.
	 *
	 * @var string[]
	 */
	private static $ignored_types = array(
		'divider',
		'html',
		'pagebreak',
		'captcha',
		'entry-preview',
		'content',
		'layout',
		'repeater',
		'internal-information',
		'password',
		'credit-card',
		'stripe-credit-card',
		'square',
		'authorize_net',
	);

	/**
	 * The raw submitted fields of each form being processed, keyed by form id,
	 * held between wpforms_process_before and wpforms_process_initial_errors.
	 *
	 * Keyed by form id rather than a single slot, so two forms on one page
	 * cannot hand each other their answers.
	 *
	 * @var array<string,array>
	 */
	private $entries = array();

	/**
	 * The post statuses a form has while it exists: WPForms publishes a form
	 * when it is saved, and a draft is one that was imported or set aside.
	 * Trashed forms are not forms, in the list or when looked up by id.
	 *
	 * @var string[]
	 */
	private static $listed_statuses = array( 'publish', 'draft' );

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'wpforms' ) || class_exists( 'WPForms\WPForms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect_forms() {
		if ( ! $this->is_available() || ! function_exists( 'wpforms_decode' ) ) {
			return array();
		}

		// Read the posts directly rather than going through wpforms()->form.
		// That property is an internal that has come and gone across versions
		// and is not populated in every context — under WP-CLI it is null — so
		// depending on it left a site's forms simply invisible. The post id is
		// the form id either way: it is what $form_data['id'] carries in
		// wpforms_process_complete.
		//
		// A page at a time, up to Send4_Adapters::detection_limit(). This was
		// one query for 100 forms, and because the list is by title, adding a
		// form could push an existing one off the end of it. The id breaks
		// ties: forms that share a title (every "Contact us" copy) have no
		// order of their own, and could change places between two pages.
		$forms = array();
		$limit = Send4_Adapters::detection_limit();

		for ( $offset = 0; $offset < $limit; $offset += Send4_Adapters::DETECTION_PAGE ) {
			$posts = get_posts(
				array(
					'post_type'        => 'wpforms',
					'post_status'      => self::$listed_statuses,
					'posts_per_page'   => min( Send4_Adapters::DETECTION_PAGE, $limit - $offset ),
					'offset'           => $offset,
					'orderby'          => array(
						'title' => 'ASC',
						'ID'    => 'ASC',
					),
					'suppress_filters' => false,
				)
			);

			foreach ( $posts as $post ) {
				$forms[] = $this->describe( $post );
			}

			if ( count( $posts ) < Send4_Adapters::DETECTION_PAGE ) {
				break;
			}
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Held to the post type and statuses the list above is built from, so a
	 * trashed form, or another post type's id, is not a form here either.
	 */
	public function find_form( $form_id ) {
		$form_id = (string) $form_id;

		if ( ! $this->is_available() || ! function_exists( 'wpforms_decode' ) || ! ctype_digit( $form_id ) ) {
			return null;
		}

		$post = get_post( (int) $form_id );

		if ( ! $post instanceof WP_Post || 'wpforms' !== $post->post_type || ! in_array( $post->post_status, self::$listed_statuses, true ) ) {
			return null;
		}

		return $this->describe( $post );
	}

	/**
	 * One form, as detect_forms() lists it.
	 *
	 * @param WP_Post $post The form's post.
	 * @return array{key:string,id:string,title:string,fields:array}
	 */
	private function describe( $post ) {
		$form_data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : null;
		$form_data = is_array( $form_data ) ? $form_data : array();

		return array(
			'key'    => $this->slug() . ':' . $post->ID,
			'id'     => (string) $post->ID,
			'title'  => $post->post_title,
			'fields' => $this->fields_from_form_data( $form_data ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( callable $on_submit ) {
		add_action(
			'wpforms_process_complete',
			function ( $fields, $entry, $form_data, $entry_id = 0 ) use ( $on_submit ) {
				unset( $entry );

				$captured = $this->capture( $fields, $form_data, $entry_id );

				if ( $captured instanceof Send4_Submission ) {
					call_user_func( $on_submit, $captured );
				}
			},
			10,
			4
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Two hooks, because the filter that can stop processing does not carry the
	 * entry: wpforms_process_before hands over the raw fields, and
	 * wpforms_process_initial_errors takes an error. WPForms then returns
	 * before it saves an entry or fires wpforms_process_complete, so capture
	 * never runs. Taking the entry from a hook argument, rather than reading
	 * $_POST['wpforms'], is also what keeps this class out of the nonce sniff.
	 */
	public function register_validation( Send4_Phone_Guard $guard ) {
		add_action(
			'wpforms_process_before',
			function ( $entry, $form_data ) {
				$this->remember_entry( $entry, $form_data );
			},
			10,
			2
		);

		add_filter(
			'wpforms_process_initial_errors',
			function ( $errors, $form_data ) use ( $guard ) {
				return $this->validate( $guard, $errors, $form_data );
			},
			20,
			2
		);
	}

	/**
	 * Hold on to a submission's raw fields until the errors filter runs.
	 *
	 * @param array $entry     The raw entry WPForms is processing.
	 * @param array $form_data The form definition.
	 * @return void
	 */
	public function remember_entry( $entry, $form_data ) {
		if ( ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return;
		}

		$this->entries[ (string) $form_data['id'] ] = is_array( $entry ) && isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? $entry['fields'] : array();
	}

	/**
	 * Add an error to the mapped phone field when it cannot be texted.
	 *
	 * @param Send4_Phone_Guard $guard     The phone guard.
	 * @param array             $errors    WPForms' errors, keyed by form id then field id.
	 * @param array             $form_data The form definition.
	 * @return array
	 */
	public function validate( Send4_Phone_Guard $guard, $errors, $form_data ) {
		if ( ! is_array( $errors ) || ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return $errors;
		}

		$form_id  = (string) $form_data['id'];
		$form_key = $this->slug() . ':' . $form_id;
		$field_id = $guard->field_id( $form_key );

		if ( '' === $field_id || ! isset( $this->entries[ $form_id ] ) ) {
			return $errors;
		}

		$fields = $this->entries[ $form_id ];

		unset( $this->entries[ $form_id ] );

		// Fail open. An error on a field id the form no longer defines renders
		// nowhere, and the visitor would face a form that refuses to send with
		// nothing on screen to say why.
		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) || ! isset( $form_data['fields'][ $field_id ] ) ) {
			return $errors;
		}

		if ( ! empty( $errors[ $form_id ][ $field_id ] ) ) {
			return $errors;
		}

		$message = $guard->reject( $form_key, isset( $fields[ $field_id ] ) ? $fields[ $field_id ] : '' );

		if ( '' !== $message ) {
			$errors[ $form_id ][ $field_id ] = $message;
		}

		return $errors;
	}

	/**
	 * Build a submission from WPForms' processed fields.
	 *
	 * @param array $fields    Processed fields keyed by field id.
	 * @param array $form_data The form definition.
	 * @param mixed $entry_id  Entry id; 0 on Lite, which stores no entries.
	 * @return Send4_Submission|null
	 */
	public function capture( $fields, $form_data, $entry_id = 0 ) {
		if ( ! is_array( $fields ) || ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return null;
		}

		$captured = array();

		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( in_array( $type, self::$ignored_types, true ) ) {
				continue;
			}

			$captured[] = Send4_Submission::field(
				(string) $id,
				isset( $field['name'] ) ? (string) $field['name'] : '',
				isset( $field['value'] ) ? $field['value'] : ''
			);
		}

		$title = '';

		if ( isset( $form_data['settings']['form_title'] ) ) {
			$title = (string) $form_data['settings']['form_title'];
		}

		return new Send4_Submission(
			$this->slug() . ':' . $form_data['id'],
			$title,
			$captured,
			empty( $entry_id ) ? null : (string) $entry_id
		);
	}

	/**
	 * Mappable fields from a stored form definition.
	 *
	 * @param array $form_data Decoded form definition.
	 * @return array<int,array{id:string,label:string,type:string}>
	 */
	private function fields_from_form_data( array $form_data ) {
		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $form_data['fields'] as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( in_array( $type, self::$ignored_types, true ) ) {
				continue;
			}

			$fields[] = array(
				'id'      => (string) $id,
				'label'   => isset( $field['label'] ) ? (string) $field['label'] : '',
				'type'    => $type,
				'options' => $this->choices_for( $field ),
			);
		}

		return $fields;
	}

	/**
	 * The choices of a pick-list field, as they will REACH Send4 (#0687).
	 *
	 * ⚠️ The label, never the custom value — even when the choice has one.
	 * WPForms posts the raw value with "Show Values" on, but
	 * `WPForms_Field_Select::format()` then looks the LABEL back up and stores
	 * that as the field's `value`, which is what capture() reads and sends. A
	 * choice labelled "Installation" with value "install" therefore arrives as
	 * "Installation", and reporting "install" here would build a condition in
	 * Send4's builder that can never match.
	 *
	 * @param array $field Field definition.
	 * @return array<int,string>
	 */
	private function choices_for( array $field ) {
		if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
			return array();
		}

		$options = array();

		foreach ( $field['choices'] as $choice ) {
			if ( ! is_array( $choice ) || ! isset( $choice['label'] ) ) {
				continue;
			}

			$label = trim( (string) $choice['label'] );

			// '0' is a legitimate choice, so compare against the empty string
			// rather than testing truthiness.
			if ( '' !== $label ) {
				$options[] = $label;
			}
		}

		return $options;
	}
}
