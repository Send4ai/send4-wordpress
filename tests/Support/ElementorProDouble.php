<?php
/**
 * A runtime stand-in for the class the Elementor Pro adapter looks for.
 *
 * Elementor Pro is a paid plugin. Send4_Adapter_Elementor::is_available() is
 * satisfied by any one of did_action( 'elementor_pro/init' ), this class, or
 * the ELEMENTOR_PRO_VERSION constant; declaring the class is the only one of
 * the three that does not leak into WordPress's own action bookkeeping.
 *
 * Loaded from tests/bootstrap.php after WordPress has booted, so the plugin's
 * plugins_loaded registration has already run without it. Submissions are fed
 * through Send4_Elementor_Record_Double, which mirrors the real Form_Record.
 *
 * @package Send4
 */

namespace ElementorPro;

if ( ! class_exists( '\ElementorPro\Plugin' ) ) {

	/**
	 * Marker class; the adapter only ever checks that it exists.
	 */
	class Plugin {}
}
