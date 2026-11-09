<?php
/**
 * Telling an admin that Send4 needs a newer version of this plugin.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The minimum-version notice (#1531).
 *
 * WordPress.org cannot force an update, so when Send4 needs something an old
 * version lacks, all it can do is ask. It does so on the handshake, as
 * `plugin: {minimum_version, message}`, and this class keeps the last answer,
 * shows a notice to whoever can update plugins while this version is below it,
 * and lets each of them dismiss it until the minimum rises again.
 *
 * It is a notice and nothing else. No submission is ever held back for an old
 * version: a refused submission is a lost lead.
 *
 * Shown on the Plugins screen and on Send4's own screens only, never across
 * the rest of wp-admin (WordPress.org guideline 11).
 */
class Send4_Minimum_Version {

	/**
	 * Where the last answer is kept, so drawing the Plugins screen never waits
	 * on the network.
	 */
	const OPTION = 'send4_plugin_requirement';

	/**
	 * Per user: the minimum version that user dismissed the notice for.
	 */
	const DISMISSED_META = 'send4_dismissed_minimum';

	/**
	 * The admin-post action behind the Dismiss link.
	 */
	const DISMISS_ACTION = 'send4_dismiss_minimum';

	/**
	 * How long the stored answer is trusted before the daily event asks again.
	 * A little under a day, so a daily event that fires a few minutes early
	 * still refreshes it.
	 */
	const MAX_AGE = 20 * HOUR_IN_SECONDS;

	/**
	 * The longest message shown. Send4 writes it, but it is still text from
	 * the network drawn on an admin screen.
	 */
	const MAX_MESSAGE = 300;

	/**
	 * Screens other than our own that show the notice.
	 */
	const SCREENS = array( 'plugins', 'plugins-network' );

	/**
	 * Hook into wp-admin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Keep what a handshake said, including that it asked for nothing.
	 *
	 * An answer with no `plugin` block (or an unreadable minimum) comes from a
	 * Send4 that does not ask for a minimum, so a minimum stored earlier must not
	 * keep nagging. It is still stored, with an empty minimum and the time, so
	 * the daily refresh knows Send4 was asked today and does not ask again.
	 *
	 * @param array $handshake A successful handshake result.
	 * @return void
	 */
	public static function remember( array $handshake ) {
		$plugin  = isset( $handshake['plugin'] ) && is_array( $handshake['plugin'] ) ? $handshake['plugin'] : array();
		$minimum = isset( $plugin['minimum_version'] ) ? self::version( $plugin['minimum_version'] ) : '';
		$message = '' !== $minimum && isset( $plugin['message'] ) ? self::message( $plugin['message'] ) : '';

		update_option(
			self::OPTION,
			array(
				'minimum_version' => $minimum,
				'message'         => $message,
				'checked_at'      => time(),
			),
			false
		);
	}

	/**
	 * Forget the stored answer. Called on disconnect: what Send4 asked for is
	 * derived from the connection, and a site with none shows no notice.
	 *
	 * Dismissals stay: each one names the minimum it was for.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * Ask Send4 again if the stored answer is more than a day old.
	 *
	 * Run from the daily sync event. Failure leaves the stored answer as it
	 * was: an outage is not news about the minimum.
	 *
	 * @return void
	 */
	public static function refresh_if_stale() {
		if ( ! Send4_Settings::has_credentials() || ! Send4_Settings::base_url_is_allowed() ) {
			return;
		}

		$stored = self::stored();

		if ( null !== $stored && ( time() - $stored['checked_at'] ) < self::MAX_AGE ) {
			return;
		}

		$result = ( new Send4_Api_Client() )->handshake();

		if ( is_array( $result ) ) {
			self::remember( $result );
		}
	}

	/**
	 * The stored answer, or null when Send4 has not been asked since the site
	 * connected. `minimum_version` is '' when Send4 asked for none.
	 *
	 * @return array{minimum_version:string,message:string,checked_at:int}|null
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		return array(
			'minimum_version' => isset( $stored['minimum_version'] ) ? (string) $stored['minimum_version'] : '',
			'message'         => isset( $stored['message'] ) ? (string) $stored['message'] : '',
			'checked_at'      => isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0,
		);
	}

	/**
	 * The minimum this version falls short of, or null when it does not.
	 *
	 * @return string|null
	 */
	public static function unmet() {
		$stored = self::stored();

		if ( null === $stored || '' === $stored['minimum_version'] || ! version_compare( SEND4_VERSION, $stored['minimum_version'], '<' ) ) {
			return null;
		}

		return $stored['minimum_version'];
	}

	/**
	 * Whether the current user should see the notice on this screen.
	 *
	 * Never on a site with no connection: an update would change nothing for
	 * it, and the daily refresh that could clear the notice no longer runs.
	 *
	 * @return bool
	 */
	public static function should_show() {
		$minimum = self::unmet();

		if ( ! Send4_Settings::has_credentials() || null === $minimum || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! self::is_notice_screen( $screen ) ) {
			return false;
		}

		$dismissed = (string) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );

		return '' === $dismissed || version_compare( $dismissed, $minimum, '<' );
	}

	/**
	 * The Plugins screens, and our own.
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return bool
	 */
	private static function is_notice_screen( $screen ) {
		if ( in_array( $screen->id, self::SCREENS, true ) ) {
			return true;
		}

		// Matched on the end of the id: WordPress builds a submenu screen's id
		// from the parent's translated menu title, so only the slug is stable.
		$slugs = array( Send4_Admin::PAGE_SLUG, Send4_Admin::FORMS_SLUG, Send4_Admin::CONNECTION_SLUG );

		foreach ( $slugs as $slug ) {
			$suffix = '_page_' . $slug;

			if ( substr( (string) $screen->id, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print the notice, when it is due.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::should_show() ) {
			return;
		}

		$stored  = self::stored();
		$minimum = self::unmet();

		if ( null === $stored || null === $minimum ) {
			return;
		}

		$message = '' !== $stored['message']
			? $stored['message']
			: sprintf(
				/* translators: %s: the minimum plugin version Send4 asks for, e.g. 0.3.0. */
				__( 'Send4 needs version %s or newer of this plugin. Update it from Dashboard → Updates. Your forms keep sending in the meantime.', 'send4' ),
				$minimum
			);

		$updates_url = is_multisite() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' );
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_ACTION, admin_url( 'admin-post.php' ) ),
			self::DISMISS_ACTION
		);

		echo '<div class="notice notice-warning send4-minimum-notice"><p><strong>'
			. esc_html__( 'Send4', 'send4' ) . ':</strong> '
			. esc_html( $message )
			. '</p><p><a class="button button-primary" href="' . esc_url( $updates_url ) . '">'
			. esc_html__( 'Go to Updates', 'send4' ) . '</a> <a class="button" href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'Dismiss', 'send4' ) . '</a></p></div>';
	}

	/**
	 * Remember that this user dismissed the notice for the current minimum.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'send4' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		$minimum = self::unmet();

		if ( null !== $minimum ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META, $minimum );
		}

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * A version string Send4 may ask for, or '' when it is not one.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function version( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return 1 === preg_match( '/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $value ) && strlen( $value ) <= 32 ? $value : '';
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
}
