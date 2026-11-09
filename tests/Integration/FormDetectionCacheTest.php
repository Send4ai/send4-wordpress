<?php
/**
 * Form detection runs once per request, until a form changes.
 *
 * @package Send4
 */

/**
 * Detection queries every active form plugin, and Elementor's decodes the
 * builder tree of every page carrying a form. The forms screen and a single
 * mapping save used to pay for it two or three times over (#0697).
 */
class Send4_Form_Detection_Cache_Test extends Send4_Test_Case {

	/**
	 * The adapter that counts how often it is asked.
	 *
	 * @var Send4_Fake_Adapter
	 */
	private $fake;

	/**
	 * Add the counting adapter.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$fake       = new Send4_Fake_Adapter();
		$this->fake = $fake;

		add_filter(
			'send4_form_adapters',
			static function ( $adapters ) use ( $fake ) {
				$adapters[] = $fake;

				return $adapters;
			}
		);

		Send4_Adapters::reset();
	}

	/**
	 * Leave no posted data behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Asked twice, detected once; the lookup by key uses the same answer.
	 *
	 * @return void
	 */
	public function test_detection_is_memoised_for_the_request() {
		$first = Send4_Adapters::detect_forms();

		$this->assertSame( $first, Send4_Adapters::detect_forms() );
		$this->assertSame( 'fake-forms:1', Send4_Adapters::find_form( 'fake-forms:1' )['key'] );
		$this->assertSame( 1, $this->fake->detections );

		Send4_Adapters::reset();
		Send4_Adapters::detect_forms();

		$this->assertSame( 2, $this->fake->detections, 'reset() forgets the answer too.' );
	}

	/**
	 * A form saved during the request is seen by the next detection.
	 *
	 * @return void
	 */
	public function test_saving_a_post_forgets_the_answer() {
		Send4_Adapters::detect_forms();

		self::factory()->post->create();

		Send4_Adapters::detect_forms();

		$this->assertSame( 2, $this->fake->detections );

		// Elementor saves a form as post meta alone.
		$post_id = self::factory()->post->create();

		Send4_Adapters::detect_forms();
		update_post_meta( $post_id, '_elementor_data', '[]' );
		Send4_Adapters::detect_forms();

		$this->assertSame( 4, $this->fake->detections );
	}

	/**
	 * Saving a mapping looks the form up once: the existence check, the
	 * descriptor it reads and the forms sync it triggers share one detection.
	 *
	 * @return void
	 */
	public function test_saving_a_mapping_detects_forms_once() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->http->fallback( Send4_Http_Recorder::response( 200, array( 'access_token' => 't', 'expires_in' => 3600 ) ) );

		$_POST = array(
			'action'    => 'send4_save_mapping',
			'form_key'  => 'fake-forms:1',
			'_wpnonce'  => wp_create_nonce( 'send4_save_mapping' ),
			'map_phone' => 'phone',
			'enabled'   => '1',
		);

		$_REQUEST = $_POST;

		$spy = static function ( $location ) {
			throw new Send4_Form_Detection_Cache_Redirected( (string) $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			( new Send4_Admin() )->handle_save_mapping();
			$this->fail( 'handle_save_mapping() always redirects.' );
		} catch ( Send4_Form_Detection_Cache_Redirected $redirect ) {
			$this->assertStringContainsString( 'send4_notice=success', $redirect->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->assertTrue( Send4_Settings::form( 'fake-forms:1' )['enabled'] );
		$this->assertSame( 1, $this->fake->detections );
	}
}

/**
 * Thrown in place of the exit that follows the handler's redirect.
 */
class Send4_Form_Detection_Cache_Redirected extends Exception {}
