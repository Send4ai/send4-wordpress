<?php
/**
 * The plugin object: wiring, and nothing else.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the adapters, the cron worker, the admin screen and the CLI commands.
 */
class Send4_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Send4_Plugin|null
	 */
	private static $instance = null;

	/**
	 * The admin screen, when we are in wp-admin.
	 *
	 * @var Send4_Admin|null
	 */
	private $admin = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Send4_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), 20 );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'wp_initialize_site', array( 'Send4_Installer', 'on_new_site' ), 20 );

		Send4_Privacy::boot();

		Send4_Cron::boot();

		Send4_Forms_Sync::boot();

		// Not behind ! is_admin(): Elementor renders popups through
		// admin-ajax.php. Registers hooks only.
		Send4_Phone_Field::boot();

		if ( is_admin() ) {
			$this->admin = new Send4_Admin();
			$this->admin->boot();

			Send4_Minimum_Version::boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Send4_Cli::register();
		}
	}

	/**
	 * Once every plugin is loaded we know which form plugins are present.
	 *
	 * Hooks are registered on every request, not just admin or front end:
	 * Contact Form 7 and WPForms both submit through REST or admin-ajax.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		Send4_Installer::maybe_upgrade();
		Send4_Adapters::register_hooks();
		Send4_Adapters::register_validation();
	}

	/**
	 * Scheduled events, once translations can be loaded.
	 *
	 * There is no load_plugin_textdomain() call: since WordPress 4.6 the
	 * `send4` domain is loaded just in time from the language packs
	 * WordPress.org builds, and the plugin ships no .mo files of its own, so
	 * the call loaded nothing. Plugin Check flags it for a listed plugin
	 * (#1531).
	 *
	 * @return void
	 */
	public function on_init() {
		// On init, deliberately: scheduling runs the `cron_schedules` filter,
		// whose display names are translated, and translating before init
		// earns a `_load_textdomain_just_in_time` notice.
		Send4_Installer::ensure_events();
	}
}
