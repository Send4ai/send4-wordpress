<?php
/**
 * Elementor Pro forms.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks elementor_pro/forms/new_record, which fires after validation with a
 * Form_Record carrying the submitted fields.
 *
 * Elementor stores forms inside page content rather than as their own objects,
 * so detection walks the _elementor_data of pages that contain a form widget.
 */
class Send4_Adapter_Elementor implements Send4_Form_Adapter, Send4_Validating_Adapter, Send4_Findable_Adapter, Send4_Truncating_Adapter {

	/**
	 * How many posts one detection reads. Each is a json_decode of a whole
	 * page's builder tree, so this stays bounded; find_form() reaches a form
	 * past it.
	 */
	const PAGE_LIMIT = 100;

	/**
	 * Field types that carry no answer, or one that must never leave the site.
	 *
	 * A password field: a registration form with a mapped phone would
	 * otherwise send the visitor's password to Send4, and keep it in the retry
	 * queue while Send4 is unreachable (#0697). Applied to capture and to the
	 * declared fields alike, so the keys Send4 is told about are the keys it
	 * receives.
	 *
	 * @var string[]
	 */
	private static $ignored_types = array( 'html', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot', 'upload', 'password' );

	/**
	 * Whether the last detect_forms() read PAGE_LIMIT posts, and so may have
	 * left some out.
	 *
	 * @var bool
	 */
	private $truncated = false;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'Elementor Pro';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return did_action( 'elementor_pro/init' ) > 0
			|| class_exists( '\ElementorPro\Plugin' )
			|| defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * One entry per form widget, however many posts carry it. The same widget
	 * id turns up on a draft and its published page, on a page and the copy a
	 * duplicator made of it; submissions carry only the widget id, so they are
	 * one form to Send4 too. The first copy pages_with_forms() returns wins:
	 * published before anything else, then the most recently edited, which
	 * is the field list the site is actually serving.
	 */
	public function detect_forms() {
		$this->truncated = false;

		if ( ! $this->is_available() ) {
			return array();
		}

		$forms = array();
		$pages = $this->pages_with_forms();

		$this->truncated = count( $pages ) >= self::PAGE_LIMIT;

		foreach ( $pages as $page ) {
			foreach ( $this->forms_in( $page ) as $form_id => $form ) {
				if ( ! isset( $forms[ $form_id ] ) ) {
					$forms[ $form_id ] = $form;
				}
			}
		}

		return array_values( $forms );
	}

	/**
	 * {@inheritDoc}
	 */
	public function detection_truncated() {
		return $this->truncated;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Asks the database for the posts holding this widget id rather than
	 * walking every form on the site, so a form on the 101st page is still
	 * found. The same ordering as detect_forms() picks the same copy.
	 */
	public function find_form( $form_id ) {
		$form_id = (string) $form_id;

		if ( '' === $form_id || ! $this->is_available() ) {
			return null;
		}

		foreach ( $this->pages_with_forms( '"id":' . (string) wp_json_encode( $form_id ) ) as $page ) {
			$forms = $this->forms_in( $page );

			if ( isset( $forms[ $form_id ] ) ) {
				return $forms[ $form_id ];
			}
		}

		return null;
	}

	/**
	 * The form widgets in one post's builder data, keyed by widget id.
	 *
	 * @param object $page A row from pages_with_forms().
	 * @return array<string,array>
	 */
	private function forms_in( $page ) {
		$data = json_decode( (string) $page->meta_value, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$forms = array();

		foreach ( $this->walk_widgets( $data ) as $widget ) {
			$settings = isset( $widget['settings'] ) && is_array( $widget['settings'] ) ? $widget['settings'] : array();
			$form_id  = isset( $widget['id'] ) ? (string) $widget['id'] : '';

			if ( '' === $form_id || isset( $forms[ $form_id ] ) ) {
				continue;
			}

			$forms[ $form_id ] = array(
				'key'    => $this->slug() . ':' . $form_id,
				'id'     => $form_id,
				'title'  => isset( $settings['form_name'] ) ? (string) $settings['form_name'] : __( 'Elementor form', 'send4' ),
				'fields' => $this->fields_from_settings( $settings ),
			);
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( callable $on_submit ) {
		add_action(
			'elementor_pro/forms/new_record',
			function ( $record, $handler = null ) use ( $on_submit ) {
				unset( $handler );

				$captured = $this->capture( $record );

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
	 * The elementor_pro/forms/validation action receives the same Form_Record as
	 * new_record, one stage earlier. An error added here makes Elementor answer
	 * the AJAX submit with it and never fire new_record, so capture is skipped.
	 * The form key comes from form_id_for(), the one derivation capture uses.
	 *
	 * @see https://developers.elementor.com/docs/hooks/forms/
	 */
	public function register_validation( Send4_Phone_Guard $guard ) {
		add_action(
			'elementor_pro/forms/validation',
			function ( $record, $ajax_handler ) use ( $guard ) {
				$this->validate( $guard, $record, $ajax_handler );
			},
			20,
			2
		);
	}

	/**
	 * Add an error to the mapped phone field when it cannot be texted.
	 *
	 * @param Send4_Phone_Guard $guard        The phone guard.
	 * @param object            $record       Form_Record.
	 * @param object            $ajax_handler Elementor Pro's Ajax_Handler.
	 * @return void
	 */
	public function validate( Send4_Phone_Guard $guard, $record, $ajax_handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		if ( ! is_object( $ajax_handler ) || ! method_exists( $ajax_handler, 'add_error' ) ) {
			return;
		}

		$form_key = $this->slug() . ':' . $this->form_id_for( $record );
		$field_id = $guard->field_id( $form_key );

		if ( '' === $field_id ) {
			return;
		}

		$raw   = $record->get( 'fields' );
		$field = null;

		foreach ( is_array( $raw ) ? $raw : array() as $id => $candidate ) {
			$candidate_id = is_array( $candidate ) && isset( $candidate['id'] ) ? (string) $candidate['id'] : (string) $id;

			if ( is_array( $candidate ) && $candidate_id === $field_id ) {
				$field = $candidate;
				break;
			}
		}

		// Fail open: an error on a field the form does not render would leave
		// the visitor a form that will not send and no message to explain it.
		if ( null === $field ) {
			return;
		}

		if ( isset( $ajax_handler->errors ) && is_array( $ajax_handler->errors ) && ! empty( $ajax_handler->errors[ $field_id ] ) ) {
			return;
		}

		$message = $guard->reject( $form_key, isset( $field['value'] ) ? $field['value'] : '' );

		if ( '' !== $message ) {
			$ajax_handler->add_error( $field_id, $message );
		}
	}

	/**
	 * Build a submission from an Elementor Form_Record.
	 *
	 * @param object $record Form_Record.
	 * @return Send4_Submission|null
	 */
	public function capture( $record ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return null;
		}

		$raw = $record->get( 'fields' );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$fields = array();

		foreach ( $raw as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( in_array( $type, self::$ignored_types, true ) ) {
				continue;
			}

			$fields[] = Send4_Submission::field(
				isset( $field['id'] ) ? (string) $field['id'] : (string) $id,
				isset( $field['title'] ) ? (string) $field['title'] : '',
				isset( $field['value'] ) ? $field['value'] : ''
			);
		}

		return new Send4_Submission(
			$this->slug() . ':' . $this->form_id_for( $record ),
			(string) $this->setting( $record, 'form_name' ),
			$fields,
			null
		);
	}

	/**
	 * The widget id Elementor assigns the form, which is what the form key uses.
	 *
	 * @param object $record Form_Record.
	 * @return string
	 */
	private function form_id_for( $record ) {
		$id = $this->setting( $record, 'id' );

		if ( '' !== (string) $id ) {
			return (string) $id;
		}

		if ( method_exists( $record, 'get' ) ) {
			$settings = $record->get( 'form_settings' );

			if ( is_array( $settings ) && ! empty( $settings['id'] ) ) {
				return (string) $settings['id'];
			}
		}

		return 'unknown';
	}

	/**
	 * Read one form setting off the record.
	 *
	 * @param object $record Form_Record.
	 * @param string $key    Setting key.
	 * @return mixed
	 */
	private function setting( $record, $key ) {
		if ( method_exists( $record, 'get_form_settings' ) ) {
			$value = $record->get_form_settings( $key );

			if ( null !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Posts whose Elementor data contains a form widget, the copy to trust
	 * first.
	 *
	 * Joined to the posts table because the meta alone lies (#0697). Elementor
	 * copies _elementor_data onto every revision, so one form edited thirty
	 * times was thirty-one rows — some with a field list long since changed —
	 * which filled the limit and pushed live forms out of it. Trashed pages
	 * and auto-drafts are not forms anyone can submit either. 'inherit' is the
	 * status revisions and autosaves carry, excluded alongside the type.
	 *
	 * Any other post type stays: Elementor keeps popups and saved templates in
	 * elementor_library, and a form inside a popup is as live as one on a page.
	 *
	 * @param string $contains Optional extra JSON fragment the data must hold,
	 *                         to narrow the search to one widget.
	 * @return array<int,object>
	 */
	private function pages_with_forms( $contains = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_elementor_data'
					AND pm.meta_value LIKE %s
					AND pm.meta_value LIKE %s
					AND p.post_type <> 'revision'
					AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
				ORDER BY ( p.post_status = 'publish' ) DESC, p.post_modified_gmt DESC, p.ID DESC
				LIMIT %d",
				'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%',
				// Only wildcards when there is nothing to narrow by: one query
				// text, and a pattern every row matches.
				'%' . $wpdb->esc_like( $contains ) . '%',
				self::PAGE_LIMIT
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Depth-first walk of Elementor's nested element tree, yielding form widgets.
	 *
	 * @param array $elements Elementor elements.
	 * @return array<int,array>
	 */
	private function walk_widgets( array $elements ) {
		$found = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$is_form = isset( $element['widgetType'] ) && 'form' === $element['widgetType'];

			if ( $is_form ) {
				$found[] = $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = array_merge( $found, $this->walk_widgets( $element['elements'] ) );
			}
		}

		return $found;
	}

	/**
	 * Mappable fields from a form widget's settings.
	 *
	 * @param array $settings Widget settings.
	 * @return array<int,array{id:string,label:string,type:string}>
	 */
	private function fields_from_settings( array $settings ) {
		if ( empty( $settings['form_fields'] ) || ! is_array( $settings['form_fields'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $settings['form_fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['field_type'] ) ? (string) $field['field_type'] : '';
			$id   = isset( $field['custom_id'] ) ? (string) $field['custom_id'] : '';

			if ( '' === $id || in_array( $type, self::$ignored_types, true ) ) {
				continue;
			}

			$fields[] = array(
				'id'      => $id,
				'label'   => isset( $field['field_label'] ) ? (string) $field['field_label'] : '',
				'type'    => $type,
				'options' => $this->choices_for( $field ),
			);
		}

		return $fields;
	}

	/**
	 * The choices of a pick-list field, as they will REACH Send4 (#0687).
	 *
	 * Elementor stores them as newline-separated lines in one string, and a line
	 * may carry a custom value as `Label|value`. It submits the VALUE half, so
	 * that is what capture() reads and what a condition in Send4's builder has
	 * to be written against — reporting the whole line verbatim would build a
	 * condition that can never match.
	 *
	 * @see https://elementor.com/help/custom-labels-values-in-select-radio-and-checkbox-form-fields/
	 *
	 * @param array $field Field definition.
	 * @return array<int,string>
	 */
	private function choices_for( array $field ) {
		if ( ! isset( $field['field_options'] ) || ! is_string( $field['field_options'] ) ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $field['field_options'] );
		$options = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$separator = strpos( $line, '|' );

			if ( false !== $separator ) {
				$line = trim( substr( $line, $separator + 1 ) );
			}

			// '0' is a legitimate option. Comparing against the empty string
			// rather than testing truthiness is what keeps it.
			if ( '' !== $line && ! in_array( $line, $options, true ) ) {
				$options[] = $line;
			}
		}

		return $options;
	}
}
