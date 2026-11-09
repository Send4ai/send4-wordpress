<?php
/**
 * The picker's flags, pinned under our own class.
 *
 * @package Send4
 */

/**
 * public/css/phone-flags.css is generated from the vendored stylesheet, so an
 * upgrade of intl-tel-input that reorders the sprite must regenerate it. This
 * fails until someone does.
 */
class Send4_Phone_Flag_Css_Test extends Send4_Test_Case {

	/**
	 * The committed file is what the generator would write today.
	 *
	 * @return void
	 */
	public function test_the_committed_table_matches_the_vendored_stylesheet() {
		$root = dirname( __DIR__, 2 );

		if ( ! defined( 'SEND4_FLAG_CSS_AS_LIBRARY' ) ) {
			define( 'SEND4_FLAG_CSS_AS_LIBRARY', true );
		}

		require_once $root . '/bin/build-flag-css.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$committed = (string) file_get_contents( $root . '/public/css/phone-flags.css' );

		$this->assertSame(
			send4_build_flag_css( $root ),
			$committed,
			'Run `php bin/build-flag-css.php` and commit the result.'
		);
	}

	/**
	 * Lithuania is Lithuania: the 130th flag in the 29.x sprite, addressed
	 * through variables no other copy of the picker writes.
	 *
	 * @return void
	 */
	public function test_a_flag_is_addressed_without_upstream_variables() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/public/css/phone-flags.css' );

		$this->assertStringContainsString( '.send4-iti .iti__lt{--send4-flag-i:129}', $css );
		$this->assertStringNotContainsString( '--iti-', $css );
	}

	/**
	 * The stylesheet is a dependency of the field's own, so it loads whenever
	 * the picker does.
	 *
	 * @return void
	 */
	public function test_the_table_loads_with_the_picker() {
		Send4_Phone_Field::register_assets();

		$this->assertContains( Send4_Phone_Field::FLAGS_HANDLE, wp_styles()->registered[ Send4_Phone_Field::HANDLE ]->deps );
	}
}
