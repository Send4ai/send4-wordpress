<?php
/**
 * Stops the same submission reaching Send4 several times in a few seconds.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * One claim per identical envelope per window.
 *
 * Contact Form 7 does not block its submit button while a request is in
 * flight, so a visitor who clicks five times on a slow page makes five
 * complete submissions. Each one fires the capture hook, and because the form
 * plugin has no entry id each gets a fresh random submission id — Send4's own
 * dedupe never sees them as the same lead. The repeats arrive in parallel, so
 * the claim has to be atomic: a transient is a read followed by a write, and
 * three requests landing in the same second would all read "not seen".
 */
class Send4_Duplicate_Guard {

	const OPTION_PREFIX  = 'send4_seen_';
	const DEFAULT_WINDOW = 60;

	/**
	 * Claim an envelope. False means an identical one was claimed moments ago.
	 *
	 * Fails open: a lead sent twice is a nuisance, a lead dropped because the
	 * options table hiccuped is a lost customer.
	 *
	 * @param array    $envelope The envelope about to be sent.
	 * @param int|null $now      Override for tests.
	 * @return bool
	 */
	public static function claim( array $envelope, $now = null ) {
		global $wpdb;

		$window = self::window( $envelope );

		if ( $window <= 0 ) {
			return true;
		}

		$now  = null === $now ? time() : (int) $now;
		$name = self::OPTION_PREFIX . self::fingerprint( $envelope );

		self::sweep( $now - $window );

		// INSERT IGNORE against the unique option_name index, the same lock
		// WP_Upgrader::create_lock() takes: exactly one concurrent request sees
		// a row inserted. add_option() cannot say which request won.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				(string) $now
			)
		);

		return 0 !== $inserted;
	}

	/**
	 * Forget every claim. Used on uninstall.
	 *
	 * @return void
	 */
	public static function purge() {
		self::sweep( PHP_INT_MAX );
	}

	/**
	 * What makes two envelopes the same lead: the form and everything the
	 * visitor typed. The submission id is left out, being the part that differs.
	 *
	 * Keyed with the plugin's secret so the option name says nothing about the
	 * person who filled the form in.
	 *
	 * @param array $envelope The envelope.
	 * @return string
	 */
	public static function fingerprint( array $envelope ) {
		$parts = array(
			'form' => isset( $envelope['form']['key'] ) ? (string) $envelope['form']['key'] : '',
			'lead' => isset( $envelope['lead'] ) ? $envelope['lead'] : array(),
			'data' => isset( $envelope['data'] ) ? $envelope['data'] : array(),
		);

		return substr( hash_hmac( 'sha256', (string) wp_json_encode( $parts ), Send4_Submission_Id::secret() ), 0, 40 );
	}

	/**
	 * Seconds during which an identical envelope counts as a repeat.
	 *
	 * @param array $envelope The envelope.
	 * @return int
	 */
	private static function window( array $envelope ) {
		/**
		 * Filter the duplicate window. Return 0 to send every submission.
		 *
		 * @param int   $seconds  Window in seconds.
		 * @param array $envelope The envelope about to be sent.
		 */
		return (int) apply_filters( 'send4_duplicate_window', self::DEFAULT_WINDOW, $envelope );
	}

	/**
	 * Delete claims made at or before a moment, which is also what lets the
	 * same person submit the same answers again once the window has passed.
	 *
	 * @param int $before Unix timestamp.
	 * @return void
	 */
	private static function sweep( $before ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				(int) $before
			)
		);
	}
}
