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

		$this->assertSame(
			array(),
			$this->wrapped( $lines ),
			'readme.txt: join each of these to the line above it. WordPress.org draws a line break as a <br>.'
		);
	}

	/**
	 * The check finds a wrap, and leaves a code sample alone.
	 *
	 * @return void
	 */
	public function test_the_check_finds_a_wrap_and_skips_a_code_sample() {
		$this->assertSame(
			array( 'line 4: carries on here.' ),
			$this->wrapped( array( 'Tags: sms', '', 'A sentence that', 'carries on here.' ) )
		);

		$this->assertSame(
			array(),
			$this->wrapped( array( 'Tags: sms', '', "`add_filter( 'a', function () {", '    return 1;', '} );`', '', 'One line.' ) )
		);
	}

	/**
	 * An odd backtick in the middle of a sentence does not open a code sample.
	 * Counting backticks alone took it for one, and every wrapped line after
	 * it passed.
	 *
	 * @return void
	 */
	public function test_a_stray_backtick_in_a_sentence_does_not_hide_a_wrap() {
		$this->assertSame(
			array( 'line 4: second line.' ),
			$this->wrapped( array( 'Tags: sms', '', 'Write it as `a ` b` on the', 'second line.' ) )
		);
	}

	/**
	 * The lines that continue a paragraph or a list item.
	 *
	 * @param string[] $lines The readme, a line each.
	 * @return string[] "line N: the start of it", one per wrapped line.
	 */
	private function wrapped( array $lines ) {
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
			$unpaired = 1 === substr_count( $line, '`' ) % 2;

			// A code sample opens on a line that starts with a backtick and has
			// no partner for it, and closes on a line that ends with one. A
			// sentence naming a function has its backticks in pairs.
			if ( ! $in_code && $unpaired && '`' === substr( ltrim( $line ), 0, 1 ) ) {
				$in_code = true;
			} elseif ( $in_code && $unpaired && '`' === substr( rtrim( $line ), -1 ) ) {
				$in_code = false;
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

		return $wrapped;
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
