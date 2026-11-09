<?php
/**
 * Uninstall: everything the plugin created has to go, including the leads.
 *
 * @package Send4
 */

/**
 * Deactivating keeps the queue; uninstalling does not, and that is not a
 * tidiness rule. The queue holds a lead's phone number, name and email until
 * delivery succeeds, so a row that outlives the plugin is personal data the
 * site owner no longer has any basis to hold and no interface to find.
 *
 * uninstall.php runs in a WordPress that has not loaded the plugin, so it
 * cannot use the class constants and re-declares every option name as a string
 * literal. That duplication is the failure mode this file is really about: a
 * renamed option would leave its data behind, silently, on every site that ever
 * removes the plugin. So the literals are checked against the constants, and
 * the run itself is checked against "nothing called send4 is left".
 */
class Send4_Uninstall_Test extends Send4_Test_Case {

	/**
	 * Another plugin's transient, one character away from ours: a hyphen where
	 * we have an underscore, which is exactly what an unescaped LIKE lets through.
	 */
	const NEIGHBOURS_TRANSIENT = 'send4-widget_cache';

	/**
	 * Let this class issue real DDL.
	 *
	 * WP_UnitTestCase rewrites every CREATE TABLE and DROP TABLE into its
	 * TEMPORARY form, so that a test cannot damage the schema the rest of the
	 * run depends on. Under that rewrite `DROP TABLE IF EXISTS` against a real
	 * table is a silent no-op — which would make this whole file pass while
	 * uninstall.php dropped nothing at all. The rewrites are lifted here and
	 * WP_UnitTestCase::tear_down() takes them off again by itself.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertTrue( Send4_Queue::table_exists(), 'The suite should start with a queue table.' );
	}

	/**
	 * Put the queue table back.
	 *
	 * The drop was real and MySQL commits the surrounding transaction on the way
	 * through DDL, so the rollback in tear_down cannot undo it, and every later
	 * test needs somewhere to put a queue row.
	 *
	 * The same commit keeps the neighbour's transient, which uninstalling is
	 * meant to leave alone, so it is taken out by hand. Here rather than in its
	 * test, so that a failed assertion there cannot leave it for the next one.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_transient( self::NEIGHBOURS_TRANSIENT );

		Send4_Installer::install();

		parent::tear_down();
	}

	/**
	 * Run the uninstaller the way WordPress does.
	 *
	 * The file is included once per process — it declares a function — so later
	 * tests in this class call that function directly. Both routes run the same
	 * code; only the first proves the file's top-level guard and dispatch work.
	 *
	 * @return void
	 */
	private function runUninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'send4/send4.php' );
		}

		if ( function_exists( 'send4_uninstall_current_site' ) ) {
			send4_uninstall_current_site();

			return;
		}

		require_once dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * A site that has been used: credentials, a mapped form, a delivery
	 * outcome, a log, an id secret, a queued lead, a forms sync that landed and
	 * both scheduled events.
	 *
	 * @return void
	 */
	private function seedAWorkingSite() {
		update_option( Send4_Settings::BASE_URL_OPTION, 'https://staging.send4.ai', false );

		$this->enableForm( 'contact-form-7:1', array( 'phone' => 'your-phone' ), 'Roof quote' );

		Send4_Settings::record_delivery( 'contact-form-7:1', 'delivered' );
		Send4_Logger::log( 'info', 'Submission delivered and routed to a flow.' );

		// Only a sync Send4 accepted writes its hash and timestamp, so without
		// one the "nothing left behind" check below cannot see them.
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );
		$this->assertTrue( Send4_Forms_Sync::maybe_push( true ) );

		set_transient(
			Send4_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'a-real-looking-token',
				'expires_at' => time() + 3600,
			),
			3600
		);
		set_transient( Send4_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Stogas UAB' ) ), 300 );

		// The secret is created on first use, and only the entry-id path uses it.
		Send4_Submission_Id::secret();

		Send4_Minimum_Version::remember(
			array(
				'plugin' => array(
					'minimum_version' => '9.0.0',
					'message'         => 'Update the plugin.',
				),
			)
		);
		update_user_meta( self::factory()->user->create(), Send4_Minimum_Version::DISMISSED_META, '9.0.0' );

		Send4_Queue::enqueue(
			array(
				'submission_id' => 'contact-form-7:1:1700000000:abcdef123456',
				'site_url'      => 'http://example.org/',
				'form'          => array(
					'key'  => 'contact-form-7:1',
					'name' => 'Roof quote',
				),
				'lead'          => array(
					'name'  => 'Jonas',
					'email' => 'j@x.lt',
					'phone' => '+37060012345',
				),
				'data'          => array(),
			),
			'contact-form-7:1',
			'Send4 returned status 500.'
		);

		Send4_Cron::ensure_scheduled();
		Send4_Forms_Sync::ensure_scheduled();

		// Everything above really is there, or the test below proves nothing.
		$this->assertNotEmpty( get_option( Send4_Settings::CONNECTION_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Settings::FORMS_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Settings::STATUS_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Forms_Sync::HASH_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Forms_Sync::AT_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Logger::OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Stats::OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Submission_Id::SECRET_OPTION ) );
		$this->assertNotEmpty( get_option( Send4_Minimum_Version::OPTION ) );
		$this->assertSame( Send4_Installer::DB_VERSION, (int) get_option( Send4_Installer::DB_VERSION_OPTION ) );
		$this->assertCount( 1, $this->queueRows() );
		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Send4_Forms_Sync::HOOK ) );
	}

	/**
	 * Option names still in the database that look like ours.
	 *
	 * Catches the case the literal-versus-constant check below cannot: an
	 * option the plugin started writing after uninstall.php was last edited.
	 *
	 * @return array<int,string>
	 */
	private function leftoverOptionNames() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'%' . $wpdb->esc_like( 'send4' ) . '%'
			)
		);

		return is_array( $names ) ? $names : array();
	}

	/**
	 * The whole thing, through the file WordPress actually runs.
	 *
	 * @return void
	 */
	public function test_it_removes_every_option_transient_row_and_event() {
		$this->seedAWorkingSite();

		$this->runUninstall();

		$this->assertFalse( get_option( Send4_Settings::CONNECTION_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Settings::FORMS_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Settings::STATUS_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Forms_Sync::HASH_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Forms_Sync::AT_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Settings::BASE_URL_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Logger::OPTION, false ) );
		$this->assertFalse( get_option( Send4_Stats::OPTION, false ) );
		$this->assertFalse( get_option( Send4_Installer::DB_VERSION_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Submission_Id::SECRET_OPTION, false ) );
		$this->assertFalse( get_option( Send4_Minimum_Version::OPTION, false ) );
		$this->assertSame(
			array(),
			get_users(
				array(
					'meta_key' => Send4_Minimum_Version::DISMISSED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'fields'   => 'ID',
				)
			),
			'Who dismissed the minimum-version notice is ours to remove too.'
		);

		$this->assertFalse( get_transient( Send4_Api_Client::TOKEN_TRANSIENT ) );
		$this->assertFalse( get_transient( Send4_Admin::HANDSHAKE_CACHE ) );

		$this->assertFalse(
			wp_next_scheduled( Send4_Cron::HOOK ),
			'A cron event left behind fires for ever against a plugin that is gone.'
		);
		$this->assertFalse(
			wp_next_scheduled( Send4_Forms_Sync::HOOK ),
			'The daily forms sync must go with the plugin too.'
		);

		$this->assertFalse(
			Send4_Queue::table_exists(),
			'The queue table holds lead data and must not outlive the plugin.'
		);

		$this->assertSame(
			array(),
			$this->leftoverOptionNames(),
			'Something the plugin wrote is still in wp_options after uninstall.'
		);
	}

	/**
	 * Two transients are named at run time, a pending notice per user and a
	 * Gravity Forms format warning per form and field. uninstall.php cannot list
	 * them, so it sweeps the prefix, and 0.1.4 left both behind until they
	 * expired while its changelog said uninstalling removed everything.
	 *
	 * The sweep is a LIKE, where an unescaped "_" matches any character. The
	 * neighbour below is what a sloppier pattern would take with it.
	 *
	 * @return void
	 */
	public function test_it_sweeps_up_the_transients_it_cannot_name() {
		$notice  = Send4_Admin::NOTICE_PREFIX . '7';
		$warning = Send4_Adapter_Gravityforms::US_FORMAT_NOTICE . md5( 'gravityforms:1|3' );

		set_transient(
			$notice,
			array(
				'type'    => 'success',
				'message' => 'Settings saved.',
			),
			Send4_Admin::NOTICE_CACHE_S
		);
		set_transient( $warning, 1, DAY_IN_SECONDS );
		set_transient( self::NEIGHBOURS_TRANSIENT, 1, DAY_IN_SECONDS );

		// Rows, not get_transient(): that would answer from the object cache.
		$this->assertContains( '_transient_' . $notice, $this->leftoverOptionNames() );
		$this->assertContains( '_transient_timeout_' . $notice, $this->leftoverOptionNames() );
		$this->assertContains( '_transient_' . $warning, $this->leftoverOptionNames() );
		$this->assertContains( '_transient_timeout_' . $warning, $this->leftoverOptionNames() );

		$this->runUninstall();

		$left = $this->leftoverOptionNames();
		sort( $left );

		$this->assertSame(
			array(
				'_transient_' . self::NEIGHBOURS_TRANSIENT,
				'_transient_timeout_' . self::NEIGHBOURS_TRANSIENT,
			),
			$left,
			'Every transient of ours must go, and nothing that only looks like one.'
		);
	}

	/**
	 * Uninstalling a site that never got as far as connecting is a no-op, not a
	 * warning or a fatal.
	 *
	 * @return void
	 */
	public function test_it_is_safe_on_a_site_that_was_never_used() {
		Send4_Settings::disconnect();
		Send4_Queue::drop();

		$this->runUninstall();

		$this->assertFalse( Send4_Queue::table_exists() );
		$this->assertSame( array(), $this->leftoverOptionNames() );
	}

	/**
	 * Every name uninstall.php spells out has to be the name the plugin uses.
	 *
	 * @dataProvider ownedNameProvider
	 *
	 * @param string $name  The literal that must appear.
	 * @param string $where Which constant it mirrors, for the failure message.
	 * @return void
	 */
	public function test_the_literals_match_the_constants( $name, $where ) {
		$this->assertStringContainsString(
			"'" . $name . "'",
			$this->uninstallSource(),
			'uninstall.php does not mention ' . $name . ', which is ' . $where . '.'
		);
	}

	/**
	 * Every stored name the plugin owns, and where it is defined.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function ownedNameProvider() {
		return array(
			'the connection'      => array( Send4_Settings::CONNECTION_OPTION, 'Send4_Settings::CONNECTION_OPTION' ),
			'the form mappings'   => array( Send4_Settings::FORMS_OPTION, 'Send4_Settings::FORMS_OPTION' ),
			'the form statuses'   => array( Send4_Settings::STATUS_OPTION, 'Send4_Settings::STATUS_OPTION' ),
			'the forms sync hash' => array( Send4_Forms_Sync::HASH_OPTION, 'Send4_Forms_Sync::HASH_OPTION' ),
			'the forms sync time' => array( Send4_Forms_Sync::AT_OPTION, 'Send4_Forms_Sync::AT_OPTION' ),
			'the delivery log'    => array( Send4_Logger::OPTION, 'Send4_Logger::OPTION' ),
			'the schema version'  => array( Send4_Installer::DB_VERSION_OPTION, 'Send4_Installer::DB_VERSION_OPTION' ),
			'the base URL'        => array( Send4_Settings::BASE_URL_OPTION, 'Send4_Settings::BASE_URL_OPTION' ),
			'the id secret'       => array( Send4_Submission_Id::SECRET_OPTION, 'Send4_Submission_Id::SECRET_OPTION' ),
			'the minimum version' => array( Send4_Minimum_Version::OPTION, 'Send4_Minimum_Version::OPTION' ),
			'its dismissals'      => array( Send4_Minimum_Version::DISMISSED_META, 'Send4_Minimum_Version::DISMISSED_META' ),
			'the access token'    => array( Send4_Api_Client::TOKEN_TRANSIENT, 'Send4_Api_Client::TOKEN_TRANSIENT' ),
			'the handshake cache' => array( Send4_Admin::HANDSHAKE_CACHE, 'Send4_Admin::HANDSHAKE_CACHE' ),
			'the cron event'      => array( Send4_Cron::HOOK, 'Send4_Cron::HOOK' ),
			'the sync event'      => array( Send4_Forms_Sync::HOOK, 'Send4_Forms_Sync::HOOK' ),
		);
	}

	/**
	 * And the table it drops has to be the table the plugin writes to.
	 *
	 * @return void
	 */
	public function test_it_drops_the_table_the_queue_actually_uses() {
		global $wpdb;

		$suffix = substr( Send4_Queue::table(), strlen( $wpdb->prefix ) );

		$this->assertSame( 'send4_queue', $suffix );
		$this->assertStringContainsString(
			"\$wpdb->prefix . '" . $suffix . "'",
			$this->uninstallSource(),
			'uninstall.php drops a different table from the one Send4_Queue::table() names.'
		);
	}

	/**
	 * A network install must not leave one subsite's leads behind.
	 *
	 * @return void
	 */
	public function test_it_walks_every_site_on_a_network() {
		$source = $this->uninstallSource();

		$this->assertStringContainsString( 'is_multisite()', $source );
		$this->assertStringContainsString( 'get_sites(', $source );
		$this->assertStringContainsString( 'switch_to_blog(', $source );
		$this->assertStringContainsString( 'restore_current_blog();', $source );
	}

	/**
	 * uninstall.php, as text.
	 *
	 * @return string
	 */
	private function uninstallSource() {
		$path = dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
