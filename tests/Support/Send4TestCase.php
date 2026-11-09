<?php
/**
 * Shared setup for the Send4 suite.
 *
 * @package Send4
 */

/**
 * A connected site with a clean queue, a clean log and no real HTTP.
 */
abstract class Send4_Test_Case extends WP_UnitTestCase {

	/**
	 * The HTTP double.
	 *
	 * @var Send4_Http_Recorder
	 */
	protected $http;

	/**
	 * Give every test a connected site and a scripted network.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->http = new Send4_Http_Recorder();
		$this->http->hook();

		// The registry caches the adapter list the first time it is asked, and
		// the list is filterable, so a test that adds an adapter would otherwise
		// leak it into every test that ran afterwards.
		Send4_Adapters::reset();

		delete_option( Send4_Settings::FORMS_OPTION );
		delete_option( Send4_Settings::STATUS_OPTION );
		delete_option( Send4_Settings::BASE_URL_OPTION );
		delete_option( Send4_Logger::OPTION );
		Send4_Stats::clear();
		delete_transient( Send4_Api_Client::TOKEN_TRANSIENT );
		delete_transient( Send4_Api_Client::SIGN_IN_COOLDOWN );
		delete_transient( Send4_Admin::HANDSHAKE_CACHE );

		Send4_Settings::update_connection(
			array(
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
				'client_name'   => 'Stogas UAB',
				'connected_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->emptyQueue();
	}

	/**
	 * Undo the HTTP double.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->http->unhook();

		Send4_Adapters::reset();
		Send4_Settings::disconnect();
		delete_option( Send4_Settings::FORMS_OPTION );
		delete_option( Send4_Settings::STATUS_OPTION );
		delete_option( Send4_Settings::BASE_URL_OPTION );
		$this->emptyQueue();

		parent::tear_down();
	}

	/**
	 * Re-run the plugin's adapter wiring, so the hooks a test fires are the ones
	 * Send4_Adapters::register_hooks() just made.
	 *
	 * The plugin already registered its hooks at bootstrap, on plugins_loaded.
	 * Registering a second time without clearing them would attach two listeners
	 * to the same form-plugin hook and deliver every submission twice, which
	 * would hide the very thing these tests exist to pin down. WP_UnitTestCase
	 * restores the whole hook table in tear_down, so this is undone for free.
	 *
	 * The phone validation hooks (#0688) are cleared and re-registered the same
	 * way. Clearing wpcf7_validate removes nothing of Contact Form 7's own: it
	 * validates through the per-type wpcf7_validate_{$type} filters and leaves
	 * the global one to plugins. The WPForms pair is only ever used by the
	 * Send4 binding in this suite, and Gravity Forms and Elementor Pro are not
	 * installed at all.
	 *
	 * @return void
	 */
	protected function registerAdapterHooks() {
		$hooks = array(
			'wpcf7_before_send_mail',
			'wpforms_process_complete',
			'gform_after_submission',
			'elementor_pro/forms/new_record',
			'wpcf7_validate',
			'wpforms_process_before',
			'wpforms_process_initial_errors',
			'gform_field_validation',
			'elementor_pro/forms/validation',
		);

		foreach ( $hooks as $hook ) {
			remove_all_actions( $hook );
		}

		Send4_Adapters::reset();
		Send4_Adapters::register_hooks();
		Send4_Adapters::register_validation();
	}

	/**
	 * Bring a queue row's next attempt forward so process_queue() picks it up.
	 *
	 * @param object $row Queue row.
	 * @return void
	 */
	protected function makeDue( $row ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4_Queue::table(),
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Empty the queue table.
	 *
	 * The table lives outside the per-test transaction (it is created once, in
	 * the bootstrap), and MySQL commits the transaction when DDL runs, so rows
	 * are cleared explicitly rather than trusted to the rollback.
	 *
	 * @return void
	 */
	protected function emptyQueue() {
		global $wpdb;

		$table = Send4_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Every queue row, oldest first.
	 *
	 * @return array<int,object>
	 */
	protected function queueRows() {
		global $wpdb;

		$table = Send4_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The single queue row, failing the test when there is not exactly one.
	 *
	 * @return object
	 */
	protected function onlyQueueRow() {
		$rows = $this->queueRows();

		$this->assertCount( 1, $rows, 'Expected exactly one queue row.' );

		return $rows[0];
	}

	/**
	 * Enable a form with a field mapping.
	 *
	 * @param string $form_key Form key.
	 * @param array  $mapping  phone/name/email field ids.
	 * @param string $name     Form name.
	 * @return void
	 */
	protected function enableForm( $form_key, array $mapping, $name = 'Roof quote' ) {
		Send4_Settings::update_form(
			$form_key,
			array(
				'enabled' => true,
				'mapping' => $mapping,
				'name'    => $name,
			)
		);
	}

	/**
	 * A fixture, by path below tests/fixtures.
	 *
	 * @param string $path Relative path, e.g. cf7/posted.php.
	 * @return mixed
	 */
	protected function fixture( $path ) {
		return require dirname( __DIR__ ) . '/fixtures/' . $path;
	}
}
