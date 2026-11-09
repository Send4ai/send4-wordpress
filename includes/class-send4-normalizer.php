<?php
/**
 * Turns a captured submission plus its field mapping into the Send4 envelope.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The envelope contract is defined by Send4's WordPress integration API.
 * Do not improvise fields here: anything Send4 does not validate is stripped
 * before it reaches the flow, and anything over the length limits 422s.
 */
class Send4_Normalizer {

	const MAX_IDENTIFIER = 191;
	const MAX_PHONE      = 32;
	const MAX_SITE_URL   = 2048;

	/**
	 * Keys the envelope itself owns. A form field slugging down to one of these
	 * would clobber the envelope once Send4 spreads `data` into the run vars.
	 *
	 * @var string[]
	 */
	private static $reserved = array( 'source', 'site_url', 'form', 'lead' );

	/**
	 * Build the envelope.
	 *
	 * @param Send4_Submission $submission    Captured submission.
	 * @param array            $mapping       phone/name/email field ids.
	 * @param string           $submission_id Idempotency key.
	 * @param string|null      $site_url      Site URL, defaults to home_url().
	 * @return array Envelope, safe to json_encode and to round-trip through the queue.
	 */
	public static function build( Send4_Submission $submission, array $mapping, $submission_id, $site_url = null ) {
		$mapping = wp_parse_args(
			$mapping,
			array(
				'phone' => '',
				'name'  => '',
				'email' => '',
			)
		);

		$mapped_ids = self::mapped_ids( $mapping );

		// The key each unmapped field lands under, worked out from the whole
		// field list rather than field by field, because de-duplication depends
		// on what came before. data_keys_for() is the ONLY place that decides
		// this, so a reported descriptor and a delivered payload cannot drift.
		$data_keys = self::data_keys_for( $submission->fields, $mapping );

		$lead = array();
		$data = array();

		foreach ( $submission->fields as $index => $field ) {
			$id    = isset( $field['id'] ) ? (string) $field['id'] : '';
			$value = self::flatten( isset( $field['value'] ) ? $field['value'] : '' );

			if ( '' !== $id && in_array( $id, $mapped_ids, true ) ) {
				if ( $id === $mapping['phone'] ) {
					$lead['phone'] = self::clean_phone( $value );
				}

				if ( $id === $mapping['name'] ) {
					$lead['name'] = self::cap( trim( $value ), self::MAX_IDENTIFIER );
				}

				if ( $id === $mapping['email'] ) {
					$lead['email'] = self::cap( trim( $value ), self::MAX_IDENTIFIER );
				}

				continue;
			}

			// An empty field is not sent, but it still consumed its key above —
			// otherwise a form whose first field happens to be blank would
			// renumber every duplicate after it, and the descriptor Send4 holds
			// would stop matching the payload.
			if ( '' === trim( $value ) || ! isset( $data_keys[ $index ] ) ) {
				continue;
			}

			$data[ $data_keys[ $index ] ] = $value;
		}

		$lead = array_filter(
			$lead,
			static function ( $value ) {
				return '' !== $value;
			}
		);

		$envelope = array(
			'submission_id' => self::cap( (string) $submission_id, self::MAX_IDENTIFIER ),
			'site_url'      => self::cap( null === $site_url ? home_url( '/' ) : (string) $site_url, self::MAX_SITE_URL ),
			'form'          => array(
				'key'  => self::cap( $submission->form_key, self::MAX_IDENTIFIER ),
				'name' => self::cap( $submission->form_name, self::MAX_IDENTIFIER ),
			),
			'lead'          => $lead,
			'data'          => $data,
		);

		/**
		 * Filter the envelope just before it is queued or sent.
		 *
		 * @param array            $envelope   The envelope.
		 * @param Send4_Submission $submission The captured submission.
		 * @param array            $mapping    The field mapping.
		 */
		return apply_filters( 'send4_envelope', $envelope, $submission, $mapping );
	}

	/**
	 * Strip the separators people type into phone fields.
	 *
	 * Deliberately no country-code inference: Send4 normalizes to E.164 and
	 * reports what it could not parse, which beats guessing wrong here.
	 *
	 * @param string $value Raw field value.
	 * @return string
	 */
	public static function clean_phone( $value ) {
		// Strip the invisible characters first. A phone pasted from a contact
		// card or a spreadsheet routinely arrives wrapped in a non-breaking
		// space or a directional mark, and trim() removes neither.
		$value = str_replace(
			array( "\xc2\xa0", "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\xaa", "\xe2\x80\xac", "\xe2\x80\x8e", "\xe2\x80\x8f" ),
			array( ' ', '-', '-', '', '', '', '' ),
			(string) $value
		);
		$value = trim( self::ascii_digits( $value ) );

		if ( '' === $value ) {
			return '';
		}

		// The plus counts wherever it sits ahead of the first digit, so
		// "(+370) 600 12345" and "tel: +370..." keep their country code. Testing
		// only the first character would quietly turn an international number
		// into a national one and leave Send4 guessing a country.
		$has_plus = 1 === preg_match( '/^[^0-9]*\+/', $value );
		$digits   = preg_replace( '/[^0-9]/', '', $value );

		if ( null === $digits || '' === $digits ) {
			return '';
		}

		// 00 is the international prefix almost everywhere, and the validator
		// already accepts "00370 600 12345" as international. Sent as typed it
		// reached Send4 as a 13-digit national number. Only when a real calling
		// code follows, so a national number that happens to start 00 is left
		// as typed — that is still not inferring a country, just reading one.
		if ( ! $has_plus && 0 === strpos( $digits, '00' ) && Send4_Phone_Validator::has_known_calling_code( substr( $digits, 2 ) ) ) {
			$has_plus = true;
			$digits   = substr( $digits, 2 );
		}

		// A country code and nothing after it is the picker's own prefill,
		// which a visitor who skipped the field never touched. The script blanks
		// it on submit; this is for the submit the script never saw. Without it
		// an optional phone left alone arrives as "+370" and is refused.
		if ( $has_plus && isset( Send4_Phone_Validator::calling_codes()[ $digits ] ) ) {
			return '';
		}

		return self::cap( ( $has_plus ? '+' : '' ) . $digits, self::MAX_PHONE );
	}

	/**
	 * Fold the digits and plus sign of other scripts into ASCII.
	 *
	 * A phone keyboard in Arabic, Persian or a CJK input method types its own
	 * digits, and a [^0-9] strip threw every one of them away: "+٩٦٦ ٥٠…" left
	 * only the picker's "966" prefix, which then read as an untouched field and
	 * the lead was recorded with no phone number and no error. These are the
	 * same numbers, only drawn differently, so they are translated rather than
	 * refused.
	 *
	 * @param string $value Phone as typed.
	 * @return string
	 */
	private static function ascii_digits( $value ) {
		static $map = null;

		if ( null === $map ) {
			$map = array(
				// Fullwidth plus, as a CJK input method types it.
				"\xef\xbc\x8b" => '+',
			);

			for ( $digit = 0; $digit <= 9; $digit++ ) {
				// Arabic-Indic U+0660–0669, Extended Arabic-Indic (Persian,
				// Urdu) U+06F0–06F9 and fullwidth U+FF10–FF19.
				$map[ self::utf8( 0x0660 + $digit ) ] = (string) $digit;
				$map[ self::utf8( 0x06F0 + $digit ) ] = (string) $digit;
				$map[ self::utf8( 0xFF10 + $digit ) ] = (string) $digit;
			}
		}

		return strtr( (string) $value, $map );
	}

	/**
	 * The UTF-8 bytes of a code point in the Basic Multilingual Plane.
	 *
	 * Written out rather than left to mb_chr(), which is not guaranteed to be
	 * there: mbstring is optional in PHP and WordPress only polyfills a few of
	 * its functions.
	 *
	 * @param int $code_point Code point, below U+10000.
	 * @return string
	 */
	private static function utf8( $code_point ) {
		if ( $code_point < 0x800 ) {
			return chr( 0xC0 | ( $code_point >> 6 ) ) . chr( 0x80 | ( $code_point & 0x3F ) );
		}

		return chr( 0xE0 | ( $code_point >> 12 ) ) . chr( 0x80 | ( ( $code_point >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code_point & 0x3F ) );
	}

	/**
	 * Reduce whatever the form plugin stored to a single string.
	 *
	 * Public because the phone validation bindings receive raw builder values —
	 * a WPForms composite, a Contact Form 7 tag that posts an array — and must
	 * judge exactly the string the envelope will carry, not their own reading
	 * of it (#0688).
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	public static function flatten( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();

			foreach ( $value as $item ) {
				$item = self::flatten( $item );

				if ( '' !== trim( $item ) ) {
					$parts[] = $item;
				}
			}

			return implode( ', ', $parts );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * The `data` key each unmapped field will land under, keyed by its position
	 * in the field list.
	 *
	 * Public because the form descriptors this plugin reports to Send4 must
	 * name the keys a submission will ACTUALLY carry (#0687). Slugging a label
	 * independently would be right most of the time and quietly wrong for a
	 * renamed collision or a duplicate label — and Send4 would then offer the
	 * customer a variable that is never populated, with nothing erroring
	 * anywhere. One function, two callers.
	 *
	 * Mapped fields are absent: they go to `lead.*`, not to the top level.
	 *
	 * @param array $fields  Field list, as Send4_Submission carries it.
	 * @param array $mapping phone/name/email field ids.
	 * @return array Position => data key.
	 */
	public static function data_keys_for( array $fields, array $mapping ) {
		$mapping = wp_parse_args(
			$mapping,
			array(
				'phone' => '',
				'name'  => '',
				'email' => '',
			)
		);

		$mapped_ids = self::mapped_ids( $mapping );
		$keys       = array();
		$taken      = array();

		foreach ( $fields as $index => $field ) {
			$id    = isset( $field['id'] ) ? (string) $field['id'] : '';
			$label = isset( $field['label'] ) ? (string) $field['label'] : '';

			if ( '' !== $id && in_array( $id, $mapped_ids, true ) ) {
				continue;
			}

			$key            = self::data_key( $label, $id, $index, $taken );
			$taken[ $key ]  = true;
			$keys[ $index ] = $key;
		}

		return $keys;
	}

	/**
	 * The field ids the mapping claims for the lead block.
	 *
	 * @param array $mapping phone/name/email field ids.
	 * @return string[]
	 */
	private static function mapped_ids( array $mapping ) {
		$ids = array();

		foreach ( array( 'phone', 'name', 'email' ) as $slot ) {
			// Field ids are compared against Send4_Submission's, which are
			// strings — a numeric id stored as an int would otherwise never
			// match and its value would leak into `data` instead of `lead`.
			$id = isset( $mapping[ $slot ] ) ? (string) $mapping[ $slot ] : '';

			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Pick the key an unmapped field lands under in `data`.
	 *
	 * @param string $label    Field label.
	 * @param string $id       Field id.
	 * @param int    $index    Position, used as a last resort.
	 * @param array  $existing Keys already taken.
	 * @return string
	 */
	private static function data_key( $label, $id, $index, array $existing ) {
		$key = self::slug( $label );

		if ( '' === $key ) {
			$key = self::slug( $id );

			// WPForms and Gravity Forms number their fields, so a form labelled
			// in Cyrillic or Arabic would otherwise declare variables called
			// `1`, `2`, `3`. A bare number is no name in a flow builder, and PHP
			// quietly turns such an array key into an integer.
			if ( '' !== $key && ctype_digit( $key ) ) {
				$key = 'field_' . $key;
			}
		}

		if ( '' === $key ) {
			$key = 'field_' . ( (int) $index + 1 );
		}

		if ( in_array( $key, self::$reserved, true ) ) {
			$key = 'field_' . $key;
		}

		// Uniqueness is judged on the FINAL, capped string. Capping after the
		// suffix was appended cut the suffix off again: two 200-character
		// labels both became the same 191 characters, and the second answer
		// overwrote the first. So the base is shortened to leave the suffix
		// room, and the loop compares exactly what will be returned.
		$candidate = self::cap( $key, self::MAX_IDENTIFIER );
		$suffix    = 2;

		while ( array_key_exists( $candidate, $existing ) ) {
			$tail      = '_' . $suffix;
			$candidate = self::cap( $key, self::MAX_IDENTIFIER - strlen( $tail ) ) . $tail;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Slugify a label into a flow-variable-friendly key.
	 *
	 * @param string $text Label or id.
	 * @return string
	 */
	private static function slug( $text ) {
		$slug = sanitize_title( (string) $text );

		// sanitize_title() transliterates accented Latin but percent-encodes
		// every other script, and the strip below then dropped only the "%":
		// "Имя" became `d0b8d0bcd18f`. That is no name a customer can recognise
		// in the variable picker, and at six bytes a letter a Cyrillic or CJK
		// label ran into the length cap far sooner. Such a label says nothing
		// usable in a key, so it counts as no label, and the field id (then the
		// position) names the field instead.
		if ( false !== strpos( $slug, '%' ) ) {
			return '';
		}

		$slug = str_replace( '-', '_', $slug );
		$slug = preg_replace( '/[^a-z0-9_]/', '', strtolower( $slug ) );
		$slug = preg_replace( '/_+/', '_', (string) $slug );

		return trim( (string) $slug, '_' );
	}

	/**
	 * Truncate to a byte length the API will accept.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function cap( $value, $length ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}
