<?php
/**
 * readme.txt keeps each paragraph and each list item on one line.
 *
 * @package Send4
 */

/**
 * WordPress.org turns every line break inside a paragraph into a <br>. The
 * readme used to be wrapped at about 115 characters like the code, and the
 * listing drew the description as ragged lines that stopped mid-sentence: 34
 * breaks in that one section. Nothing failed, which is why this does.
 *
 * The header block, headings, list items, blank lines and the code sample in
 * the FAQ are where a new line is meant.
 */
class Send4_Readme_Line_Breaks_Test extends Send4_Test_Case {

	/**
	 * No line continues the paragraph or list item on the line above it.
	 *
	 * @return void
	 */
	public function test_no_paragraph_or_list_item_is_wrapped() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lines = explode( "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ) );

		$wrapped = array();
		$header  = true;
		$in_code = false;

		foreach ( $lines as $index => $line ) {
			if ( $header ) {
				// Everything up to the first blank line is "Key: value" pairs.
				$header = '' !== trim( $line );
				continue;
			}

			$was_code = $in_code;

			// A code sample opens and closes with a lone backtick; a line with
			// a pair of them is an ordinary sentence naming a function.
			if ( 1 === substr_count( $line, '`' ) % 2 ) {
				$in_code = ! $in_code;
			}

			$previous = $lines[ $index - 1 ];

			if ( $was_code || '' === trim( $line ) || '' === trim( $previous ) ) {
				continue;
			}

			if ( $this->starts_a_block( $line ) || $this->is_heading( $previous ) ) {
				continue;
			}

			$wrapped[] = sprintf( 'line %d: %s', $index + 1, substr( trim( $line ), 0, 60 ) );
		}

		$this->assertSame(
			array(),
			$wrapped,
			'readme.txt: join each of these to the line above it. WordPress.org draws a line break as a <br>.'
		);
	}

	/**
	 * A heading, or the first line of a list item.
	 *
	 * @param string $line A line of the readme.
	 * @return bool
	 */
	private function starts_a_block( $line ) {
		return $this->is_heading( $line ) || 1 === preg_match( '/^\s*([*+-]|\d+\.)\s/', $line );
	}

	/**
	 * "== Section ==" or "= Question =".
	 *
	 * @param string $line A line of the readme.
	 * @return bool
	 */
	private function is_heading( $line ) {
		return 1 === preg_match( '/^=+ .* =+$/', trim( $line ) );
	}
}
