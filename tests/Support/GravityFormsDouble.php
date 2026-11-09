<?php
/**
 * Runtime stand-ins for the two Gravity Forms classes the adapter looks for.
 *
 * Gravity Forms is a paid plugin, so it can never be a dev dependency and its
 * adapter would otherwise be invisible to Send4_Adapters: is_available() is a
 * class_exists() pair, so without these the adapter is never registered and
 * `gform_after_submission` is never wired up — which is exactly the wiring
 * these doubles exist to let the suite test.
 *
 * Loaded from tests/bootstrap.php *after* WordPress has booted, so the plugin's
 * own plugins_loaded registration has already happened by the time they appear.
 * A test that wants the adapter hooked asks for it explicitly, through
 * Send4_Test_Case::registerAdapterHooks().
 *
 * Only the surface the adapter touches is declared. The envelope itself is
 * built from tests/fixtures/gravityforms/*, not from these.
 *
 * @package Send4
 */

if ( ! class_exists( 'GFForms' ) ) {

	/**
	 * Marker class; the adapter only ever checks that it exists.
	 */
	class GFForms {} // phpcs:ignore
}

if ( ! class_exists( 'GFAPI' ) ) {

	/**
	 * The slice of the Gravity Forms API the adapter calls.
	 */
	class GFAPI { // phpcs:ignore

		/**
		 * Form definitions the double should report.
		 *
		 * @var array<int,array>
		 */
		public static $forms = array();

		/**
		 * Mirror of GFAPI::get_forms().
		 *
		 * @param bool $active Only active forms.
		 * @param bool $trash  Only trashed forms.
		 * @return array<int,array>
		 */
		public static function get_forms( $active = true, $trash = false ) {
			unset( $active, $trash );

			return self::$forms;
		}
	}
}
