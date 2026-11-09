<?php
/**
 * The translation template points at files that exist.
 *
 * @package Send4
 */

/**
 * A translator opens a string's source reference to see where it is used.
 * `make pot` used to mount the repo at /app, and make-pot makes a path relative
 * with a plain str_replace of its source directory, so the "/app" inside
 * admin/views/partials/app-bar.php went too: 0.1.4 shipped a template that
 * filed all eight of that file's strings under a partials-bar.php that does not
 * exist. Nothing failed, which is why this does.
 *
 * Line numbers are left alone on purpose. They drift with every edit, and a
 * template that is a few lines out costs a translator a moment, not a wrong
 * file.
 */
class Send4_Pot_References_Test extends Send4_Test_Case {

	/**
	 * Every "#: file:line" names a file in the repo.
	 *
	 * @return void
	 */
	public function test_every_source_reference_names_a_file_that_exists() {
		$root = dirname( __DIR__, 2 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pot = (string) file_get_contents( $root . '/languages/send4.pot' );

		preg_match_all( '/^#: (.+)$/m', $pot, $matches );

		$files = array();

		foreach ( $matches[1] as $line ) {
			// gettext allows several references on one line, a space apart.
			foreach ( preg_split( '/\s+/', trim( $line ) ) as $reference ) {
				$files[ (string) preg_replace( '/:\d+$/', '', (string) $reference ) ] = true;
			}
		}

		$this->assertNotEmpty( $files, 'No source references found, so this test would prove nothing.' );

		$missing = array();

		foreach ( array_keys( $files ) as $file ) {
			if ( ! is_file( $root . '/' . $file ) ) {
				$missing[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'languages/send4.pot names files that are not in the repo. Run `make pot` and commit the result.'
		);
	}
}
