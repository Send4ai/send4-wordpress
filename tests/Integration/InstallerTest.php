<?php
/**
 * The schema version, and what it promises.
 *
 * @package Send4
 */

/**
 * send4_db_version is what stops maybe_upgrade() from running dbDelta on every
 * request, so it must only ever claim a table that really exists — and, being
 * read on every request, it must come out of the autoloaded options.
 */
class Send4_Installer_Test extends Send4_Test_Case {

	/**
	 * Put the version back the way the bootstrap left it.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( Send4_Installer::DB_VERSION_OPTION, Send4_Installer::DB_VERSION, true );

		parent::tear_down();
	}

	/**
	 * Make the queue table look missing, and every attempt to create it fail.
	 *
	 * Nothing is really dropped: SHOW TABLES is pointed at a name that does not
	 * exist, and CREATE is swallowed — which also keeps WP_UnitTestCase's
	 * CREATE TEMPORARY TABLE rewrite from shadowing the real table for the rest
	 * of the run.
	 *
	 * @return void
	 */
	private function breakTheTable() {
		add_filter(
			'query',
			static function ( $query ) {
				$trimmed = ltrim( (string) $query );

				if ( 0 === stripos( $trimmed, 'CREATE' ) ) {
					return '';
				}

				// esc_like() has escaped the underscores by now: send4\_queue.
				if ( 0 === stripos( $trimmed, 'SHOW TABLES LIKE' ) && false !== strpos( $trimmed, 'send4' ) ) {
					return "SHOW TABLES LIKE 'send4_no_such_table'";
				}

				return $query;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * A create that failed must be retried on the next request, which only
	 * happens while the version is not recorded. Recording it anyway left the
	 * site with no queue for good, and every lead that needed one dropped.
	 *
	 * @return void
	 */
	public function test_the_version_is_not_recorded_when_the_table_is_missing() {
		delete_option( Send4_Installer::DB_VERSION_OPTION );

		$this->breakTheTable();

		Send4_Installer::install();

		$this->assertFalse( get_option( Send4_Installer::DB_VERSION_OPTION, false ) );
	}

	/**
	 * With the table there, the version is recorded as usual.
	 *
	 * @return void
	 */
	public function test_the_version_is_recorded_once_the_table_exists() {
		delete_option( Send4_Installer::DB_VERSION_OPTION );

		Send4_Installer::install();

		$this->assertSame( Send4_Installer::DB_VERSION, (int) get_option( Send4_Installer::DB_VERSION_OPTION ) );
	}

	/**
	 * Read on every request by maybe_upgrade(), so autoloaded. An upgrading
	 * site stored it with autoload off; the version bump is what moves it,
	 * since update_option() only applies a new flag with a new value.
	 *
	 * @return void
	 */
	public function test_the_version_is_autoloaded_after_an_upgrade() {
		global $wpdb;

		delete_option( Send4_Installer::DB_VERSION_OPTION );
		add_option( Send4_Installer::DB_VERSION_OPTION, 1, '', false );

		Send4_Installer::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Send4_Installer::DB_VERSION_OPTION )
		);

		$autoloaded = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );

		$this->assertSame( Send4_Installer::DB_VERSION, (int) get_option( Send4_Installer::DB_VERSION_OPTION ) );
		$this->assertContains( $autoload, $autoloaded );
	}

	/**
	 * A row is unique per submission and client, and schema 2's key on the
	 * submission alone is gone: it would refuse the new account's row.
	 *
	 * @return void
	 */
	public function test_the_queue_is_unique_per_submission_and_client() {
		global $wpdb;

		$table = Send4_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( 0 === (int) $index->Non_unique ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$unique[ $index->Key_name ][] = $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$this->assertSame( array( 'submission_id', 'client_id' ), $unique['submission_client'] );
		$this->assertArrayNotHasKey( 'submission_id', $unique );
	}

	/**
	 * The upgrade adds client_id to an existing table.
	 *
	 * @return void
	 */
	public function test_the_queue_has_a_client_id_column() {
		global $wpdb;

		$table = Send4_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}" );

		$this->assertContains( 'client_id', $columns );
	}

	/**
	 * If the table is missing when a lead needs it, the lead path creates it
	 * and queues the lead, rather than dropping it.
	 *
	 * @return void
	 */
	public function test_a_failed_enqueue_installs_the_table_and_tries_once_more() {
		$failures = 1;

		add_filter(
			'query',
			static function ( $query ) use ( &$failures ) {
				if ( $failures > 0 && 0 === stripos( ltrim( (string) $query ), 'INSERT INTO `' . Send4_Queue::table() . '`' ) ) {
					--$failures;

					return '';
				}

				return $query;
			}
		);

		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::send( $this->envelope(), 'contact-form-7:1' );

		$this->assertSame( 0, $failures, 'The first insert should have been made to fail.' );
		$this->assertSame( Send4_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
		$this->assertSame( 'queued', Send4_Settings::form( 'contact-form-7:1' )['last_status'] );
	}

	/**
	 * And when even that fails, the admin is told the truth: the lead was not
	 * queued. It used to say "queued" with nothing on the queue.
	 *
	 * @return void
	 */
	public function test_a_lead_that_cannot_be_queued_is_reported_as_failed() {
		add_filter(
			'query',
			static function ( $query ) {
				return 0 === stripos( ltrim( (string) $query ), 'INSERT INTO `' . Send4_Queue::table() . '`' ) ? '' : $query;
			}
		);

		$this->http->queueToken();
		$this->http->queue500();

		Send4_Delivery::send( $this->envelope(), 'contact-form-7:1' );

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'failed', Send4_Settings::form( 'contact-form-7:1' )['last_status'] );
		$this->assertStringContainsString( 'could not be queued', Send4_Settings::form( 'contact-form-7:1' )['last_error'] );

		$entry = Send4_Logger::entries()[0];

		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 'failed', $entry['context']['status'] );
	}

	/**
	 * A minimal envelope.
	 *
	 * @return array
	 */
	private function envelope() {
		return array(
			'submission_id' => 'contact-form-7:1:1700000000:install',
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => 'contact-form-7:1',
				'name' => 'Send4 test form',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);
	}
}
