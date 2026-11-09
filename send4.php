<?php
/**
 * Plugin Name:       Send4 – AI SMS Lead Follow-up for Contact Forms
 * Plugin URI:        https://send4.ai/integrations/wordpress
 * Description:       Sends your form submissions to Send4 so an AI can follow up with the lead over SMS. Works with Contact Form 7, WPForms, Gravity Forms and Elementor Pro.
 * Version:           0.2.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Send4
 * Author URI:        https://send4.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       send4
 * Domain Path:       /languages
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

define( 'SEND4_VERSION', '0.2.2' );
define( 'SEND4_PLUGIN_FILE', __FILE__ );
define( 'SEND4_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEND4_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The Send4 API the plugin talks to.
 *
 * Overridable with a SEND4_API_BASE_URL constant in wp-config.php (used by the
 * development containers) or, failing that, the
 * send4_api_base_url option. Production sites need neither.
 */
if ( ! defined( 'SEND4_API_BASE_URL' ) ) {
	define( 'SEND4_API_BASE_URL', '' );
}

require_once SEND4_PLUGIN_DIR . 'includes/class-send4-autoloader.php';
Send4_Autoloader::register();

register_activation_hook( __FILE__, array( 'Send4_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Send4_Installer', 'deactivate' ) );

/**
 * The plugin instance.
 *
 * @return Send4_Plugin
 */
function send4() {
	return Send4_Plugin::instance();
}

send4()->boot();
