<?php
/**
 * Asset URLs that survive a cache which ignores `?ver=`.
 *
 * @package Send4
 */

/**
 * Send4_Assets (#0692).
 */
class Send4_Assets_Test extends Send4_Test_Case {

	/**
	 * The version goes ahead of the extension, so the file keeps its type.
	 *
	 * @return void
	 */
	public function test_the_version_goes_into_the_file_name() {
		$this->assertSame( 'public/js/phone-field.0.1.2.js', Send4_Assets::versioned( 'public/js/phone-field.js', '0.1.2' ) );
		$this->assertSame( 'public/css/intl-tel-input-scoped.0.1.2.css', Send4_Assets::versioned( 'public/css/intl-tel-input-scoped.css', '0.1.2' ) );
	}

	/**
	 * A built plugin has the versioned copy and serves it; a checkout does not,
	 * and serves the file as it is.
	 *
	 * @return void
	 */
	public function test_the_versioned_copy_is_served_only_where_it_exists() {
		$dir = trailingslashit( get_temp_dir() ) . 'send4-assets-' . wp_generate_password( 8, false ) . '/';

		wp_mkdir_p( $dir . 'public/js' );

		$this->assertSame( 'public/js/phone-field.js', Send4_Assets::resolve( 'public/js/phone-field.js', $dir, '0.1.2' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . 'public/js/phone-field.0.1.2.js', '' );

		$this->assertSame( 'public/js/phone-field.0.1.2.js', Send4_Assets::resolve( 'public/js/phone-field.js', $dir, '0.1.2' ) );

		// Another release's copy is not this release's.
		$this->assertSame( 'public/js/phone-field.js', Send4_Assets::resolve( 'public/js/phone-field.js', $dir, '0.1.3' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $dir . 'public/js/phone-field.0.1.2.js' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir . 'public/js' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir . 'public' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
