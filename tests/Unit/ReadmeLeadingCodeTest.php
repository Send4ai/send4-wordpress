<?php
/**
 * No paragraph of readme.txt starts with inline code.
 *
 * @package Send4
 */

/**
 * WordPress.org reads a backtick at the start of a line as the start of a code
 * sample, whatever follows it. A paragraph that began with a file name in
 * backticks was drawn on the listing as a grey code box that scrolled
 * sideways, with the rest of the sentence under it, and nothing failed.
 *
 * The one line that may start with a backtick is the first line of a real code
 * sample: its closing backtick is on a later line.
 */
class Send4_Readme_Leading_Code_Test extends Send4_Test_Case {

	/**
	 * No line starts with a backtick and closes it on the same line.
	 *
	 * @return void
	 */
	public function test_no_paragraph_starts_with_inline_code() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lines = explode( "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ) );
		$found = array();

		foreach ( $lines as $index => $line ) {
			if ( 1 === preg_match( '/^[ \t]*`[^`]*`/', $line ) ) {
				$found[] = sprintf( 'line %d: %s', $index + 1, substr( trim( $line ), 0, 60 ) );
			}
		}

		$this->assertSame(
			array(),
			$found,
			'readme.txt: start each of these lines with a word. WordPress.org draws a line that starts with a backtick as a code sample.'
		);
	}
}
