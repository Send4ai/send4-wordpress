<?php
/**
 * A pasted client secret is sanitized, then checked, before it is tried.
 *
 * @package Send4
 */

/**
 * Until 0.2.1 the secret was only trimmed, because sanitize_text_field() could
 * change a credential that contained markup. Plugin Check reports that as
 * unsanitized input (#1531). Send4 secrets are `secret_` and 48 letters and
 * digits, characters sanitize_text_field() never touches, so it is now used,
 * and anything that could not be a Send4 secret is refused rather than sent.
 * Markup and percent escapes are what sanitize_text_field() removes, before
 * the check; a real secret contains neither.
 */
class Send4_Connect_Secret_Test extends Send4_Test_Case {

	/**
	 * A made-up secret in the shape Send4 issues.
	 *
	 * @var string
	 */
	const REAL_SECRET = 'secret_NotARealSecretNotARealSecretNotARealSecret000000';

	/**
	 * Log in as someone who may configure Send4.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear the request superglobals the handler reads.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Post the Connection form and return where it redirected.
	 *
	 * @param string $client_id     Client ID field.
	 * @param string $client_secret Client secret field, as typed.
	 * @return string
	 */
	private function connect( $client_id, $client_secret ) {
		$_POST                = array(
			'client_id'     => $client_id,
			'client_secret' => wp_slash( $client_secret ),
		);
		$_REQUEST             = $_POST;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4_connect' );

		$spy = static function ( $location ) {
			throw new Send4_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			( new Send4_Admin() )->handle_connect();
		} catch ( Send4_Redirected $redirect ) {
			return rawurldecode( $redirect->location );
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->fail( 'The handler always redirects.' );

		return '';
	}

	/**
	 * The notice a redirect carries.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	private function noticeAt( $location ) {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$saved = $_GET;

		wp_parse_str( $query, $_GET );

		try {
			$notice = ( new Send4_Admin() )->current_notice();
		} finally {
			$_GET = $saved;
		}

		return is_array( $notice ) ? $notice['message'] : '';
	}

	/**
	 * A real secret reaches Send4 exactly as it was pasted.
	 *
	 * @return void
	 */
	public function test_a_send4_secret_is_tried_unchanged() {
		$this->http->queueTokenRejected( 401 );

		$this->connect( 'client_new', self::REAL_SECRET );

		$requests = $this->http->requests();
		$this->assertNotEmpty( $requests, 'The secret was tried.' );
		$this->assertSame( self::REAL_SECRET, $requests[0]['body']['client_secret'] );
	}

	/**
	 * Copying from a page often brings a space or a line break along at the
	 * ends. Those were always trimmed, and still are.
	 *
	 * @return void
	 */
	public function test_whitespace_around_a_secret_is_still_trimmed() {
		$this->http->queueTokenRejected( 401 );

		$this->connect( 'client_new', "  " . self::REAL_SECRET . "\n" );

		$this->assertSame( self::REAL_SECRET, $this->http->requests()[0]['body']['client_secret'] );
	}

	/**
	 * Things that cannot be a Send4 secret.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function notASecretProvider() {
		return array(
			'a space inside'  => array( 'secret_NotARealSecret NotARealSecretNotARealSecret000000' ),
			'a tab inside'    => array( "secret_NotARealSecret\tNotARealSecretNotARealSecret000000" ),
			'too short'       => array( 'secret_abc' ),
			'punctuation'     => array( 'secret_NotARealSecret!NotARealSecretNotARealSecret000000' ),
			'a non-ASCII one' => array( 'secret_NotARealSecretąNotARealSecretNotARealSecret000000' ),
			'only markup'     => array( '<b></b>' . str_repeat( ' ', 3 ) . 'x' ),
		);
	}

	/**
	 * Anything else is refused with a message and never sent to Send4.
	 *
	 * @dataProvider notASecretProvider
	 *
	 * @param string $typed What was pasted.
	 * @return void
	 */
	public function test_something_that_is_not_a_secret_is_refused( $typed ) {
		$before = Send4_Settings::connection();

		$location = $this->connect( 'client_new', $typed );

		$this->assertStringContainsString( 'send4_notice=error', $location );
		$this->assertStringContainsString( 'That is not a Send4 client secret.', $this->noticeAt( $location ) );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
		$this->assertSame( $before, Send4_Settings::connection(), 'The working connection is left as it was.' );
	}

	/**
	 * An empty secret field still means "keep the one already saved".
	 *
	 * @return void
	 */
	public function test_an_empty_secret_still_reuses_the_saved_one() {
		$this->http->queueTokenRejected( 401 );

		$this->connect( 'client_new', '' );

		$this->assertSame( 'test-client-secret', $this->http->requests()[0]['body']['client_secret'] );
	}

	/**
	 * The rule itself.
	 *
	 * @return void
	 */
	public function test_the_shape_of_a_secret() {
		$this->assertTrue( Send4_Settings::is_client_secret( self::REAL_SECRET ) );
		$this->assertTrue( Send4_Settings::is_client_secret( 'secret_' . str_repeat( 'Z', 48 ) ) );
		$this->assertTrue( Send4_Settings::is_client_secret( 'future-key_0123456789' ), 'A future key with "-" still fits.' );

		$this->assertFalse( Send4_Settings::is_client_secret( '' ) );
		$this->assertFalse( Send4_Settings::is_client_secret( 'secret_short' ) );
		$this->assertFalse( Send4_Settings::is_client_secret( str_repeat( 'a', 256 ) ) );
		$this->assertFalse( Send4_Settings::is_client_secret( self::REAL_SECRET . "\n" ) );
		$this->assertFalse( Send4_Settings::is_client_secret( null ) );
		$this->assertFalse( Send4_Settings::is_client_secret( array( self::REAL_SECRET ) ) );
	}
}
