<?php
/**
 * Turning a form on or off only ever touches a form that exists.
 *
 * @package Send4
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * Thrown in place of the exit that follows an admin handler's redirect; the
 * message is the location.
 */
class Send4_Form_Disable_Redirected extends Exception {}

/**
 * update_form() creates whatever entry it is asked for, so a disable request
 * for a key that is not a form — a stale Elementor widget id, a typo on the
 * command line — used to leave a junk entry that nothing ever prunes. And the
 * CLI switched forms on and off without telling Send4, which the admin screen
 * always did (#0696).
 */
class Send4_Form_Key_Validation_Test extends Send4_Test_Case {

	/**
	 * A real Contact Form 7 form's key.
	 *
	 * @var string
	 */
	private $form_key;

	/**
	 * An administrator, a real form, and a network that accepts every forms
	 * sync.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->form_key = 'contact-form-7:' . Send4_Cf7_Factory::create()->id();

		$this->http->fallback( Send4_Http_Recorder::response( 200, array( 'access_token' => 't', 'expires_in' => 3600 ) ) );

		Send4_Forms_Sync::forget();
		WP_CLI::$output = array();
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

	public function test_the_admin_will_not_disable_a_form_that_does_not_exist() {
		$location = $this->disableFromAdmin( 'elementor:123:gone' );

		$this->assertStringContainsString( 'send4_notice=error', $location );
		$this->assertFalse( Send4_Settings::has_form( 'elementor:123:gone' ), 'A junk entry was created.' );
		$this->assertSame( array(), Send4_Settings::forms() );
	}

	public function test_the_admin_disables_a_detected_form() {
		$this->enableForm( $this->form_key, array( 'phone' => 'your-phone' ) );

		$location = $this->disableFromAdmin( $this->form_key );

		$this->assertStringContainsString( 'send4_notice=success', $location );
		$this->assertFalse( Send4_Settings::form( $this->form_key )['enabled'] );
	}

	/**
	 * A form whose plugin is deactivated is not detected, but it is still
	 * configured, and switching it off must still work.
	 *
	 * @return void
	 */
	public function test_the_admin_disables_a_stored_form_that_is_not_detected_right_now() {
		$this->enableForm( 'wpforms:999999', array( 'phone' => 'phone' ) );

		$location = $this->disableFromAdmin( 'wpforms:999999' );

		$this->assertStringContainsString( 'send4_notice=success', $location );
		$this->assertFalse( Send4_Settings::form( 'wpforms:999999' )['enabled'] );
	}

	public function test_the_cli_will_not_disable_a_form_that_does_not_exist() {
		try {
			( new Send4_Cli() )->disable( array( 'contact-form-7:typo' ) );
			$this->fail( 'disable accepted a key that is not a form.' );
		} catch ( Send4_Cli_Halted $halted ) {
			$this->assertStringContainsString( 'No form with key', $halted->getMessage() );
		}

		$this->assertFalse( Send4_Settings::has_form( 'contact-form-7:typo' ), 'A junk entry was created.' );
	}

	public function test_the_cli_disable_tells_send4() {
		$this->enableForm( $this->form_key, array( 'phone' => 'your-phone' ) );

		( new Send4_Cli() )->disable( array( $this->form_key ) );

		$this->assertFalse( Send4_Settings::form( $this->form_key )['enabled'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/forms' ), 'Send4 still shows the form as on.' );
	}

	public function test_the_cli_enable_tells_send4() {
		( new Send4_Cli() )->enable( array( $this->form_key ), array( 'phone' => 'your-phone' ) );

		$this->assertTrue( Send4_Settings::form_is_active( $this->form_key ) );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/forms' );
		$this->assertCount( 1, $requests, 'Send4 does not know the form is on.' );
		$this->assertCount( 1, WP_CLI::printed( 'success' ) );
	}

	/**
	 * Post the list screen's disable button and return where it redirected.
	 *
	 * @param string $form_key Posted form key.
	 * @return string
	 */
	private function disableFromAdmin( $form_key ) {
		$_POST = array(
			'action'   => 'send4_disable_form',
			'form_key' => $form_key,
			'_wpnonce' => wp_create_nonce( 'send4_disable_form' ),
		);

		$_REQUEST = $_POST;

		$spy = static function ( $location ) {
			throw new Send4_Form_Disable_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			( new Send4_Admin() )->handle_disable_form();
		} catch ( Send4_Form_Disable_Redirected $redirect ) {
			return $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->fail( 'handle_disable_form() always redirects.' );
	}
}
