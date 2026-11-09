<?php
/**
 * The server's floor for a textable phone number.
 *
 * @package Send4
 */

/**
 * Send4_Phone_Validator, which judges and never rewrites (#0688).
 */
class Send4_Phone_Validator_Test extends Send4_Test_Case {

	/**
	 * Numbers Send4 can text are accepted.
	 *
	 * @dataProvider acceptedProvider
	 *
	 * @param mixed $input What the visitor typed.
	 * @return void
	 */
	public function test_it_accepts( $input ) {
		$this->assertSame( Send4_Phone_Validator::OK, Send4_Phone_Validator::check( $input ) );
		$this->assertTrue( Send4_Phone_Validator::is_dialable( $input ) );
	}

	/**
	 * Accepted inputs.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function acceptedProvider() {
		return array(
			'international with spaces' => array( '+370 600 12345' ),
			// The picker's prefill, left untouched: an empty field, not a short
			// number. See Send4_Normalizer::clean_phone().
			'a country code alone'      => array( '+370 ' ),
			'NANP with brackets'        => array( '+1 (415) 555-0132' ),
			'00 counts as a plus'       => array( '00370 600 12345' ),
			// Typed on an Arabic, Persian or CJK keyboard: judged on the same
			// ASCII digits the envelope will carry.
			'Arabic-Indic digits'       => array( '+٩٦٦ ٥٠ ١٢٣ ٤٥٦٧' ),
			'fullwidth digits and plus' => array( '＋８１ ９０ １２３４ ５６７８' ),
			// Niue: three-digit code plus four digits is the shortest real case.
			'seven digits, the minimum' => array( '+683 4012' ),
			'fifteen digits, the E.164 cap' => array( '+' . str_repeat( '4', 15 ) ),
			// Global services are real and dialable. Refusing them would be a
			// false rejection, and a false rejection loses the lead.
			'international networks'    => array( '+882 16 12345' ),
			'Inmarsat'                  => array( '+870 773 123456' ),
			'Kosovo'                    => array( '+383 44 123 456' ),
			'Curaçao'                   => array( '+599 9 123 4567' ),
			// Empty means the form plugin's own required rule decides.
			'empty'                     => array( '' ),
			'no digits at all'          => array( 'call me' ),
			'a composite that is blank' => array( array( '', '  ' ) ),
		);
	}

	/**
	 * Anything else is refused with the reason, so the log can say why.
	 *
	 * @dataProvider rejectedProvider
	 *
	 * @param mixed  $input  What the visitor typed.
	 * @param string $reason Expected reason code.
	 * @return void
	 */
	public function test_it_rejects( $input, $reason ) {
		$this->assertSame( $reason, Send4_Phone_Validator::check( $input ) );
		$this->assertFalse( Send4_Phone_Validator::is_dialable( $input ) );
	}

	/**
	 * Rejected inputs and their reasons.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function rejectedProvider() {
		return array(
			'Lithuanian national'   => array( '060012345', Send4_Phone_Validator::NO_COUNTRY_CODE ),
			'trunk prefix 8'        => array( '8-600-12345', Send4_Phone_Validator::NO_COUNTRY_CODE ),
			'US national'           => array( '(415) 555-0132', Send4_Phone_Validator::NO_COUNTRY_CODE ),
			'too short'             => array( '+370 600', Send4_Phone_Validator::TOO_SHORT ),
			// Stripping these digits used to leave only the picker's "+966",
			// which read as an untouched field: passed, then sent with no phone.
			'short, in Arabic-Indic digits' => array( '+966 ٥٠', Send4_Phone_Validator::TOO_SHORT ),
			'national, in Persian digits'   => array( '۰۹۱۲ ۳۴۵ ۶۷۸۹', Send4_Phone_Validator::NO_COUNTRY_CODE ),
			// Sixteen digits, kept well under MAX_PHONE so clean_phone() cannot
			// truncate it back into range and make this test meaningless.
			'sixteen digits'        => array( '+' . str_repeat( '4', 16 ), Send4_Phone_Validator::TOO_LONG ),
			'unassigned code'       => array( '+999 123 4567', Send4_Phone_Validator::UNKNOWN_COUNTRY_CODE ),
			'zero is not a code'    => array( '+0 1234567', Send4_Phone_Validator::UNKNOWN_COUNTRY_CODE ),
			'a composite national'  => array( array( '060012345' ), Send4_Phone_Validator::NO_COUNTRY_CODE ),
		);
	}

	/**
	 * The invariant, both halves at once: a national number is refused, and
	 * the string the envelope would carry is still exactly what was typed.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_national_number_without_completing_it() {
		$this->assertSame( Send4_Phone_Validator::NO_COUNTRY_CODE, Send4_Phone_Validator::check( '060012345' ) );
		$this->assertSame( '060012345', Send4_Normalizer::clean_phone( '060012345' ) );
	}

	/**
	 * An optional phone field left blank must still submit.
	 *
	 * @return void
	 */
	public function test_an_empty_value_is_not_this_validator_s_business() {
		foreach ( array( '', '   ', "\xc2\xa0", null, array() ) as $blank ) {
			$this->assertTrue( Send4_Phone_Validator::is_dialable( $blank ) );
		}
	}

	/**
	 * A site can teach the table a code this release does not know yet.
	 *
	 * @return void
	 */
	public function test_the_calling_code_table_is_filterable() {
		$this->assertSame( Send4_Phone_Validator::UNKNOWN_COUNTRY_CODE, Send4_Phone_Validator::check( '+999 123 4567' ) );

		$add = static function ( $codes ) {
			$codes['999'] = true;

			return $codes;
		};

		add_filter( 'send4_phone_calling_codes', $add );

		$this->assertSame( Send4_Phone_Validator::OK, Send4_Phone_Validator::check( '+999 123 4567' ) );

		remove_filter( 'send4_phone_calling_codes', $add );
	}

	/**
	 * Client-accepts ⊂ server-accepts: every dial code the bundled picker can
	 * select is one this validator knows, so upgrading intl-tel-input cannot
	 * quietly produce numbers the server refuses.
	 *
	 * @return void
	 */
	public function test_every_dial_code_the_picker_offers_is_accepted() {
		$library = dirname( __DIR__, 2 ) . '/public/lib/intl-tel-input/js/intlTelInput.js';

		$this->assertFileExists( $library );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = (string) file_get_contents( $library );

		$this->assertSame( 1, preg_match( '/var rawCountryData = \[(.*?)\n  \];/s', $source, $block ), 'The country data block in intl-tel-input moved.' );

		preg_match_all( '/\[\s*"[a-z]{2}",\s*(?:\/\/[^\n]*\s*)?"(\d+)"/', $block[1], $matches );

		$dial_codes = array_unique( $matches[1] );

		$this->assertGreaterThan( 200, count( $dial_codes ), 'Parsed too few dial codes out of intl-tel-input.' );

		$known = Send4_Phone_Validator::calling_codes();

		foreach ( $dial_codes as $code ) {
			$this->assertArrayHasKey( $code, $known, "The picker can select +{$code}, which the server would refuse." );
		}
	}
}
