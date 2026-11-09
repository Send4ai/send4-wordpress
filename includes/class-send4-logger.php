<?php
/**
 * A small ring buffer of recent deliveries, shown on the settings screen.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logs to the option ring buffer and, when WP_DEBUG is on, to the PHP error log.
 *
 * Lead data is never written here: entries carry the submission id, the form key
 * and the outcome, but no phone numbers, names or email addresses. The nearest
 * it comes is the masked shape of a number the phone check refused, which keeps
 * the opening digits and the length and nothing else — Send4_Phone_Guard::shape().
 */
class Send4_Logger {

	const OPTION = 'send4_log';
	const LIMIT  = 50;

	/**
	 * Append an entry.
	 *
	 * @param string $level   info|warning|error.
	 * @param string $message Message, already PII-free.
	 * @param array  $context Extra scalar context.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entry = array(
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => $message,
			'context' => array_map( array( __CLASS__, 'stringify' ), $context ),
		);

		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();

		array_unshift( $entries, $entry );
		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$suffix = empty( $entry['context'] ) ? '' : ' ' . wp_json_encode( $entry['context'] );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[send4] ' . $level . ': ' . $message . $suffix );
		}
	}

	/**
	 * Everything currently buffered, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empty the buffer.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Coerce context values to short strings.
	 *
	 * @param mixed $value Context value.
	 * @return string
	 */
	private static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return substr( (string) wp_json_encode( $value ), 0, 200 );
	}
}
