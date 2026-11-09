<?php
/**
 * The descriptors this site reports to Send4.
 *
 * @package Send4
 */

/**
 * The contract that matters here is KEY PARITY: every key a descriptor declares
 * must be a key a real submission of that form actually carries, and vice
 * versa. Send4 renders the declared keys in its flow builder's variable picker,
 * so a descriptor that guesses offers the customer a variable that is never
 * populated — and nothing errors anywhere along the way.
 */
class Send4_Form_Descriptors_Test extends Send4_Test_Case {

	/**
	 * A detected form and a matching submission built from the same fields, so
	 * the two halves cannot drift apart in the fixture itself.
	 *
	 * @param array $fields  id => array{label, type, value, options?}.
	 * @param array $mapping phone/name/email field ids.
	 * @return array{descriptor:array,envelope:array}
	 */
	private function bothSidesOf( array $fields, array $mapping = array( 'phone' => 'phone' ) ) {
		$detected  = array();
		$submitted = array();

		foreach ( $fields as $id => $field ) {
			$row = array(
				'id'    => (string) $id,
				'label' => $field['label'],
				'type'  => isset( $field['type'] ) ? $field['type'] : 'text',
			);

			if ( isset( $field['options'] ) ) {
				$row['options'] = $field['options'];
			}

			$detected[]  = $row;
			$submitted[] = Send4_Submission::field( (string) $id, $field['label'], $field['value'] );
		}

		$form = array(
			'key'          => 'contact-form-7:1',
			'adapter'      => 'contact-form-7',
			'adapter_name' => 'Contact Form 7',
			'title'        => 'Kontaktų forma',
			'fields'       => $detected,
		);

		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () use ( $form ) {
				return array( new Send4_Descriptor_Fixture_Adapter( $form ) );
			}
		);

		Send4_Settings::update_form(
			'contact-form-7:1',
			array(
				'enabled' => true,
				'mapping' => $mapping,
			)
		);

		$descriptors = Send4_Form_Descriptors::build();

		$submission = new Send4_Submission( 'contact-form-7:1', 'Kontaktų forma', $submitted, null );
		$envelope   = Send4_Normalizer::build( $submission, $mapping, 'contact-form-7:1:1:abc' );

		return array(
			'descriptor' => $descriptors[0],
			'envelope'   => $envelope,
		);
	}

	/**
	 * The whole point of the feature. If this ever fails, Send4's builder is
	 * offering variables that submissions do not carry.
	 *
	 * @dataProvider parityProvider
	 *
	 * @param array $fields  Field definitions with values.
	 * @param array $mapping Field mapping.
	 * @return void
	 */
	public function test_declared_keys_are_the_keys_a_submission_carries( array $fields, array $mapping ) {
		$both = $this->bothSidesOf( $fields, $mapping );

		$this->assertSame(
			array_keys( $both['envelope']['data'] ),
			wp_list_pluck( $both['descriptor']['fields'], 'key' )
		);
	}

	/**
	 * Each case is a way the normalizer's key rules bite.
	 *
	 * @return array<string,array{0:array,1:array}>
	 */
	public function parityProvider() {
		return array(
			'plain labels'            => array(
				array(
					'phone' => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'city'  => array(
						'label' => 'City',
						'value' => 'Vilnius',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// Two fields labelled the same get `_2` appended to the second, and
			// a descriptor that slugged each label alone would declare `notes`
			// twice and never mention `notes_2`.
			'duplicate labels'        => array(
				array(
					'phone'  => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'notes1' => array(
						'label' => 'Notes',
						'value' => 'first',
					),
					'notes2' => array(
						'label' => 'Notes',
						'value' => 'second',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// A field labelled "Lead" would clobber the envelope's lead block,
			// so the normalizer renames it to `field_lead`.
			'a reserved label'        => array(
				array(
					'phone' => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'lead'  => array(
						'label' => 'Lead',
						'value' => 'from an ad',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// No label at all: the key falls back to the field id.
			'an unlabelled field'     => array(
				array(
					'phone'      => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'custom_ref' => array(
						'label' => '',
						'value' => 'abc',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// Mapped fields go to lead.*, so they must NOT be declared — Send4
			// already knows those paths and would show them twice.
			'name and email mapped'   => array(
				array(
					'phone' => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'name'  => array(
						'label' => 'Your name',
						'value' => 'Jonas',
					),
					'email' => array(
						'label' => 'Email',
						'value' => 'j@x.lt',
					),
					'city'  => array(
						'label' => 'City',
						'value' => 'Vilnius',
					),
				),
				array(
					'phone' => 'phone',
					'name'  => 'name',
					'email' => 'email',
				),
			),
			'labels that slug to one' => array(
				array(
					'phone' => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'a'     => array(
						'label' => 'How did you hear?',
						'value' => 'Google',
					),
					'b'     => array(
						'label' => 'How did you hear',
						'value' => 'A friend',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// Long enough to hit the key cap, so the `_2` must be made room
			// for rather than truncated away.
			'long duplicate labels'   => array(
				array(
					'phone' => array(
						'label' => 'Phone',
						'value' => '+37060012345',
					),
					'a'     => array(
						'label' => str_repeat( 'x', 200 ),
						'value' => 'first',
					),
					'b'     => array(
						'label' => str_repeat( 'x', 200 ),
						'value' => 'second',
					),
				),
				array( 'phone' => 'phone' ),
			),
			// Non-Latin labels fall back to the field id — on both sides. A
			// numbered id, as WPForms and Gravity Forms use, gets a prefix.
			'non-Latin labels'        => array(
				array(
					'phone'   => array(
						'label' => 'Телефон',
						'value' => '+37060012345',
					),
					'name'    => array(
						'label' => 'Имя',
						'value' => 'Иван',
					),
					'message' => array(
						'label' => 'رسالتك',
						'value' => 'مرحبا',
					),
					'4'       => array(
						'label' => 'Όνομα',
						'value' => 'Γιώργος',
					),
				),
				array( 'phone' => 'phone' ),
			),
		);
	}

	/**
	 * The keys a non-Latin form declares are its field ids, not hex.
	 *
	 * @return void
	 */
	public function test_non_latin_labels_are_declared_under_their_field_ids() {
		$both = $this->bothSidesOf(
			array(
				'phone' => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'name'  => array(
					'label' => 'Имя',
					'value' => 'Иван',
				),
				'???'   => array(
					'label' => 'שם משפחה',
					'value' => 'כהן',
				),
			)
		);

		$this->assertSame( array( 'name', 'field_3' ), wp_list_pluck( $both['descriptor']['fields'], 'key' ) );
		$this->assertSame( array( 'name', 'field_3' ), array_keys( $both['envelope']['data'] ) );

		// The label still reads as the owner wrote it: only the key changed.
		$this->assertSame( 'Имя', $both['descriptor']['fields'][0]['label'] );
	}

	/**
	 * Every string the API holds to 191 characters is capped at 191, counted
	 * in characters. One over-long label used to 422 the whole inventory, and
	 * the daily sync resent it unchanged forever.
	 *
	 * @return void
	 */
	public function test_long_names_and_labels_are_capped_at_the_api_limit() {
		$both = $this->bothSidesOf(
			array(
				'phone' => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'q'     => array(
					'label' => str_repeat( 'ž', 300 ),
					'value' => 'yes',
				),
			)
		);

		$field = $both['descriptor']['fields'][0];

		$this->assertSame( 191, mb_strlen( $field['label'] ) );
		$this->assertSame( str_repeat( 'ž', 191 ), $field['label'] );
		$this->assertLessThanOrEqual( 191, strlen( $field['key'] ) );
	}

	/**
	 * The form's own identity is capped the same way.
	 *
	 * @return void
	 */
	public function test_a_long_form_title_and_adapter_are_capped() {
		$form = array(
			'key'    => 'contact-form-7:1',
			'title'  => str_repeat( 'Ž', 250 ),
			'fields' => array(),
		);

		// A third-party adapter names itself, and nothing stopped it from
		// naming itself at length.
		$adapter = new class( $form ) extends Send4_Descriptor_Fixture_Adapter {
			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function slug() {
				return str_repeat( 'a', 40 );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function name() {
				return str_repeat( 'Ą', 250 );
			}
		};

		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () use ( $adapter ) {
				return array( $adapter );
			}
		);

		$descriptor = Send4_Form_Descriptors::build()[0];

		$this->assertSame( str_repeat( 'Ž', 191 ), $descriptor['name'] );
		$this->assertSame( str_repeat( 'Ą', 191 ), $descriptor['adapter_name'] );
		$this->assertSame( str_repeat( 'a', 32 ), $descriptor['adapter'] );
		$this->assertSame( 'contact-form-7:1', $descriptor['form_key'] );
	}

	/**
	 * One choice over 191 characters drops the list, as too many choices do:
	 * a truncated choice is one no submission ever carries.
	 *
	 * @return void
	 */
	public function test_a_choice_over_the_limit_drops_the_list() {
		$both = $this->bothSidesOf(
			array(
				'phone'   => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'agree'   => array(
					'label'   => 'Terms',
					'type'    => 'checkbox',
					'value'   => 'Short',
					'options' => array( 'Short', str_repeat( 'o', 192 ) ),
				),
			)
		);

		$field = $both['descriptor']['fields'][0];

		$this->assertSame( array(), $field['options'] );
		// Still a pick-list: the list was withheld, not absent.
		$this->assertSame( 'option', $field['type'] );
	}

	/**
	 * WPForms' slider posts a number and its GDPR agreement is a yes/no.
	 *
	 * @return void
	 */
	public function test_wpforms_slider_and_gdpr_types_are_mapped() {
		$both = $this->bothSidesOf(
			array(
				'phone'  => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'budget' => array(
					'label' => 'Budget',
					'type'  => 'number-slider',
					'value' => '1500',
				),
				'gdpr'   => array(
					'label' => 'GDPR',
					'type'  => 'gdpr-checkbox',
					'value' => 'I consent',
				),
			)
		);

		$this->assertSame( array( 'number', 'boolean' ), wp_list_pluck( $both['descriptor']['fields'], 'type' ) );
	}

	/**
	 * A blank field is not sent, but it still consumed its key — otherwise the
	 * `_2` on a later duplicate would shift and stop matching the descriptor.
	 *
	 * @return void
	 */
	public function test_a_blank_field_does_not_renumber_the_fields_after_it() {
		$both = $this->bothSidesOf(
			array(
				'phone'  => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'notes1' => array(
					'label' => 'Notes',
					'value' => '',
				),
				'notes2' => array(
					'label' => 'Notes',
					'value' => 'second',
				),
			),
			array( 'phone' => 'phone' )
		);

		// The declaration names both, because both exist on the form.
		$this->assertSame( array( 'notes', 'notes_2' ), wp_list_pluck( $both['descriptor']['fields'], 'key' ) );

		// The submission carries only the one that was filled in — and it is
		// still `notes_2`, exactly as declared.
		$this->assertSame( array( 'notes_2' => 'second' ), $both['envelope']['data'] );
	}

	public function test_a_descriptor_carries_the_form_identity_send4_renders() {
		$both = $this->bothSidesOf(
			array(
				'phone' => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
			)
		);

		$this->assertSame( 'contact-form-7:1', $both['descriptor']['form_key'] );
		$this->assertSame( 'contact-form-7', $both['descriptor']['adapter'] );
		$this->assertSame( 'Contact Form 7', $both['descriptor']['adapter_name'] );
		$this->assertSame( 'Kontaktų forma', $both['descriptor']['name'] );
	}

	/**
	 * `enabled` answers "will submissions actually reach Send4", so a form
	 * switched on without the phone mapping delivery needs reports as off.
	 * Showing it as on would send the customer hunting for a fault in Send4.
	 *
	 * @return void
	 */
	public function test_enabled_means_it_will_really_send() {
		$fields = array(
			'phone' => array(
				'label' => 'Phone',
				'value' => '+37060012345',
			),
		);

		$this->bothSidesOf( $fields, array( 'phone' => 'phone' ) );
		$this->assertTrue( Send4_Form_Descriptors::build()[0]['enabled'] );

		Send4_Settings::update_form( 'contact-form-7:1', array( 'mapping' => array( 'phone' => '' ) ) );
		$this->assertFalse( Send4_Form_Descriptors::build()[0]['enabled'] );

		Send4_Settings::update_form(
			'contact-form-7:1',
			array(
				'enabled' => false,
				'mapping' => array( 'phone' => 'phone' ),
			)
		);
		$this->assertFalse( Send4_Form_Descriptors::build()[0]['enabled'] );
	}

	/**
	 * Types drive which operators Send4's conditional card offers, so they are
	 * narrowed to its vocabulary rather than passed through raw.
	 *
	 * @return void
	 */
	public function test_field_types_are_narrowed_to_send4s_vocabulary() {
		$both = $this->bothSidesOf(
			array(
				'phone'    => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'email'    => array(
					'label' => 'Email',
					'type'  => 'email*',
					'value' => 'j@x.lt',
				),
				'budget'   => array(
					'label' => 'Budget',
					'type'  => 'number',
					'value' => '1500',
				),
				'service'  => array(
					'label'   => 'Service',
					'type'    => 'select',
					'value'   => 'install',
					'options' => array( 'install', 'repair' ),
				),
				'newsletter' => array(
					'label' => 'Newsletter',
					'type'  => 'checkbox',
					'value' => 'on',
				),
				'anything' => array(
					'label' => 'Anything else',
					'type'  => 'wysiwyg',
					'value' => 'hello',
				),
			),
			array( 'phone' => 'phone' )
		);

		$types = array();
		foreach ( $both['descriptor']['fields'] as $field ) {
			$types[ $field['key'] ] = $field['type'];
		}

		// A required marker is a validation rule, not a different type.
		$this->assertSame( 'email', $types['email'] );
		$this->assertSame( 'number', $types['budget'] );
		$this->assertSame( 'option', $types['service'] );
		// A checkbox with no choices is a single yes/no. It arrives as the
		// string "on", which Send4 could never match against a real boolean
		// without being told the field is one.
		$this->assertSame( 'boolean', $types['newsletter'] );
		// Unknown types stay usable rather than being dropped. Note the key is
		// slugged from the LABEL, not the field id — which is exactly why the
		// descriptor asks the normalizer for it instead of guessing.
		$this->assertSame( 'text', $types['anything_else'] );

		$options = array();
		foreach ( $both['descriptor']['fields'] as $field ) {
			$options[ $field['key'] ] = $field['options'];
		}
		$this->assertSame( array( 'install', 'repair' ), $options['service'] );
	}

	/**
	 * Send4 answers 422 for the WHOLE payload when one field exceeds its option
	 * limit, and the retry would resend the same invalid body forever. One
	 * country dropdown must not cost the site every other form in its builder.
	 *
	 * @return void
	 */
	public function test_a_field_with_too_many_options_drops_the_list_rather_than_the_inventory() {
		$countries = array();
		for ( $i = 0; $i < Send4_Form_Descriptors::MAX_OPTIONS + 1; $i++ ) {
			$countries[] = 'Country ' . $i;
		}

		$both = $this->bothSidesOf(
			array(
				'phone'   => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'country' => array(
					'label'   => 'Country',
					'type'    => 'select',
					'value'   => 'Country 1',
					'options' => $countries,
				),
			),
			array( 'phone' => 'phone' )
		);

		$fields = $both['descriptor']['fields'];

		// The field is still declared and still typed — only the choice list is
		// withheld. A TRUNCATED list would be a lie: Send4 would render the
		// first 50 as though they were the choices.
		$this->assertCount( 1, $fields );
		$this->assertSame( 'country', $fields[0]['key'] );
		$this->assertSame( array(), $fields[0]['options'] );
	}

	public function test_a_list_exactly_on_the_limit_is_still_reported() {
		$options = array();
		for ( $i = 0; $i < Send4_Form_Descriptors::MAX_OPTIONS; $i++ ) {
			$options[] = 'Option ' . $i;
		}

		$both = $this->bothSidesOf(
			array(
				'phone'  => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
				'choice' => array(
					'label'   => 'Choice',
					'type'    => 'select',
					'value'   => 'Option 1',
					'options' => $options,
				),
			),
			array( 'phone' => 'phone' )
		);

		$this->assertCount( Send4_Form_Descriptors::MAX_OPTIONS, $both['descriptor']['fields'][0]['options'] );
	}

	/**
	 * Same reasoning one level up. The declaration is a hint — an undeclared key
	 * still reaches the run — so dropping the tail of an enormous form costs far
	 * less than a 422 that loses everything.
	 *
	 * @return void
	 */
	public function test_a_form_with_too_many_fields_is_truncated_not_rejected() {
		$fields = array(
			'phone' => array(
				'label' => 'Phone',
				'value' => '+37060012345',
			),
		);

		for ( $i = 0; $i < Send4_Form_Descriptors::MAX_FIELDS + 20; $i++ ) {
			$fields[ 'f' . $i ] = array(
				'label' => 'Field ' . $i,
				'value' => 'x',
			);
		}

		$descriptors = $this->bothSidesOf( $fields, array( 'phone' => 'phone' ) );

		$this->assertCount( Send4_Form_Descriptors::MAX_FIELDS, $descriptors['descriptor']['fields'] );
	}

	public function test_the_hash_changes_only_when_the_inventory_does() {
		$this->bothSidesOf(
			array(
				'phone' => array(
					'label' => 'Phone',
					'value' => '+37060012345',
				),
			)
		);

		$first = Send4_Form_Descriptors::hash( Send4_Form_Descriptors::build() );

		$this->assertSame( $first, Send4_Form_Descriptors::hash( Send4_Form_Descriptors::build() ) );

		// Switching a form off changes what Send4 should render, so it must
		// change the fingerprint or the sync would skip it.
		Send4_Settings::update_form( 'contact-form-7:1', array( 'enabled' => false ) );

		$this->assertNotSame( $first, Send4_Form_Descriptors::hash( Send4_Form_Descriptors::build() ) );
	}
}

/**
 * An adapter that reports exactly the form a test handed it.
 */
class Send4_Descriptor_Fixture_Adapter implements Send4_Form_Adapter {

	/**
	 * The form to report.
	 *
	 * @var array
	 */
	private $form;

	/**
	 * Constructor.
	 *
	 * @param array $form Detected form.
	 */
	public function __construct( array $form ) {
		$this->form = $form;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'contact-form-7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name() {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function detect_forms() {
		return array( $this->form );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param callable $on_submit Submission callback.
	 * @return void
	 */
	public function register_hooks( callable $on_submit ) {
		unset( $on_submit );
	}
}
