<?php
/**
 * PHPUnit bootstrap: a real WordPress with the plugin and the two form plugins
 * we can legally install loaded.
 *
 * @package Send4
 */

// First, because wp-phpunit's autoload file is what puts WP_PHPUNIT__DIR into
// the environment.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$send4_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $send4_tests_dir || '' === $send4_tests_dir ) {
	$send4_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( false === $send4_tests_dir || '' === $send4_tests_dir ) {
	echo 'Could not locate the WordPress test library. Run composer install.' . PHP_EOL;
	exit( 1 );
}

$send4_tests_dir = rtrim( $send4_tests_dir, '/\\' );

/**
 * Create the test database if the server has not got one yet.
 *
 * WordPress's own installer connects straight to DB_NAME and gives up if it is
 * missing. CI's database service is started with the database already created,
 * but the development compose file's is not (and it is shared with the running
 * site, so it cannot simply be renamed), which would otherwise make
 * `docker compose run tests` fail on a fresh checkout.
 *
 * Defaults match tests/wp-config.php.
 *
 * @return void
 */
function send4_tests_ensure_database() {
	if ( ! function_exists( 'mysqli_connect' ) ) {
		return;
	}

	$host     = getenv( 'WP_TESTS_DB_HOST' );
	$name     = getenv( 'WP_TESTS_DB_NAME' );
	$user     = getenv( 'WP_TESTS_DB_USER' );
	$password = getenv( 'WP_TESTS_DB_PASSWORD' );

	$host     = ( false === $host || '' === $host ) ? '127.0.0.1' : $host;
	$name     = ( false === $name || '' === $name ) ? 'wp_test' : $name;
	$user     = ( false === $user || '' === $user ) ? 'root' : $user;
	$password = ( false === $password || '' === $password ) ? 'root' : $password;

	$port = 3306;

	if ( false !== strpos( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
		$port                = (int) $port;
	}

	$link = @mysqli_connect( $host, $user, $password, '', $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! $link ) {
		return;
	}

	mysqli_query( $link, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', $name ) . '`' );
	mysqli_close( $link );
}

send4_tests_ensure_database();

require_once $send4_tests_dir . '/includes/functions.php';

/**
 * Load the plugins under test.
 *
 * roots/wordpress ships no wp-content, so the form plugins live where Composer
 * put them (tests/plugins) rather than anywhere relative to ABSPATH.
 *
 * @return void
 */
function send4_tests_load_plugins() {
	global $wp_plugin_paths;

	$plugins = dirname( __FILE__ ) . '/plugins';

	/*
	 * Tell WordPress where WPForms would live if this were a real install.
	 *
	 * WPForms Lite refuses to boot when plugin_basename() of its main file is
	 * not `wpforms-lite/wpforms.php`: it looks its own requirements up by that
	 * key, misses, falls back to the add-on defaults and decides it is an
	 * unlicensed add-on. Loaded from tests/plugins that is exactly what happens,
	 * and the symptom is silent — wpforms() is simply never defined, so
	 * Send4_Adapter_Wpforms::is_available() answers false and the adapter is
	 * never registered. $wp_plugin_paths is the same mechanism WordPress uses
	 * for symlinked plugins.
	 */
	$wp_plugin_paths[ WP_PLUGIN_DIR . '/wpforms-lite' ] = $plugins . '/wpforms-lite';

	require_once $plugins . '/contact-form-7/wp-contact-form-7.php';
	require_once $plugins . '/wpforms-lite/wpforms.php';
	require_once dirname( __DIR__ ) . '/send4.php';
}

tests_add_filter( 'muplugins_loaded', 'send4_tests_load_plugins' );

require_once $send4_tests_dir . '/includes/bootstrap.php';

// The queue table has to exist before the first test, because every test runs
// inside a transaction that is rolled back: a dbDelta issued from inside a test
// would create the table and then lose it (and MySQL DDL would commit the
// surrounding transaction on the way through, which is worse).
Send4_Installer::install();

require_once dirname( __FILE__ ) . '/Support/HttpRecorder.php';
require_once dirname( __FILE__ ) . '/Support/Send4TestCase.php';
require_once dirname( __FILE__ ) . '/Support/ElementorRecordDouble.php';
require_once dirname( __FILE__ ) . '/Support/ElementorAjaxHandlerDouble.php';
require_once dirname( __FILE__ ) . '/Support/Cf7SubmissionDouble.php';
require_once dirname( __FILE__ ) . '/Support/Cf7Factory.php';
require_once dirname( __FILE__ ) . '/Support/FakeAdapter.php';

// Deliberately after the WordPress bootstrap above, and therefore after the
// plugin's own plugins_loaded registration: with these loaded any earlier, the
// Gravity Forms and Elementor adapters would already be hooked and a test could
// not tell its own call to Send4_Adapters::register_hooks() from the plugin's.
require_once dirname( __FILE__ ) . '/Support/GravityFormsDouble.php';
require_once dirname( __FILE__ ) . '/Support/ElementorProDouble.php';
