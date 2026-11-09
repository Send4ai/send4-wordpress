<?php
/**
 * Telling Send4 which versions this site runs.
 *
 * @package Send4
 */

/**
 * Once the plugin is on WordPress.org, every released version can still be
 * installed somewhere, and Send4 can only find out which by being told (#1531).
 * The report rides on the forms push, so it must never cost the site its form
 * inventory, and it must reach Send4 soon after anything in it changes.
 */
class Send4_Environment_Report_Test extends Send4_Test_Case {

	/**
	 * One detectable form, and nothing remembered from a previous push.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Send4_Forms_Sync::forget();

		add_filter(
			'send4_form_adapters',
			static function () {
				return array( new Send4_Fake_Adapter( 'fake-forms' ) );
			}
		);
	}

	/**
	 * A token exchange plus an accepted report.
	 *
	 * @return void
	 */
	private function queueAcceptedReport() {
		$this->http->queueToken();
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 1 ) ) ) );
	}

	/**
	 * The shape Send4 reads (app WordPressSiteEnvironment): six keys, nothing else.
	 *
	 * @return void
	 */
	public function test_the_forms_push_carries_the_environment() {
		$this->queueAcceptedReport();

		$this->assertTrue( Send4_Forms_Sync::maybe_push() );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 1, $requests );

		$body = $requests[0]['body'];
		$this->assertArrayHasKey( 'forms', $body, 'The inventory is still the point of the push.' );
		$this->assertSame(
			array( 'plugin_version', 'wp_version', 'php_version', 'wc_version', 'locale', 'multisite' ),
			array_keys( $body['environment'] )
		);
		$this->assertSame( SEND4_VERSION, $body['environment']['plugin_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $body['environment']['wp_version'] );
		$this->assertSame( get_locale(), $body['environment']['locale'] );
		$this->assertSame( is_multisite(), $body['environment']['multisite'] );
	}

	/**
	 * Distribution builds append a suffix to PHP_VERSION that is longer than
	 * the 32 characters Send4 keeps, which would turn the value into nothing.
	 *
	 * @return void
	 */
	public function test_the_php_version_is_the_plain_release_number() {
		$report = Send4_Environment::report();

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $report['php_version'] );
		$this->assertSame( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, $report['php_version'] );
	}

	/**
	 * No WooCommerce here, and the report says so rather than guessing.
	 *
	 * @return void
	 */
	public function test_wc_version_is_null_without_woocommerce() {
		if ( defined( 'WC_VERSION' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded in this environment.' );
		}

		$this->assertNull( Send4_Environment::report()['wc_version'] );
	}

	/**
	 * The report holds what the site runs, never what a visitor typed.
	 *
	 * @return void
	 */
	public function test_the_report_holds_nothing_but_versions_and_flags() {
		foreach ( Send4_Environment::report() as $key => $value ) {
			$this->assertTrue(
				null === $value || is_bool( $value ) || ( is_string( $value ) && strlen( $value ) <= 32 ),
				$key . ' is not a short version, locale or flag.'
			);
		}
	}

	/**
	 * After an update the next sync reports it, instead of waiting for the
	 * inventory itself to change. The locale stands in for a version here,
	 * because it is the one part of the environment a test can change.
	 *
	 * @return void
	 */
	public function test_a_changed_environment_is_reported_at_the_next_sync() {
		$this->queueAcceptedReport();
		Send4_Forms_Sync::maybe_push();

		// Unchanged: nothing goes out.
		$this->assertNull( Send4_Forms_Sync::maybe_push() );

		$locale = static function () {
			return 'lt_LT';
		};
		add_filter( 'locale', $locale );

		try {
			$this->queueAcceptedReport();
			$this->assertTrue( Send4_Forms_Sync::maybe_push() );
		} finally {
			remove_filter( 'locale', $locale );
		}

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 2, $requests );
		$this->assertSame( 'lt_LT', $requests[1]['body']['environment']['locale'] );
	}

	/**
	 * The same inventory and the same site give the same fingerprint.
	 *
	 * @return void
	 */
	public function test_the_fingerprint_is_stable() {
		$descriptors = Send4_Form_Descriptors::build();

		$this->assertSame(
			Send4_Forms_Sync::fingerprint( $descriptors, Send4_Environment::report() ),
			Send4_Forms_Sync::fingerprint( $descriptors, Send4_Environment::report() )
		);
		$this->assertNotSame(
			Send4_Forms_Sync::fingerprint( $descriptors, Send4_Environment::report() ),
			Send4_Forms_Sync::fingerprint( $descriptors, array_merge( Send4_Environment::report(), array( 'plugin_version' => '9.9.9' ) ) )
		);
	}

	/**
	 * Whoever reports forms without an environment still gets the old body,
	 * key for key.
	 *
	 * @return void
	 */
	public function test_an_empty_environment_sends_the_old_body() {
		$this->queueAcceptedReport();

		$this->assertTrue( ( new Send4_Api_Client() )->report_forms( Send4_Form_Descriptors::build() ) );

		$this->assertSame( array( 'forms' ), array_keys( $this->http->lastBody() ) );
	}
}
