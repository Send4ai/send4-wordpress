<?php
/**
 * What still stands between this site and a texted lead.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the handshake's `readiness` rows for the dashboard (#1531).
 *
 * Send4 accepts a submission with a 202 whether or not anything can text the
 * lead: a workspace with no sending number, or no flow listening, takes the
 * lead and nothing happens. Send4 knows why, and says so on the handshake as
 * `readiness: [{key, ok, message, action_url}]`. The plugin only draws the rows
 * that fail. It never decides anything from them, so a check Send4 adds later
 * shows up without a plugin release.
 */
class Send4_Readiness {

	/**
	 * The longest message shown. Send4 writes it as product copy, but it is
	 * still text from the network drawn on an admin screen.
	 */
	const MAX_MESSAGE = 300;

	/**
	 * The rows that fail, ready to draw.
	 *
	 * Empty when everything passes, when the handshake failed, and when Send4
	 * sent no readiness at all (an older Send4): the card is only ever drawn
	 * from what Send4 actually said.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @return array<int,array{key:string,message:string,action_url:string}>
	 */
	public static function failing( $handshake ) {
		if ( ! is_array( $handshake ) || empty( $handshake['readiness'] ) || ! is_array( $handshake['readiness'] ) ) {
			return array();
		}

		$rows = array();

		foreach ( $handshake['readiness'] as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['ok'] ) && true === $row['ok'] ) ) {
				continue;
			}

			$message = isset( $row['message'] ) ? self::message( $row['message'] ) : '';

			// A row with nothing to say is a row nobody can act on.
			if ( '' === $message ) {
				continue;
			}

			$rows[] = array(
				'key'        => isset( $row['key'] ) && is_string( $row['key'] ) ? sanitize_key( $row['key'] ) : '',
				'message'    => $message,
				'action_url' => isset( $row['action_url'] ) ? self::action_url( $row['action_url'] ) : '',
			);
		}

		return $rows;
	}

	/**
	 * The message as it will be shown: plain text, one line, capped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function message( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );

		return mb_substr( $value, 0, self::MAX_MESSAGE );
	}

	/**
	 * A link only when it leads to Send4.
	 *
	 * The same check that guards the Connect button's redirect: the API is
	 * trusted with submissions, not with where the administrator's browser
	 * goes next.
	 *
	 * @param mixed $value Raw value.
	 * @return string The URL, or '' for no link.
	 */
	private static function action_url( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		return Send4_Settings::authorize_url_is_allowed( $value ) ? $value : '';
	}
}
