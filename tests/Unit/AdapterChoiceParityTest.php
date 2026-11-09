<?php
/**
 * The choices an adapter reports must be the choices it delivers.
 *
 * @package Send4
 */

/**
 * A pick-list's options exist so Send4's conditional card can offer them. That
 * only helps if the option a customer picks in the builder is the string their
 * form actually submits — otherwise the builder quietly writes a condition that
 * can never match, and nothing errors anywhere.
 *
 * Each form plugin decides that differently, and two of them disagree with the
 * obvious reading of their own stored definition:
 *
 * | Plugin         | Stored              | Delivered | Report |
 * |----------------|---------------------|-----------|--------|
 * | WPForms        | label + value       | LABEL     | label  |
 * | Elementor Pro  | `Label|value` lines | VALUE     | value  |
 * | Gravity Forms  | text + value        | VALUE     | value  |
 * | Contact Form 7 | values (labels)     | LABEL     | label  |
 * | CF7, piped     | `Label|value`       | VALUE     | value  |
 */
class Send4_Adapter_Choice_Parity_Test extends Send4_Test_Case {

	/**
	 * The options an adapter reports for its one detected form's first
	 * pick-list field.
	 *
	 * @param Send4_Form_Adapter $adapter Adapter under test.
	 * @param string             $field_id Field to read.
	 * @return array<int,string>
	 */
	private function reportedOptions( Send4_Form_Adapter $adapter, $field_id ) {
		foreach ( $adapter->detect_forms() as $form ) {
			foreach ( $form['fields'] as $field ) {
				if ( (string) $field['id'] === $field_id ) {
					return isset( $field['options'] ) ? $field['options'] : array();
				}
			}
		}

		return array();
	}

	/**
	 * WPForms posts the raw value with "Show Values" on, but
	 * WPForms_Field_Select::format() looks the LABEL back up and stores that as
	 * the field's `value` — which is what capture() reads and sends.
	 *
	 * @return void
	 */
	public function test_wpforms_reports_the_label_because_that_is_what_it_delivers() {
		$form_id = $this->seedWpformsSelect();

		$options = $this->reportedOptions( new Send4_Adapter_Wpforms(), '1' );

		$this->assertSame( array( 'Installation', 'Repair', 'Free quote' ), $options );

		// And the value a real submission carries is one of exactly those.
		$submission = ( new Send4_Adapter_Wpforms() )->capture(
			array(
				'1' => array(
					'name'  => 'Service',
					'value' => 'Installation',
					'type'  => 'select',
				),
			),
			array(
				'id'       => $form_id,
				'settings' => array( 'form_title' => 'Quote' ),
			),
			0
		);

		$this->assertContains( $submission->fields[0]['value'], $options );
	}

	/**
	 * Elementor stores `Label|value` and submits the value half.
	 *
	 * @return void
	 */
	public function test_elementor_reports_the_value_half_of_a_piped_option() {
		$this->seedElementorSelect( "Installation|install\nRepair|repair\nFree quote" );

		$options = $this->reportedOptions( new Send4_Adapter_Elementor(), 'service' );

		// The value half where there is one; the whole line where there is not.
		$this->assertSame( array( 'install', 'repair', 'Free quote' ), $options );
	}

	/**
	 * A "0" option is a real choice — "0 months", a quantity, a rating. The
	 * obvious array_filter() drops it, and the customer could then never build
	 * a condition on it.
	 *
	 * @return void
	 */
	public function test_elementor_keeps_a_zero_option() {
		$this->seedElementorSelect( "None|0\nOne|1" );

		$this->assertSame( array( '0', '1' ), $this->reportedOptions( new Send4_Adapter_Elementor(), 'service' ) );
	}

	public function test_elementor_ignores_blank_lines_and_duplicates() {
		$this->seedElementorSelect( "Installation|install\n\n  \nRepair|repair\nAlso|install" );

		$this->assertSame( array( 'install', 'repair' ), $this->reportedOptions( new Send4_Adapter_Elementor(), 'service' ) );
	}

	/**
	 * Gravity Forms stores the chosen VALUE on the entry, and value_for() reads
	 * the entry — so here the value, not the text, is the right thing to report.
	 *
	 * @return void
	 */
	public function test_gravity_forms_reports_the_value_because_the_entry_stores_it() {
		$form = array(
			'id'     => 7,
			'title'  => 'Roof quote',
			'fields' => array(
				array(
					'id'      => 3,
					'label'   => 'Service',
					'type'    => 'select',
					'choices' => array(
						array(
							'text'  => 'Installation',
							'value' => 'install',
						),
						// No value set: GF then stores the text.
						array( 'text' => 'Free quote' ),
					),
				),
			),
		);

		$adapter = new Send4_Adapter_Gravityforms();
		$reader  = Closure::bind(
			static function ( $form ) use ( $adapter ) {
				return $adapter->fields_for( $form );
			},
			null,
			Send4_Adapter_Gravityforms::class
		);

		$fields = $reader( $form );

		$this->assertSame( array( 'install', 'Free quote' ), $fields[0]['options'] );
	}

	/**
	 * Contact Form 7 posts the chosen label, and a pick-list tag's quoted values
	 * are those labels.
	 *
	 * @return void
	 */
	public function test_contact_form_7_reports_the_choices_of_every_pick_list_type() {
		Send4_Cf7_Factory::create(
			'Quote',
			'[checkbox topics "Roof" "Gutters"]
[radio contact-by "Phone" "Email"]
[select* service "Installation" "Repair"]
[submit "Send"]'
		);

		$adapter = new Send4_Adapter_Cf7();

		$this->assertSame( array( 'Roof', 'Gutters' ), $this->reportedOptions( $adapter, 'topics' ) );
		$this->assertSame( array( 'Phone', 'Email' ), $this->reportedOptions( $adapter, 'contact-by' ) );
		$this->assertSame( array( 'Installation', 'Repair' ), $this->reportedOptions( $adapter, 'service' ) );
	}

	/**
	 * A pipe shows the visitor the half before the bar and submits the half
	 * after it, so the after half is the choice (#0698). Reporting "Sales" for
	 * a submission that carries "sales@example.com" built conditions that could
	 * never match.
	 *
	 * @return void
	 */
	public function test_contact_form_7_reports_the_piped_value_it_delivers() {
		Send4_Cf7_Factory::create(
			'Quote',
			'[select dept "Sales|sales@example.com" "Support|support@example.com"]
[radio contact-by "By phone|phone" "By email|email"]
[checkbox topics "Roof" "Gutters|gutters"]
[submit "Send"]'
		);

		$adapter = new Send4_Adapter_Cf7();

		$this->assertSame( array( 'sales@example.com', 'support@example.com' ), $this->reportedOptions( $adapter, 'dept' ) );
		$this->assertSame( array( 'phone', 'email' ), $this->reportedOptions( $adapter, 'contact-by' ) );
		// A value with no bar pipes to itself.
		$this->assertSame( array( 'Roof', 'gutters' ), $this->reportedOptions( $adapter, 'topics' ) );
	}

	/**
	 * first_as_label makes the first value the select's prompt, posted as an
	 * empty string. It is not a choice. With include_blank CF7 adds its own
	 * prompt and the first value stays a real option.
	 *
	 * @return void
	 */
	public function test_contact_form_7_does_not_offer_a_first_as_label_prompt() {
		Send4_Cf7_Factory::create(
			'Quote',
			'[select service first_as_label "Choose a service" "Installation" "Repair"]
[select piped first_as_label "Choose…|none" "Sales|sales@example.com"]
[select both include_blank first_as_label "Installation" "Repair"]
[submit "Send"]'
		);

		$adapter = new Send4_Adapter_Cf7();

		$this->assertSame( array( 'Installation', 'Repair' ), $this->reportedOptions( $adapter, 'service' ) );
		$this->assertSame( array( 'sales@example.com' ), $this->reportedOptions( $adapter, 'piped' ) );
		$this->assertSame( array( 'Installation', 'Repair' ), $this->reportedOptions( $adapter, 'both' ) );
	}

	/**
	 * CF7 keeps a free-text tag's placeholder or default value in the same
	 * `values` property a pick-list keeps its choices in. Reported as options,
	 * Send4's conditional card would offer "Your name" as a value to match.
	 *
	 * @return void
	 */
	public function test_contact_form_7_reports_no_choices_for_a_placeholder() {
		Send4_Cf7_Factory::create(
			'Quote',
			'[text* your-name placeholder "Jūsų vardas ir pavardė"]
[email* your-email placeholder "El. paštas"]
[textarea your-message "Default message"]
[acceptance privacy-policy] I agree [/acceptance]
[submit "Send"]'
		);

		$adapter = new Send4_Adapter_Cf7();

		foreach ( array( 'your-name', 'your-email', 'your-message', 'privacy-policy' ) as $field_id ) {
			$this->assertSame( array(), $this->reportedOptions( $adapter, $field_id ), $field_id );
		}
	}

	/**
	 * CF7 has no labels, so the tag name is one. A run of delimiters must not
	 * leave a double space in it.
	 *
	 * @return void
	 */
	public function test_contact_form_7_labels_a_run_of_delimiters_as_one_space() {
		Send4_Cf7_Factory::create( 'Quote', '[text user-info__name-and-surname] [submit "Send"]' );

		$labels = array();

		foreach ( ( new Send4_Adapter_Cf7() )->detect_forms() as $form ) {
			foreach ( $form['fields'] as $field ) {
				$labels[ $field['id'] ] = $field['label'];
			}
		}

		$this->assertSame( 'User info name and surname', $labels['user-info__name-and-surname'] );
	}

	/**
	 * Seed a WPForms form carrying one select with custom values.
	 *
	 * @return int Form id.
	 */
	private function seedWpformsSelect() {
		return wp_insert_post(
			array(
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => 'Quote',
				'post_content' => wp_slash(
					(string) wp_json_encode(
						array(
							'id'       => 1,
							'settings' => array( 'form_title' => 'Quote' ),
							'fields'   => array(
								'1' => array(
									'id'          => '1',
									'type'        => 'select',
									'label'       => 'Service',
									'show_values' => '1',
									'choices'     => array(
										array(
											'label' => 'Installation',
											'value' => 'install',
										),
										array(
											'label' => 'Repair',
											'value' => 'repair',
										),
										// No custom value: the label is posted.
										array( 'label' => 'Free quote' ),
									),
								),
							),
						)
					)
				),
			)
		);
	}

	/**
	 * Seed an Elementor page carrying one select.
	 *
	 * @param string $options Newline-separated option lines.
	 * @return void
	 */
	private function seedElementorSelect( $options ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Contact',
			)
		);

		update_post_meta(
			$post_id,
			'_elementor_data',
			wp_slash(
				(string) wp_json_encode(
					array(
						array(
							'id'         => 'a1b2c3d',
							'elType'     => 'widget',
							'widgetType' => 'form',
							'settings'   => array(
								'form_name'   => 'Contact',
								'form_fields' => array(
									array(
										'custom_id'     => 'service',
										'field_label'   => 'Service',
										'field_type'    => 'select',
										'field_options' => $options,
									),
								),
							),
							'elements'   => array(),
						),
					)
				)
			)
		);
	}
}
