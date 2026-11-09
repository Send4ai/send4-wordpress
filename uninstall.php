<?php
/**
 * Uninstall: remove everything the plugin created.
 *
 * Deactivating keeps the queue and the connection; uninstalling does not. That
 * is the WordPress convention, and it matters here because the queue can hold
 * lead data that must not outlive the plugin.
 *
 * @package Send4
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Remove one site's tables, options and scheduled events.
 *
 * @return void
 */
function send4_uninstall_current_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'send4_queue';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

	delete_option( 'send4_connection' );
	delete_option( 'send4_forms' );
	delete_option( 'send4_form_status' );
	delete_option( 'send4_forms_hash' );
	delete_option( 'send4_forms_synced_at' );
	delete_option( 'send4_log' );
	delete_option( 'send4_stats' );
	delete_option( 'send4_db_version' );
	delete_option( 'send4_api_base_url' );
	delete_option( 'send4_id_secret' );
	delete_option( 'send4_plugin_requirement' );
	// Literal on purpose: the plugin's classes are not loaded during uninstall.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'send4\\_seen\\_%'" );
	delete_transient( 'send4_access_token' );
	delete_transient( 'send4_handshake' );
	// The others are named at run time, an admin notice per user and a Gravity
	// Forms format warning per form and field, so they go by prefix, along with
	// any transient added after this file was last edited. Only the options
	// table can be searched that way. Behind a persistent object cache they are
	// left to expire, a notice within a minute and a warning within a day, and
	// neither holds lead data.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\\_transient\\_send4\\_%'
		OR option_name LIKE '\\_transient\\_timeout\\_send4\\_%'"
	);

	foreach ( array( 'send4_process_queue', 'send4_sync_forms' ) as $send4_hook ) {
		$timestamp = wp_next_scheduled( $send4_hook );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $send4_hook );
			$timestamp = wp_next_scheduled( $send4_hook );
		}
	}
}

// The queue holds lead data, so a subsite left behind on a network uninstall
// would be a quiet retention breach rather than a tidiness problem.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $send4_site_id ) {
		switch_to_blog( (int) $send4_site_id );
		send4_uninstall_current_site();
		restore_current_blog();
	}
} else {
	send4_uninstall_current_site();
}

// Who dismissed the minimum-version notice. User meta belongs to the network
// rather than to a site, so it is removed once, for every user.
delete_metadata( 'user', 0, 'send4_dismissed_minimum', '', true );
