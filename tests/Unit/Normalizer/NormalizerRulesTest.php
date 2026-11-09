<?php
/**
 * The rules the envelope obeys regardless of which form plugin produced it.
 *
 * @package Send4
 */

/**
 * Phone cleaning, data keys, and the envelope's reserved names.
 */
class Send4_Normalizer_Rules_Test extends Send4_Test_Case {

	/**
	 * Build an envelope from a flat id => value map.
	 *
	 * @param array $fields  id => value, or id => array{label,value}.
	 * @param array $mapping phone/name/email field ids.
	 * @return array
	 */
	private function envelopeFor( array $fields, array $mapping = array( 'phone' => 'phone' ) ) {
		$built = array();

		foreach ( $fields as $id => $field ) {
			if ( is_array( $field ) && array_key_exists( 'label', $field ) ) {
				$built[] = Send4_Submission::field( $id, $field['label'], $field['value'] );
				continue;
			}

			$built[] = Send4_Submission::field( $id, ucfirst( (string) $id ), $field );
		}

		$submission = new Send4_Submission( 'contact-form-7:1', 'Test', $built, null );

		return Send4_Normalizer::build( $submission, $mapping, 'contact-form-7:1:1:abc' );
	}

	/**
	 * Separators people type, or paste from a contact card, are stripped.
	 *
	 * @dataProvider phoneProvider
	 *
	 * @param string $input    What was typed.
	 * @param string $expected What is sent.
	 * @return void
	 */
	public function test_it_strips_phone_separators( $input, $expected ) {
		$this->assertSame( $expected, Send4_Normalizer::clean_phone( $input ) );
	}

	/**
	 * Phone inputs and their cleaned form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function phoneProvider() {
		return array(
			'brackets and dashes'  => array( '+370 (600) 12-345', '+37060012345' ),
			'plain spaces'         => array( '+370 600 12345', '+37060012345' ),
			'dots'                 => array( '+370.600.12345', '+37060012345' ),
			'non-breaking space'   => array( "+370\xc2\xa0600\xc2\xa012345", '+37060012345' ),
			'en dash from a card'  => array( "+370\xe2\x80\x93600\xe2\x80\x9312345", '+37060012345' ),
			// The plus counts wherever it appears ahead of the first digit.
			// Losing it would turn an international number into a national one
			// and leave Send4 to guess a country, which is the one thing the
			// normalizer must never make it do.
			'plus inside brackets' => array( '(+370) 600 12345', '+37060012345' ),
			'letters before it'    => array( 'tel: +37060012345', '+37060012345' ),
			'pasted with a nbsp'   => array( "\xc2\xa0+37060012345", '+37060012345' ),
			'pasted with an LTR mark' => array( "\xe2\x80\x8e+37060012345", '+37060012345' ),
			// No country-code inference, ever: a national number stays national
			// and Send4 reports what it could not parse.
			'national stays national' => array( '(8-600) 12345', '860012345' ),
			// 00 is the international prefix: read as a plus when a real
			// calling code follows, left as typed when none does.
			'double zero prefix'   => array( '00370 600 12345', '+37060012345' ),
			'double zero, no code' => array( '00999 600 12345', '0099960012345' ),
			'double zero prefill'  => array( '00370', '' ),
			// Other scripts' digits are the same number drawn differently.
			'arabic-indic digits'  => array( '+٩٦٦ ٥٠ ١٢٣ ٤٥٦٧', '+966501234567' ),
			'persian digits'       => array( '+۹۸ ۹۱۲ ۳۴۵ ۶۷۸۹', '+989123456789' ),
			'fullwidth digits'     => array( '＋８１ ９０ １２３４ ５６７８', '+819012345678' ),
			'picker prefix, arabic number' => array( '+966 ٥٠١٢٣٤٥٦٧', '+966501234567' ),
			'empty stays empty'    => array( '   ', '' ),
			'no digits at all'     => array( 'call me', '' ),
		);
	}

	/**
	 * A national number is passed through as it was typed, minus separators.
	 *
	 * Send4 normalizes to E.164 and reports what it could not parse. Guessing a
	 * country code here would silently text the wrong person.
	 *
	 * @return void
	 */
	public function test_it_never_infers_a_country_code() {
		$this->assertSame( '060012345', Send4_Normalizer::clean_phone( '060012345' ) );
		$this->assertSame( '060012345', Send4_Normalizer::clean_phone( '0 600 12345' ) );
		$this->assertSame( '860012345', Send4_Normalizer::clean_phone( '8-600-12345' ) );

		$envelope = $this->envelopeFor( array( 'phone' => '060012345' ) );

		$this->assertSame( '060012345', $envelope['lead']['phone'] );
	}

	/**
	 * The picker shows the country code by putting it in the field (#0691). Left
	 * untouched, that is an empty field.
	 *
	 * @return void
	 */
	public function test_a_country_code_alone_is_an_empty_phone() {
		// What the picker prefills, posted because its script never ran.
		$this->assertSame( '', Send4_Normalizer::clean_phone( '+370 ' ) );
		$this->assertSame( '', Send4_Normalizer::clean_phone( '+1' ) );
		// Not a calling code, and not the prefill without its plus: left alone.
		$this->assertSame( '+37', Send4_Normalizer::clean_phone( '+37' ) );
		$this->assertSame( '370', Send4_Normalizer::clean_phone( '370' ) );
		$this->assertSame( '+3706', Send4_Normalizer::clean_phone( '+370 6' ) );
	}

	/**
	 * A phone longer than the API accepts is truncated rather than 422'd.
	 *
	 * @return void
	 */
	public function test_it_caps_the_phone_at_the_api_limit() {
		$this->assertSame( 32, strlen( Send4_Normalizer::clean_phone( '+' . str_repeat( '7', 60 ) ) ) );
	}

	/**
	 * Unmapped fields land in data, keyed by the slugified label.
	 *
	 * @return void
	 */
	public function test_unmapped_fields_land_in_data_keyed_by_slugified_label() {
		$envelope = $this->envelopeFor(
			array(
				'phone'    => '+37060012345',
				'field_17' => array(
					'label' => 'What do you need? (be specific)',
					'value' => 'A new roof',
				),
				'field_18' => array(
					'label' => 'Preferred  start   date',
					'value' => 'Next week',
				),
			)
		);

		$this->assertSame(
			array(
				'what_do_you_need_be_specific' => 'A new roof',
				'preferred_start_date'         => 'Next week',
			),
			$envelope['data']
		);
	}

	/**
	 * With no usable label the field id is the next best key, then the position.
	 *
	 * @return void
	 */
	public function test_it_falls_back_from_label_to_id_to_position() {
		$envelope = $this->envelopeFor(
			array(
				'phone'      => '+37060012345',
				'city_field' => array(
					'label' => '',
					'value' => 'Vilnius',
				),
				'???'        => array(
					'label' => '???',
					'value' => 'Something',
				),
			)
		);

		$this->assertSame(
			array(
				'city_field' => 'Vilnius',
				'field_3'    => 'Something',
			),
			$envelope['data']
		);
	}

	/**
	 * Two fields slugging to the same key do not overwrite each other.
	 *
	 * @return void
	 */
	public function test_it_disambiguates_colliding_keys() {
		$envelope = $this->envelopeFor(
			array(
				'phone' => '+37060012345',
				'a'     => array(
					'label' => 'City',
					'value' => 'Vilnius',
				),
				'b'     => array(
					'label' => 'City',
					'value' => 'Kaunas',
				),
			)
		);

		$this->assertSame(
			array(
				'city'   => 'Vilnius',
				'city_2' => 'Kaunas',
			),
			$envelope['data']
		);
	}

	/**
	 * Two identical labels long enough to hit the key cap still get two keys.
	 *
	 * The suffix used to be appended before the cap, which then cut it off
	 * again: both fields landed on the same 191 characters and the second
	 * answer silently replaced the first.
	 *
	 * @return void
	 */
	public function test_long_duplicate_labels_keep_both_answers() {
		$label = str_repeat( 'a', 200 );

		$envelope = $this->envelopeFor(
			array(
				'phone' => '+37060012345',
				'q1'    => array(
					'label' => $label,
					'value' => 'first',
				),
				'q2'    => array(
					'label' => $label,
					'value' => 'second',
				),
				'q3'    => array(
					'label' => $label,
					'value' => 'third',
				),
			)
		);

		$this->assertCount( 3, $envelope['data'] );
		$this->assertSame( array( 'first', 'second', 'third' ), array_values( $envelope['data'] ) );

		$keys = array_keys( $envelope['data'] );

		$this->assertSame( str_repeat( 'a', 191 ), $keys[0] );
		$this->assertSame( str_repeat( 'a', 189 ) . '_2', $keys[1] );
		$this->assertSame( str_repeat( 'a', 189 ) . '_3', $keys[2] );

		foreach ( $keys as $key ) {
			$this->assertLessThanOrEqual( Send4_Normalizer::MAX_IDENTIFIER, strlen( $key ) );
		}
	}

	/**
	 * A label in a script sanitize_title() cannot transliterate is named by the
	 * field id instead of by its percent-encoded bytes.
	 *
	 * "Имя" used to become `d0b8d0bcd18f`: meaningless in Send4's variable
	 * picker, and six bytes a letter towards the cap.
	 *
	 * @return void
	 */
	public function test_a_non_latin_label_falls_back_to_the_field_id() {
		$envelope = $this->envelopeFor(
			array(
				'phone'  => '+37060012345',
				'name'   => array(
					'label' => 'Имя',
					'value' => 'Иван',
				),
				'city'   => array(
					'label' => 'المدينة',
					'value' => 'الرياض',
				),
				'???'    => array(
					'label' => '您的留言',
					'value' => '你好',
				),
				// Accented Latin is transliterated, as it always was.
				'street' => array(
					'label' => 'Gatvė',
					'value' => 'Gedimino pr.',
				),
				// A numbered id, as WPForms and Gravity Forms use: a bare `7`
				// is no variable name.
				'7'      => array(
					'label' => 'Όνομα',
					'value' => 'Γιώργος',
				),
			)
		);

		$this->assertSame(
			array(
				'name'  => 'Иван',
				'city'  => 'الرياض',
				// No usable label and no usable id: the position.
				'field_4' => '你好',
				'gatve' => 'Gedimino pr.',
				'field_7' => 'Γιώργος',
			),
			$envelope['data']
		);
	}

	/**
	 * A multi-value field (a Contact Form 7 checkbox) becomes one string.
	 *
	 * @return void
	 */
	public function test_it_joins_array_values() {
		$envelope = $this->envelopeFor(
			array(
				'phone'  => '+37060012345',
				'topics' => array(
					'label' => 'Topics',
					'value' => array( 'roof', 'gutters', '', 'chimney' ),
				),
			)
		);

		$this->assertSame( 'roof, gutters, chimney', $envelope['data']['topics'] );
	}

	/**
	 * Blank answers are dropped rather than sent as empty strings.
	 *
	 * @return void
	 */
	public function test_it_drops_empty_values() {
		$envelope = $this->envelopeFor(
			array(
				'phone'   => '+37060012345',
				'city'    => array(
					'label' => 'City',
					'value' => '',
				),
				'note'    => array(
					'label' => 'Note',
					'value' => "   \n ",
				),
				'nothing' => array(
					'label' => 'Nothing',
					'value' => array(),
				),
				'message' => array(
					'label' => 'Message',
					'value' => 'Kept',
				),
			)
		);

		$this->assertSame( array( 'message' => 'Kept' ), $envelope['data'] );
	}

	/**
	 * An unmapped lead field is dropped when it is blank, and the lead only
	 * carries what was actually answered.
	 *
	 * @return void
	 */
	public function test_the_lead_only_carries_answered_fields() {
		$envelope = $this->envelopeFor(
			array(
				'phone' => '+37060012345',
				'name'  => '',
				'email' => 'j@x.lt',
			),
			array(
				'phone' => 'phone',
				'name'  => 'name',
				'email' => 'email',
			)
		);

		$this->assertSame(
			array(
				'phone' => '+37060012345',
				'email' => 'j@x.lt',
			),
			$envelope['lead']
		);
	}

	/**
	 * A field labelled "Form" must not clobber the envelope's own form block.
	 *
	 * @return void
	 */
	public function test_reserved_keys_are_renamed_rather_than_allowed_to_clobber() {
		$envelope = $this->envelopeFor(
			array(
				'phone' => '+37060012345',
				'f1'    => array(
					'label' => 'Source',
					'value' => 'Google',
				),
				'f2'    => array(
					'label' => 'Site URL',
					'value' => 'https://evil.example',
				),
				'f3'    => array(
					'label' => 'Form',
					'value' => 'Nope',
				),
				'f4'    => array(
					'label' => 'Lead',
					'value' => 'Also nope',
				),
			)
		);

		$this->assertSame(
			array(
				'field_source'   => 'Google',
				'field_site_url' => 'https://evil.example',
				'field_form'     => 'Nope',
				'field_lead'     => 'Also nope',
			),
			$envelope['data']
		);

		$this->assertSame( 'contact-form-7:1', $envelope['form']['key'] );
		$this->assertSame( 'http://example.org/', $envelope['site_url'] );
		$this->assertSame( array( 'phone' => '+37060012345' ), $envelope['lead'] );
	}

	/**
	 * Long identifiers are capped at what the API will accept.
	 *
	 * @return void
	 */
	public function test_it_caps_identifiers_at_the_api_limit() {
		$submission = new Send4_Submission(
			'contact-form-7:' . str_repeat( 'x', 300 ),
			str_repeat( 'n', 300 ),
			array( Send4_Submission::field( 'phone', 'Phone', '+37060012345' ) ),
			null
		);

		$envelope = Send4_Normalizer::build( $submission, array( 'phone' => 'phone' ), str_repeat( 'i', 300 ) );

		$this->assertSame( 191, strlen( $envelope['submission_id'] ) );
		$this->assertSame( 191, strlen( $envelope['form']['key'] ) );
		$this->assertSame( 191, strlen( $envelope['form']['name'] ) );
	}
}
