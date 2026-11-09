<?php
/**
 * The two answers a validation binding needs from the plugin.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hands the four builder bindings everything that is not builder-specific
 * (#0688).
 *
 * A typed object rather than a closure, because a binding needs two answers
 * at two different moments: which field to look at before it has read any
 * value (so it can bail early, and find the right tag or field object), and
 * whether to refuse once it has the value.
 */
class Send4_Phone_Guard {

	/**
	 * The field id to check on this form, or '' to leave the submission alone.
	 *
	 * '' unless Send4_Settings::form_validates_phone(), so a form that is off,
	 * or has no phone mapping, is never validated — by construction, not by
	 * each binding remembering to ask. Keyed on the mapping, never on a field
	 * type: a customer who collects phone numbers in a plain text field is
	 * exactly who this is for.
	 *
	 * @param string $form_key Form key.
	 * @return string
	 */
	public function field_id( $form_key ) {
		if ( ! Send4_Settings::form_validates_phone( $form_key ) ) {
			return '';
		}

		return (string) Send4_Settings::form( $form_key )['mapping']['phone'];
	}

	/**
	 * What to tell the visitor, or '' when the number is acceptable.
	 *
	 * Every refusal is logged with the form, the reason code and the shape of
	 * what was typed — see shape() — and never the number: Send4_Logger
	 * promises no lead data. Deliberately not recorded as
	 * a delivery — that would show a form working exactly as configured as
	 * "Failed". It is counted as `refused` instead, which no delivery figure
	 * adds up, so the dashboard can say how many visitors the check turned
	 * away without calling any of them a failure.
	 *
	 * @param string $form_key Form key.
	 * @param mixed  $value    The raw field value, as the form plugin holds it.
	 * @return string Plain text; each form plugin escapes it on output.
	 */
	public function reject( $form_key, $value ) {
		$reason = Send4_Phone_Validator::check( $value );

		if ( Send4_Phone_Validator::OK === $reason ) {
			return '';
		}

		$shape = self::shape( $value );

		Send4_Logger::log(
			'info',
			sprintf(
				'Phone check refused %1$s: %2$s. The visitor was asked to fix the number.',
				$shape,
				self::reason_text( $reason )
			),
			array(
				'form'   => $form_key,
				'reason' => $reason,
				'shape'  => $shape,
			)
		);

		Send4_Stats::record( 'refused', (string) $form_key );

		return self::message( $reason, $form_key );
	}

	/**
	 * What a refused value looked like, without being the value.
	 *
	 * An admin watching refusals pile up needs to know what visitors are
	 * typing — "86…, nine digits" says they are writing the national format
	 * and the picker's default country is the fix — but the log promises no
	 * lead data, and a refused visitor never even became a lead. So: whether
	 * it began with + or 00, the first digits, and how many there were. The
	 * opening digits are a trunk prefix or a calling code, which name a
	 * country and not a person; everything after them is masked.
	 *
	 * @param mixed $value The raw field value, as the form plugin holds it.
	 * @return string E.g. "86••••••• (9 digits)" or "+999•••••• (9 digits)".
	 */
	public static function shape( $value ) {
		$phone  = Send4_Normalizer::clean_phone( Send4_Normalizer::flatten( $value ) );
		$prefix = '';

		if ( '' !== $phone && '+' === $phone[0] ) {
			$prefix = '+';
			$phone  = substr( $phone, 1 );
		}

		$length = strlen( $phone );

		// A calling code runs to three digits; a trunk prefix and the digit
		// after it are two. Never more than is left once four stay masked.
		$shown = '' !== $prefix || 0 === strpos( $phone, '00' ) ? 3 : 2;
		$shown = max( 0, min( $shown, $length - 4 ) );

		return sprintf(
			'%1$s%2$s%3$s (%4$d digits)',
			$prefix,
			substr( $phone, 0, $shown ),
			str_repeat( '•', $length - $shown ),
			$length
		);
	}

	/**
	 * A reason code in words, for the log line.
	 *
	 * English, like every other log line: the log is for whoever supports the
	 * site, and is what gets pasted into a support request.
	 *
	 * @param string $reason A Send4_Phone_Validator reason code.
	 * @return string
	 */
	private static function reason_text( $reason ) {
		switch ( $reason ) {
			case Send4_Phone_Validator::NO_COUNTRY_CODE:
				return 'no country code';
			case Send4_Phone_Validator::TOO_SHORT:
				return 'too short';
			case Send4_Phone_Validator::TOO_LONG:
				return 'too long';
			case Send4_Phone_Validator::UNKNOWN_COUNTRY_CODE:
				return 'unknown country code';
			default:
				return (string) $reason;
		}
	}

	/**
	 * The visitor-facing message.
	 *
	 * One string for every reason: a visitor does not act differently on "too
	 * short" and "unassigned country code", and four strings would quadruple
	 * what translators carry without changing the fix. No example number
	 * either — a +370 example is no help on a US site, and deriving one from
	 * the visitor would be country inference through the back door.
	 *
	 * Plain text, not escaped: every form plugin escapes on output, and
	 * escaping here would print &amp; on the page.
	 *
	 * @param string $reason   A Send4_Phone_Validator reason code, or `client`
	 *                         for the copy the browser shows.
	 * @param string $form_key Form key.
	 * @return string
	 */
	public static function message( $reason, $form_key ) {
		/**
		 * Filter what a visitor is told when their phone number is refused.
		 *
		 * @param string $message  The message, plain text.
		 * @param string $reason   Reason code: no_country_code, too_short,
		 *                         too_long, unknown_country_code, or client.
		 * @param string $form_key The form key.
		 */
		return (string) apply_filters(
			'send4_phone_error_message',
			__( 'Please enter your phone number with its country code, starting with + — otherwise we cannot text you back.', 'send4' ),
			$reason,
			$form_key
		);
	}
}
