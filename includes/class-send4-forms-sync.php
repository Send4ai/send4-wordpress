<?php
/**
 * Keeping Send4's copy of this site's form list current.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pushes the detected form descriptors to Send4 when they change (#0687).
 *
 * Deliberately NOT on every admin page load: this is metadata, and a request
 * per page view would be rude to a site that never edits its forms. It runs at
 * the four moments the list can actually have changed, plus a daily sweep for
 * the one that has no hook at all — a form created, renamed or deleted in the
 * CMS tells this plugin nothing.
 *
 * Failure is never fatal. A sync that does not land leaves the stored hash
 * alone, so the next trigger retries; it is not queued into `send4_queue`,
 * which is the submissions retry queue — putting a metadata sync in front of a
 * customer's lead would be the wrong trade every time.
 */
class Send4_Forms_Sync {

	const HOOK        = 'send4_sync_forms';
	const HASH_OPTION = 'send4_forms_hash';
	const AT_OPTION   = 'send4_forms_synced_at';

	/** Re-send an unchanged inventory this often, so a lost push self-heals. */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the daily sweep if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * The cron callback, and the plugin's one daily housekeeping slot.
	 *
	 * Queue retention and the queue event's safety net live here because this
	 * is the only event that always exists: the every-minute queue event is
	 * dropped whenever the queue is empty. Both run before the push, which is
	 * a network call that may spend its whole timeout.
	 *
	 * @return void
	 */
	public static function run() {
		Send4_Privacy::enforce_retention();
		Send4_Cron::ensure_scheduled_if_pending();

		$pushed = self::maybe_push();

		// After the push, which matters more. A site whose admins never open
		// the Send4 screens would otherwise never hear of a new minimum. Not
		// when the push could not reach Send4 at all: a second request would
		// only spend a second timeout, and tomorrow asks again.
		if ( ! is_wp_error( $pushed ) || 'http_request_failed' !== $pushed->get_error_code() ) {
			Send4_Minimum_Version::refresh_if_stale();
		}
	}

	/**
	 * Push the inventory if it has changed, or if the last push is stale.
	 *
	 * @param bool $force Push even when nothing changed.
	 * @return true|WP_Error|null True on a push, WP_Error on failure, null when skipped.
	 */
	public static function maybe_push( $force = false ) {
		if ( ! Send4_Settings::has_credentials() ) {
			return null;
		}

		$descriptors = Send4_Form_Descriptors::build();
		$environment = Send4_Environment::report();
		$hash        = self::fingerprint( $descriptors, $environment );

		if ( ! $force && ! self::is_due( $hash ) ) {
			return null;
		}

		$result = ( new Send4_Api_Client() )->report_forms( $descriptors, $environment );

		if ( is_wp_error( $result ) ) {
			// The hash is deliberately NOT stored: leaving it stale is what
			// makes the next save or the daily sweep try again.
			Send4_Logger::log(
				'warning',
				'Could not report this site\'s forms to Send4.',
				array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);

			return $result;
		}

		update_option( self::HASH_OPTION, $hash, false );
		update_option( self::AT_OPTION, time(), false );

		return true;
	}

	/**
	 * What the stored hash is compared with: the inventory and what the site runs.
	 *
	 * The environment is in it so that a plugin, WordPress or PHP update is
	 * reported at the next sync, not only once the forms happen to change.
	 *
	 * @param array $descriptors Descriptors from Send4_Form_Descriptors::build().
	 * @param array $environment From Send4_Environment::report().
	 * @return string
	 */
	public static function fingerprint( array $descriptors, array $environment ) {
		return md5( Send4_Form_Descriptors::hash( $descriptors ) . '|' . (string) wp_json_encode( $environment ) );
	}

	/**
	 * Whether a push is warranted.
	 *
	 * @param string $hash Fingerprint of the current inventory.
	 * @return bool
	 */
	private static function is_due( $hash ) {
		if ( get_option( self::HASH_OPTION, '' ) !== $hash ) {
			return true;
		}

		$last = (int) get_option( self::AT_OPTION, 0 );

		return ( time() - $last ) >= self::MAX_AGE;
	}

	/**
	 * When the inventory was last accepted by Send4, for the admin screens.
	 *
	 * @return int Unix timestamp, 0 when never.
	 */
	public static function last_synced_at() {
		return (int) get_option( self::AT_OPTION, 0 );
	}

	/**
	 * Forget the sync state, so the next push is unconditional.
	 *
	 * Called on disconnect: the next connection may be a different workspace,
	 * which has never been told anything about this site's forms.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::HASH_OPTION );
		delete_option( self::AT_OPTION );
	}
}
