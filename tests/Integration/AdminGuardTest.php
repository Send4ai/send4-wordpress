<?php
/**
 * Every admin-post handler checks the capability, then the nonce, itself.
 *
 * @package Send4
 */

/**
 * The checks used to live in one helper, Send4_Admin::authorize(). They were
 * always made, but Plugin Check only sees a nonce check written in the same
 * function that reads $_POST, so the WordPress.org sandbox reported every
 * handler as processing form data without one (#1531, 0.2.1). These tests pin
 * down that splitting them changed nothing: the order is the same and a
 * refused request does nothing.
 */
class Send4_Admin_Guard_Test extends Send4_Test_Case {

	/**
	 * Clear the request superglobals the handlers read.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Every admin-post action the plugin registers.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function handlerProvider() {
		$actions = array(
			'send4_connect',
			'send4_start_connect',
			'send4_test',
			'send4_disconnect',
			'send4_save_mapping',
			'send4_disable_form',
			'send4_sync_forms',
			'send4_run_queue',
			'send4_clear_log',
			'send4_requeue',
		);

		return array_combine( $actions, array_map( static fn ( $action ) => array( $action ), $actions ) );
	}

	/**
	 * Run one handler with this nonce and report the wp_die() message.
	 *
	 * @param string $action Admin-post action.
	 * @param string $nonce  Value of _wpnonce.
	 * @return string The message wp_die() was called with.
	 */
	private function dieMessageOf( $action, $nonce ) {
		$_POST    = array(
			'_wpnonce'      => $nonce,
			'client_id'     => 'client_new',
			'client_secret' => 'secret_' . str_repeat( 'a', 48 ),
			'form_key'      => 'contact-form-7:1',
		);
		$_REQUEST = $_POST;

		$method = 'handle_' . substr( $action, strlen( 'send4_' ) );

		try {
			( new Send4_Admin() )->$method();
		} catch ( WPDieException $died ) {
			return $died->getMessage();
		}

		$this->fail( "{$action} went ahead without a valid request." );

		return '';
	}

	/**
	 * Each handler is wired to its admin-post hook.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_the_handler_is_registered( $action ) {
		$admin = new Send4_Admin();
		$admin->boot();

		$method = 'handle_' . substr( $action, strlen( 'send4_' ) );

		$this->assertNotFalse( has_action( "admin_post_{$action}", array( $admin, $method ) ) );
	}

	/**
	 * An administrator with a forged nonce is turned away, and nothing happens.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_a_forged_nonce_is_refused( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$before = Send4_Settings::connection();

		$message = $this->dieMessageOf( $action, 'forged' );

		$this->assertStringContainsString( 'The link you followed has expired.', $message );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
		$this->assertSame( $before, Send4_Settings::connection(), 'The connection is left as it was.' );
	}

	/**
	 * Somebody who may not configure Send4 is refused even with a valid nonce.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_a_user_without_the_capability_is_refused( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$message = $this->dieMessageOf( $action, wp_create_nonce( $action ) );

		$this->assertSame( 'You do not have permission to configure Send4.', $message );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
	}

	/**
	 * The capability is checked before the nonce, as it always was: someone
	 * without it sees the permission message, never the expired-link one.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_the_capability_is_checked_before_the_nonce( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'You do not have permission to configure Send4.', $this->dieMessageOf( $action, 'forged' ) );
	}
}
