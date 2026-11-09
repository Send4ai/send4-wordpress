<?php
/**
 * Is this phone number something Send4 can text?
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The server half of phone validation (#0688): a floor, not a full parser.
 *
 * Pure rules — no options, no hooks beyond one filter on the calling-code
 * table — so it tests without a form plugin in sight.
 *
 * ⚠️ The client/server contract. The picker in public/js/phone-field.js
 * guarantees E.164 when it runs. This class is the source of truth and
 * enforces a floor the picker can never fall below: everything the client
 * accepts, this accepts, and this accepts some things the client refuses
 * (+15555555555 is well-formed but unassigned). They can never disagree in the
 * direction that lets a bad number through, which is why a visitor with
 * JavaScript off still cannot submit a national number.
 *
 * ⚠️ This never produces a phone number. check() answers with a reason code
 * and nothing else, so it structurally cannot rewrite what the visitor typed.
 * It judges the string Send4_Normalizer::clean_phone() makes — the one the
 * envelope carries — and clean_phone() still infers no country code: a number
 * without one is refused here, never completed.
 *
 * Deliberately NOT checked, because each is a false-rejection generator and a
 * false rejection loses a lead outright:
 *
 * - Per-country national number lengths. The biggest source of wrongly
 *   refused numbers, and the reason libphonenumber ships new metadata every
 *   fortnight. Send4's server parses properly; this only has to be a floor.
 * - Trunk prefixes, mobile versus landline, extensions, vanity numbers.
 */
class Send4_Phone_Validator {

	const OK                   = '';
	const NO_COUNTRY_CODE      = 'no_country_code';
	const TOO_SHORT            = 'too_short';
	const TOO_LONG             = 'too_long';
	const UNKNOWN_COUNTRY_CODE = 'unknown_country_code';

	/**
	 * Fewest digits, calling code included. +683 4012 (Niue) is a real number.
	 */
	const MIN_DIGITS = 7;

	/**
	 * Most digits, calling code included: the E.164 hard cap. Not MAX_PHONE,
	 * which is a transport limit rather than a rule about numbers.
	 */
	const MAX_DIGITS = 15;

	/**
	 * Assigned E.164 country calling codes, keyed by code so lookup is isset().
	 *
	 * Source: ITU-T, "List of ITU-T Recommendation E.164 assigned country codes"
	 * (Annex to ITU Operational Bulletin), including the codes for global
	 * services — freephone (800), shared cost (808), Inmarsat (870), UPT (878),
	 * GMSS satellite (881), international networks (882, 883), premium rate
	 * (979) and the ITPCS trial (991). Those are real, dialable numbers, and
	 * refusing a +882 number would be a false rejection; over-accepting is the
	 * safe direction for a floor. 379 (Vatican, reserved) is in for the same
	 * reason. Both halves of the old Netherlands Antilles live under 599, and
	 * Kosovo under 383.
	 *
	 * tests/Unit/PhoneValidatorTest.php checks this is a superset of every dial
	 * code the bundled picker can produce, so the contract above cannot drift
	 * when intl-tel-input is upgraded.
	 *
	 * PHP turns the numeric-string keys into integers; isset() finds them either
	 * way.
	 *
	 * @var array<int|string,true>
	 */
	private static $calling_codes = array(
		'1'   => true,
		'7'   => true,
		'20'  => true,
		'27'  => true,
		'30'  => true,
		'31'  => true,
		'32'  => true,
		'33'  => true,
		'34'  => true,
		'36'  => true,
		'39'  => true,
		'40'  => true,
		'41'  => true,
		'43'  => true,
		'44'  => true,
		'45'  => true,
		'46'  => true,
		'47'  => true,
		'48'  => true,
		'49'  => true,
		'51'  => true,
		'52'  => true,
		'53'  => true,
		'54'  => true,
		'55'  => true,
		'56'  => true,
		'57'  => true,
		'58'  => true,
		'60'  => true,
		'61'  => true,
		'62'  => true,
		'63'  => true,
		'64'  => true,
		'65'  => true,
		'66'  => true,
		'81'  => true,
		'82'  => true,
		'84'  => true,
		'86'  => true,
		'90'  => true,
		'91'  => true,
		'92'  => true,
		'93'  => true,
		'94'  => true,
		'95'  => true,
		'98'  => true,
		'211' => true,
		'212' => true,
		'213' => true,
		'216' => true,
		'218' => true,
		'220' => true,
		'221' => true,
		'222' => true,
		'223' => true,
		'224' => true,
		'225' => true,
		'226' => true,
		'227' => true,
		'228' => true,
		'229' => true,
		'230' => true,
		'231' => true,
		'232' => true,
		'233' => true,
		'234' => true,
		'235' => true,
		'236' => true,
		'237' => true,
		'238' => true,
		'239' => true,
		'240' => true,
		'241' => true,
		'242' => true,
		'243' => true,
		'244' => true,
		'245' => true,
		'246' => true,
		'247' => true,
		'248' => true,
		'249' => true,
		'250' => true,
		'251' => true,
		'252' => true,
		'253' => true,
		'254' => true,
		'255' => true,
		'256' => true,
		'257' => true,
		'258' => true,
		'260' => true,
		'261' => true,
		'262' => true,
		'263' => true,
		'264' => true,
		'265' => true,
		'266' => true,
		'267' => true,
		'268' => true,
		'269' => true,
		'290' => true,
		'291' => true,
		'297' => true,
		'298' => true,
		'299' => true,
		'350' => true,
		'351' => true,
		'352' => true,
		'353' => true,
		'354' => true,
		'355' => true,
		'356' => true,
		'357' => true,
		'358' => true,
		'359' => true,
		'370' => true,
		'371' => true,
		'372' => true,
		'373' => true,
		'374' => true,
		'375' => true,
		'376' => true,
		'377' => true,
		'378' => true,
		'379' => true,
		'380' => true,
		'381' => true,
		'382' => true,
		'383' => true,
		'385' => true,
		'386' => true,
		'387' => true,
		'389' => true,
		'420' => true,
		'421' => true,
		'423' => true,
		'500' => true,
		'501' => true,
		'502' => true,
		'503' => true,
		'504' => true,
		'505' => true,
		'506' => true,
		'507' => true,
		'508' => true,
		'509' => true,
		'590' => true,
		'591' => true,
		'592' => true,
		'593' => true,
		'594' => true,
		'595' => true,
		'596' => true,
		'597' => true,
		'598' => true,
		'599' => true,
		'670' => true,
		'672' => true,
		'673' => true,
		'674' => true,
		'675' => true,
		'676' => true,
		'677' => true,
		'678' => true,
		'679' => true,
		'680' => true,
		'681' => true,
		'682' => true,
		'683' => true,
		'685' => true,
		'686' => true,
		'687' => true,
		'688' => true,
		'689' => true,
		'690' => true,
		'691' => true,
		'692' => true,
		'800' => true,
		'808' => true,
		'850' => true,
		'852' => true,
		'853' => true,
		'855' => true,
		'856' => true,
		'870' => true,
		'878' => true,
		'880' => true,
		'881' => true,
		'882' => true,
		'883' => true,
		'886' => true,
		'888' => true,
		'960' => true,
		'961' => true,
		'962' => true,
		'963' => true,
		'964' => true,
		'965' => true,
		'966' => true,
		'967' => true,
		'968' => true,
		'970' => true,
		'971' => true,
		'972' => true,
		'973' => true,
		'974' => true,
		'975' => true,
		'976' => true,
		'977' => true,
		'979' => true,
		'991' => true,
		'992' => true,
		'993' => true,
		'994' => true,
		'995' => true,
		'996' => true,
		'998' => true,
	);

	/**
	 * Why a value is not a number Send4 can text, or '' when it is.
	 *
	 * @param mixed $value Raw field value, as the form plugin holds it.
	 * @return string One of the class constants; self::OK when acceptable.
	 */
	public static function check( $value ) {
		$phone = Send4_Normalizer::clean_phone( Send4_Normalizer::flatten( $value ) );

		// An empty optional field is not this validator's business. The form
		// plugin's own "required" rule decides that, and Send4_Delivery already
		// records a submission that arrives without a phone. Refusing blanks
		// here would quietly make every optional phone field required.
		if ( '' === $phone ) {
			return self::OK;
		}

		// clean_phone() already turned 00 followed by a real calling code into
		// a plus, and folded Arabic-Indic and fullwidth digits into ASCII, so
		// this judges exactly what the envelope will carry. A 00 still here is
		// followed by no known code, which the table check below refuses.
		if ( '+' === $phone[0] ) {
			$digits = substr( $phone, 1 );
		} elseif ( 0 === strpos( $phone, '00' ) ) {
			$digits = substr( $phone, 2 );
		} else {
			return self::NO_COUNTRY_CODE;
		}

		$length = strlen( $digits );

		if ( $length < self::MIN_DIGITS ) {
			return self::TOO_SHORT;
		}

		if ( $length > self::MAX_DIGITS ) {
			return self::TOO_LONG;
		}

		if ( ! self::has_known_calling_code( $digits ) ) {
			return self::UNKNOWN_COUNTRY_CODE;
		}

		return self::OK;
	}

	/**
	 * Could Send4 text this?
	 *
	 * @param mixed $value Raw field value.
	 * @return bool
	 */
	public static function is_dialable( $value ) {
		return self::OK === self::check( $value );
	}

	/**
	 * Every calling code this validator accepts.
	 *
	 * @return array<int|string,true>
	 */
	public static function calling_codes() {
		/**
		 * Filter the table of accepted country calling codes.
		 *
		 * For the day ITU assigns a code before this plugin ships it. Keys are
		 * the codes as digit strings; values are ignored.
		 *
		 * @param array<int|string,true> $codes Calling codes.
		 */
		$codes = apply_filters( 'send4_phone_calling_codes', self::$calling_codes );

		// A filter that forgets to return would otherwise leave no table, and
		// no table refuses every number on every form that checks.
		return is_array( $codes ) ? $codes : self::$calling_codes;
	}

	/**
	 * Longest-prefix match of the leading digits against the table.
	 *
	 * Public for Send4_Normalizer::clean_phone(), which reads a leading 00 as
	 * the international prefix only when a real calling code follows it.
	 *
	 * @param string $digits Digits after the international prefix.
	 * @return bool
	 */
	public static function has_known_calling_code( $digits ) {
		$codes = self::calling_codes();

		foreach ( array( 3, 2, 1 ) as $length ) {
			if ( isset( $codes[ substr( $digits, 0, $length ) ] ) ) {
				return true;
			}
		}

		return false;
	}
}
