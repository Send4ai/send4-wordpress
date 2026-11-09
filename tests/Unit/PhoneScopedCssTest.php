<?php
/**
 * The picker's stylesheet, limited to our own picker.
 *
 * @package Send4
 */

/**
 * public/css/intl-tel-input-scoped.css is generated from the vendored
 * stylesheet. Loading the vendored one as published restyled every other copy
 * of intl-tel-input on the page — a client's theme ran 18.x on another form,
 * and got our sprite behind its flag offsets (#0692).
 */
class Send4_Phone_Scoped_Css_Test extends Send4_Test_Case {

	/**
	 * The generated stylesheet.
	 *
	 * @return string
	 */
	private function css() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/public/css/intl-tel-input-scoped.css' );
	}

	/**
	 * The committed file is what the generator would write today.
	 *
	 * @return void
	 */
	public function test_the_committed_sheet_matches_the_vendored_stylesheet() {
		$root = dirname( __DIR__, 2 );

		if ( ! defined( 'SEND4_SCOPED_CSS_AS_LIBRARY' ) ) {
			define( 'SEND4_SCOPED_CSS_AS_LIBRARY', true );
		}

		require_once $root . '/bin/build-scoped-picker-css.php';

		$this->assertSame(
			send4_build_scoped_picker_css( $root ),
			$this->css(),
			'Run `php bin/build-scoped-picker-css.php` and commit the result.'
		);
	}

	/**
	 * No rule can reach an element outside our picker: every top-level
	 * selector carries the scope, and nothing is left on :root.
	 *
	 * @return void
	 */
	public function test_every_rule_is_limited_to_our_picker() {
		$unscoped = array();

		foreach ( explode( "\n", $this->css() ) as $line ) {
			if ( '' === $line || '}' === $line || 0 === strpos( $line, '/*' ) || '@' === $line[0] ) {
				continue;
			}

			$prelude = substr( $line, 0, (int) strpos( $line, '{' ) );

			foreach ( explode( ',.iti', $prelude ) as $selector ) {
				if ( false === strpos( $selector, '.send4-iti' ) ) {
					$unscoped[] = $selector;
				}
			}
		}

		$this->assertSame( array(), $unscoped );
		$this->assertStringNotContainsString( ':root', $this->css() );
	}

	/**
	 * The scope adds one class of specificity and no more, so the overrides in
	 * phone-field.css keep winning. The library's measuring box is matched
	 * through :where() for exactly that reason.
	 *
	 * @return void
	 */
	public function test_the_measuring_box_adds_no_specificity() {
		$this->assertStringContainsString(
			'.iti__country-list:is(.send4-iti,.send4-iti *,:where(body>.iti.iti--inline-country-selector,body>.iti.iti--inline-country-selector *))',
			$this->css()
		);
	}

	/**
	 * The sprites are addressed from public/css/, where this file lives.
	 *
	 * @return void
	 */
	public function test_the_sprites_resolve_from_the_generated_file() {
		$root = dirname( __DIR__, 2 );

		preg_match_all( '/url\(([^)]+)\)/', $this->css(), $urls );

		$this->assertCount( 2, $urls[1] );

		foreach ( $urls[1] as $url ) {
			$this->assertFileExists( $root . '/public/css/' . $url );
		}
	}

	/**
	 * The picker loads the scoped sheet, never the vendored one.
	 *
	 * @return void
	 */
	public function test_the_scoped_sheet_is_the_one_registered() {
		Send4_Phone_Field::register_assets();

		$src = wp_styles()->registered[ Send4_Phone_Field::LIBRARY_HANDLE ]->src;

		$this->assertStringEndsWith( 'public/css/intl-tel-input-scoped.css', $src );
	}
}
