<?php
/**
 * Generates the idempotency key Send4 dedupes on for 24 hours.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * {form_key}:{entry}:{suffix}, at most 191 characters.
 */
class Send4_Submission_Id {

	const MAX_LENGTH    = 191;
	const SECRET_OPTION = 'send4_id_secret';
	const SUFFIX_LENGTH = 12;

	/**
	 * Build the id for a submission.
	 *
	 * When the form plugin gives us a real entry id the suffix is derived from
	 * it, so a plugin that fires its hook twice for one entry produces the same
	 * id and Send4 dedupes it. Without one — Contact Form 7 stores no entries,
	 * and WPForms Lite always reports entry id 0 — the suffix is random. That
	 * asymmetry is deliberate: a constant id on the Lite path would make Send4's
	 * 24 hour dedupe window swallow every lead after the first while the plugin
	 * cheerfully logged 202s.
	 *
	 * @param Send4_Submission $submission Captured submission.
	 * @param int|null         $timestamp  Override for tests.
	 * @return string
	 */
	public static function generate( Send4_Submission $submission, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$entry_id  = null === $submission->entry_id ? 0 : (int) $submission->entry_id;

		if ( $entry_id > 0 ) {
			$entry  = (string) $entry_id;
			$suffix = substr(
				hash_hmac( 'sha256', $submission->form_key . '|' . $entry, self::secret() ),
				0,
				self::SUFFIX_LENGTH
			);
		} else {
			$entry  = (string) $timestamp;
			$suffix = self::random_suffix();
		}

		$tail   = ':' . $entry . ':' . $suffix;
		$budget = self::MAX_LENGTH - strlen( $tail );
		$key    = strlen( $submission->form_key ) > $budget ? substr( $submission->form_key, 0, $budget ) : $submission->form_key;

		return $key . $tail;
	}

	/**
	 * A secret owned by this plugin, created on first use.
	 *
	 * Deliberately not wp_salt(): the suffix is transmitted to and stored by a
	 * third party, and a WordPress authentication salt must never be an input to
	 * anything that leaves the site.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( is_string( $secret ) && 32 <= strlen( $secret ) ) {
			return $secret;
		}

		$secret = self::random_hex( 32 );
		update_option( self::SECRET_OPTION, $secret, false );

		return $secret;
	}

	/**
	 * Random hex for the no-entry-id path.
	 *
	 * @return string
	 */
	private static function random_suffix() {
		return substr( self::random_hex( self::SUFFIX_LENGTH ), 0, self::SUFFIX_LENGTH );
	}

	/**
	 * N hex characters from the best source available.
	 *
	 * @param int $length Characters wanted.
	 * @return string
	 */
	private static function random_hex( $length ) {
		$bytes = (int) ceil( $length / 2 );

		if ( function_exists( 'random_bytes' ) ) {
			try {
				return substr( bin2hex( random_bytes( $bytes ) ), 0, $length );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		$fallback = '';

		while ( strlen( $fallback ) < $length ) {
			$fallback .= str_pad( dechex( wp_rand( 0, 65535 ) ), 4, '0', STR_PAD_LEFT );
		}

		return substr( $fallback, 0, $length );
	}
}
