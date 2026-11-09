<?php
/**
 * Where the phone picker looks for each form plugin's phone input.
 *
 * @package Send4
 */

/**
 * Turns "I believe Gravity Forms names its inputs input_5" into a checked
 * assertion (#0688). Contact Form 7 and WPForms are real, so their selectors
 * run against the markup they actually render; Gravity Forms and Elementor Pro
 * are paid, so theirs run against committed renders in tests/fixtures.
 *
 * The selectors are evaluated with DOMXPath through a translator that only
 * understands the grammar Send4_Phone_Field emits — a list of compounds, each
 * a tag, one class and one double-quoted attribute test (= or ^=) — so a
 * selector that grows beyond it fails loudly here rather than matching
 * something by accident.
 */
class Send4_Phone_Field_Selectors_Test extends Send4_Test_Case {

	/**
	 * Every country the admin can save is one the picker can select, and the
	 * other way round.
	 *
	 * @return void
	 */
	public function test_the_country_list_matches_the_picker() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/public/lib/intl-tel-input/js/intlTelInput.js' );

		$this->assertSame( 1, preg_match( '/var rawCountryData = \[(.*?)\n  \];/s', $source, $block ) );

		preg_match_all( '/\[\s*"([a-z]{2})",/', $block[1], $matches );

		$picker = $matches[1];
		$ours   = Send4_Phone_Field::countries();

		sort( $picker );
		sort( $ours );

		$this->assertSame( $picker, $ours );
	}

	/**
	 * Only the four adapters this plugin ships have selectors, and a form with
	 * no phone mapping has none.
	 *
	 * @return void
	 */
	public function test_there_is_no_selector_without_a_known_adapter_and_a_field() {
		$this->assertNull( Send4_Phone_Field::selectors_for( 'ninja-forms', '3', 'phone' ) );
		$this->assertNull( Send4_Phone_Field::selectors_for( 'contact-form-7', '3', '' ) );
	}

	/**
	 * A customer-authored field id with a quote or a backslash in it cannot
	 * end the selector string early, and still matches its input.
	 *
	 * @return void
	 */
	public function test_quotes_and_backslashes_in_a_field_id_are_escaped() {
		foreach ( array( 'your"phone', 'your\\phone', "a\\\"b" ) as $field_id ) {
			$selectors = Send4_Phone_Field::selectors_for( 'contact-form-7', '5', $field_id );

			$html = '<div class="wpcf7" data-wpcf7-id="5"><form><input name="' . esc_attr( $field_id ) . '"><input name="decoy"></form></div>';

			$this->assertCount( 1, $this->match( $html, $selectors ), 'Field id ' . $field_id );
		}
	}

	/**
	 * Contact Form 7, as it really renders, twice on one page.
	 *
	 * @return void
	 */
	public function test_contact_form_7_selectors_match_its_own_markup() {
		$form  = Send4_Cf7_Factory::create();
		$other = Send4_Cf7_Factory::create( 'Another form' );

		$html = $form->form_html() . $form->form_html() . $other->form_html();

		$selectors = Send4_Phone_Field::selectors_for( 'contact-form-7', (string) $form->id(), 'your-phone' );
		$found     = $this->match( $html, $selectors );

		$this->assertCount( 2, $found, 'Both copies of the form, and not the other form.' );
		$this->assertSame( 'tel', $found[0]->getAttribute( 'type' ) );
	}

	/**
	 * Contact Form 7 5.x: the wrapper has no data-wpcf7-id, only its unit tag.
	 * Form 42's picker lands on both of form 42's copies and never on form 420,
	 * whose unit tag starts with the same characters (#0697).
	 *
	 * @return void
	 */
	public function test_contact_form_7_5x_markup_is_matched_by_its_unit_tag() {
		$copy = static function ( $unit_tag ) {
			return '<div class="wpcf7 no-js" id="' . $unit_tag . '" lang="en-US" dir="ltr"><form class="wpcf7-form">'
				. '<input type="tel" name="your-phone"></form></div>';
		};

		$html = $copy( 'wpcf7-f42-p10-o1' ) . $copy( 'wpcf7-f42-o2' ) . $copy( 'wpcf7-f420-p10-o3' );

		$found = $this->match( $html, Send4_Phone_Field::selectors_for( 'contact-form-7', '42', 'your-phone' ) );

		$this->assertCount( 2, $found, 'Both copies of form 42, and not form 420.' );
	}

	/**
	 * WPForms Lite, as it really renders.
	 *
	 * @return void
	 */
	public function test_wpforms_selectors_match_its_own_markup() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
				'post_title'  => 'Roof quote',
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wpforms_encode(
					array(
						'id'       => (string) $post_id,
						'field_id' => 3,
						'fields'   => array(
							'1' => array(
								'id'    => '1',
								'type'  => 'text',
								'label' => 'Phone',
							),
							'2' => array(
								'id'    => '2',
								'type'  => 'text',
								'label' => 'City',
							),
						),
						'settings' => array(
							'form_title'  => 'Roof quote',
							'submit_text' => 'Send',
						),
					)
				),
			)
		);

		ob_start();
		wpforms_display( $post_id );
		$html = (string) ob_get_clean();

		$this->assertNotSame( '', $html, 'WPForms rendered nothing.' );

		$found = $this->match( $html, Send4_Phone_Field::selectors_for( 'wpforms', (string) $post_id, '1' ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 'wpforms-' . $post_id . '-field_1', $found[0]->getAttribute( 'id' ) );
	}

	/**
	 * Gravity Forms: both copies of form 7's phone, never form 17's, and never
	 * a sub-input.
	 *
	 * @return void
	 */
	public function test_gravity_forms_selectors_match_the_committed_render() {
		$html  = $this->fixtureHtml( 'gravityforms/rendered.html' );
		$found = $this->match( $html, Send4_Phone_Field::selectors_for( 'gravityforms', '7', '2' ) );

		$this->assertCount( 2, $found );

		foreach ( $found as $input ) {
			$this->assertSame( 'input_7_2', $input->getAttribute( 'id' ) );
		}

		$this->assertCount( 0, $this->match( $html, Send4_Phone_Field::selectors_for( 'gravityforms', '7', '1' ) ), 'A composite field mapped as the phone gets no picker.' );
	}

	/**
	 * Elementor Pro: both copies of the widget's phone, never another widget's.
	 *
	 * @return void
	 */
	public function test_elementor_selectors_match_the_committed_render() {
		$html  = $this->fixtureHtml( 'elementor/rendered.html' );
		$found = $this->match( $html, Send4_Phone_Field::selectors_for( 'elementor', 'a1b2c3d', 'phone' ) );

		$this->assertCount( 2, $found );

		foreach ( $found as $input ) {
			$this->assertSame( 'form_fields[phone]', $input->getAttribute( 'name' ) );
		}
	}

	/**
	 * Read an HTML fixture.
	 *
	 * @param string $path Below tests/fixtures.
	 * @return string
	 */
	private function fixtureHtml( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $path );
	}

	/**
	 * Every input the scope and input selectors find, the way the controller
	 * resolves them: each scope, then inputs inside it.
	 *
	 * @param string $html      Markup.
	 * @param array  $selectors array{scope:string,input:string}.
	 * @return DOMElement[]
	 */
	private function match( $html, array $selectors ) {
		$document = new DOMDocument();

		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		$xpath = new DOMXPath( $document );
		$found = array();

		// A selector list, like querySelectorAll(), yields each element once:
		// XPath's union does the same.
		$scopes = array();

		foreach ( explode( ', ', $selectors['scope'] ) as $compound ) {
			$scopes[] = '//' . $this->toXpath( $compound );
		}

		foreach ( $xpath->query( implode( ' | ', $scopes ) ) as $scope ) {
			foreach ( $xpath->query( './/' . $this->toXpath( $selectors['input'] ), $scope ) as $input ) {
				$found[] = $input;
			}
		}

		return $found;
	}

	/**
	 * Translate one compound selector of the shape tag.class[attr="value"] or
	 * tag.class[attr^="prefix"].
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath step.
	 */
	private function toXpath( $selector ) {
		$matched = preg_match( '/^([a-z]*)(?:\.([a-z0-9_-]+))?\[([a-z0-9_-]+)(\^?)="((?:[^"\\\\]|\\\\.)*)"\]$/s', $selector, $parts );

		$this->assertSame( 1, $matched, 'Selector outside the grammar this test understands: ' . $selector );

		$step = '' === $parts[1] ? '*' : $parts[1];

		if ( '' !== $parts[2] ) {
			$step .= "[contains(concat(' ', normalize-space(@class), ' '), ' " . $parts[2] . " ')]";
		}

		$literal = $this->xpathLiteral( $this->unescapeCss( $parts[5] ) );

		if ( '^' === $parts[4] ) {
			return $step . '[starts-with(@' . $parts[3] . ', ' . $literal . ')]';
		}

		return $step . '[@' . $parts[3] . '=' . $literal . ']';
	}

	/**
	 * Undo CSS string escapes: a backslash before any character, or a hex
	 * escape with its optional trailing space.
	 *
	 * @param string $value Escaped value.
	 * @return string
	 */
	private function unescapeCss( $value ) {
		return (string) preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6}) ?|(.))/s',
			static function ( $escape ) {
				return '' !== $escape[1] ? mb_chr( hexdec( $escape[1] ), 'UTF-8' ) : $escape[2];
			},
			$value
		);
	}

	/**
	 * An XPath string literal for any value, quotes of both kinds included.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function xpathLiteral( $value ) {
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}

		return "concat('" . str_replace( "'", "', \"'\", '", $value ) . "')";
	}
}
