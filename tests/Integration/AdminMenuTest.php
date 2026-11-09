<?php
/**
 * The Send4 menu.
 *
 * @package Send4
 */

/**
 * Three screens under one top-level menu, all behind manage_options, with the
 * old Settings URL still answering for connections that were started before
 * the plugin moved.
 */
class Send4_Admin_Menu_Test extends Send4_Test_Case {

	/**
	 * Build the menu the way wp-admin does, as the given role.
	 *
	 * @param string $role Role to run as.
	 * @return void
	 */
	private function buildMenuAs( $role ) {
		global $menu, $submenu, $_registered_pages, $_parent_pages;

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		( new Send4_Admin() )->register_page();
	}

	/**
	 * The slugs registered under a parent, in order.
	 *
	 * @param string $parent Parent slug.
	 * @return array<int,string>
	 */
	private function submenuSlugs( $parent ) {
		global $submenu;

		if ( ! isset( $submenu[ $parent ] ) ) {
			return array();
		}

		return wp_list_pluck( $submenu[ $parent ], 2 );
	}

	public function test_it_registers_a_top_level_menu_with_three_screens() {
		$this->buildMenuAs( 'administrator' );

		global $menu;

		$this->assertContains(
			Send4_Admin::PAGE_SLUG,
			wp_list_pluck( $menu, 2 ),
			'Send4 should be its own top-level menu, not a Settings submenu.'
		);

		$this->assertSame(
			array(
				Send4_Admin::PAGE_SLUG,
				Send4_Admin::FORMS_SLUG,
				Send4_Admin::CONNECTION_SLUG,
			),
			$this->submenuSlugs( Send4_Admin::PAGE_SLUG )
		);
	}

	/**
	 * The design shows five items; only the three the plugin can actually
	 * serve are registered. A menu entry for a screen with no API behind it
	 * would be a promise the plugin cannot keep.
	 *
	 * @return void
	 */
	public function test_it_does_not_advertise_screens_that_do_not_exist() {
		$this->buildMenuAs( 'administrator' );

		$slugs = $this->submenuSlugs( Send4_Admin::PAGE_SLUG );

		$this->assertNotContains( 'send4-conversations', $slugs );
		$this->assertNotContains( 'send4-widget', $slugs );
	}

	public function test_every_screen_requires_manage_options() {
		$this->buildMenuAs( 'administrator' );

		global $submenu;

		foreach ( $submenu[ Send4_Admin::PAGE_SLUG ] as $item ) {
			$this->assertSame( Send4_Admin::CAPABILITY, $item[1], 'Screen ' . $item[2] . ' is not behind manage_options.' );
		}
	}

	/**
	 * add_menu_page() adds to $menu whatever the current user can do and
	 * wp-admin filters it at render time, so the capability on the entry is
	 * what actually keeps an editor out.
	 *
	 * @return void
	 */
	public function test_an_editor_cannot_reach_any_send4_screen() {
		$this->buildMenuAs( 'editor' );

		$this->assertFalse( current_user_can( Send4_Admin::CAPABILITY ) );
		$this->assertSame(
			array(),
			$this->submenuSlugs( Send4_Admin::PAGE_SLUG ),
			'add_submenu_page() refuses to register for a user without the capability.'
		);
	}

	/**
	 * A connection registered before the upgrade told Send4 to return to
	 * options-general.php?page=send4, and has 24 hours to do so. That URL has
	 * to land on the screen that redeems the code, carrying the code with it.
	 *
	 * @return void
	 */
	public function test_the_old_settings_url_redirects_and_keeps_the_code() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$pagenow                  = 'options-general.php';
		$_GET['page']             = Send4_Admin::PAGE_SLUG;
		$_GET['send4_code']       = 'code-abc';
		$_GET['send4_connect_id'] = 'wpconnect_abc123';

		$destination = $this->captureRedirect();

		$this->assertNotNull( $destination, 'The old URL must not dead-end.' );
		$this->assertStringContainsString( 'page=' . Send4_Admin::CONNECTION_SLUG, $destination );
		$this->assertStringContainsString( 'send4_code=code-abc', $destination );
		$this->assertStringContainsString( 'send4_connect_id=wpconnect_abc123', $destination );
	}

	/**
	 * Every other Settings screen has to be left alone.
	 *
	 * @return void
	 */
	public function test_other_settings_screens_are_not_redirected() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$pagenow      = 'options-general.php';
		$_GET['page'] = 'some-other-plugin';

		$this->assertNull( $this->captureRedirect() );
	}

	/**
	 * Run something and report where it tried to redirect to, if anywhere.
	 *
	 * The handlers end in exit, as every redirect in wp-admin does, so the
	 * filter throws to unwind out of them before that line is reached.
	 *
	 * @param callable|null $run What to run, defaulting to the legacy redirect.
	 * @return string|null
	 */
	private function captureRedirect( $run = null ) {
		if ( null === $run ) {
			$run = static function () {
				( new Send4_Admin() )->maybe_redirect_legacy_url();
			};
		}

		$spy = static function ( $location ) {
			throw new Send4_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$run();

			return null;
		} catch ( Send4_Redirected $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}
	}

	/**
	 * wp-admin prints admin-header.php before it calls a page's render
	 * callback, so a redirect from inside the renderer cannot send Location and
	 * the exit that follows leaves the admin looking at a blank page. The
	 * connect return leg therefore has to be redeemed on admin_init.
	 *
	 * @return void
	 */
	public function test_the_connect_return_leg_is_redeemed_before_any_output() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$pagenow                  = 'admin.php';
		$_GET['page']             = Send4_Admin::CONNECTION_SLUG;
		$_GET['send4_code']       = 'code-abc';
		$_GET['send4_connect_id'] = 'wpconnect_abc123';

		$this->http->queue(
			Send4_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'minted-id',
					'client_secret' => 'minted-secret',
				)
			)
		);
		$this->http->queueToken();
		$this->http->queueHandshake();

		$destination = $this->captureRedirect(
			static function () {
				( new Send4_Admin() )->maybe_complete_connect();
			}
		);

		$this->assertNotNull( $destination, 'admin_init must redeem the code, not the renderer.' );
		$this->assertStringContainsString( 'send4_notice=success', $destination );

		$connection = Send4_Settings::connection();
		$this->assertSame( 'minted-id', $connection['client_id'] );
		$this->assertSame( '', $connection['connect_id'], 'The one-shot pair must not survive redemption.' );
	}

	/**
	 * And the renderer must stay inert for it, or the blank screen comes back.
	 *
	 * @return void
	 */
	public function test_rendering_the_connection_screen_never_redirects() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$_GET['page']             = Send4_Admin::CONNECTION_SLUG;
		$_GET['send4_code']       = 'code-abc';
		$_GET['send4_connect_id'] = 'wpconnect_abc123';

		$admin = new Send4_Admin();

		$destination = $this->captureRedirect(
			static function () use ( $admin ) {
				ob_start();

				try {
					$admin->render_connection();
				} finally {
					ob_end_clean();
				}
			}
		);

		$this->assertNull(
			$destination,
			'Redirecting from a render callback cannot send Location, and the exit after it blanks the screen.'
		);
	}

	/**
	 * WordPress names a submenu's screen after the parent's *translated* menu
	 * title. Screen detection built from the slugs only worked because
	 * sanitize_title( 'Send4' ) happens to equal the slug, so a translated
	 * title took the stylesheet away from every screen but the dashboard.
	 *
	 * @return void
	 */
	public function test_screens_are_recognised_when_the_menu_title_is_translated() {
		global $menu, $submenu, $_registered_pages, $_parent_pages, $admin_page_hooks;

		$translate = static function ( $translation, $text, $domain ) {
			return 'send4' === $domain && 'Send4' === $text ? 'Siųsk Keturis' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();
		$admin_page_hooks  = array();

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$admin = new Send4_Admin();
		$admin->register_page();

		remove_filter( 'gettext', $translate, 10 );

		$forms_hook = get_plugin_page_hookname( Send4_Admin::FORMS_SLUG, Send4_Admin::PAGE_SLUG );

		$this->assertNotSame( Send4_Admin::PAGE_SLUG . '_page_' . Send4_Admin::FORMS_SLUG, $forms_hook, 'The translation should have changed the hook name.' );

		wp_dequeue_style( 'send4-admin' );
		wp_dequeue_script( 'send4-phone-country' );
		$_GET['form'] = 'contact-form-7:1';

		$admin->enqueue( $forms_hook );

		unset( $_GET['form'] );

		$this->assertTrue( wp_style_is( 'send4-admin', 'enqueued' ), 'The forms screen lost its stylesheet.' );
		$this->assertTrue( wp_script_is( 'send4-phone-country', 'enqueued' ), 'The mapping screen lost its country names.' );

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		set_current_screen( get_plugin_page_hookname( Send4_Admin::CONNECTION_SLUG, Send4_Admin::PAGE_SLUG ) );
		$this->assertStringContainsString( 'send4-screen', $admin->body_class( '' ) );

		set_current_screen( 'dashboard' );
		$this->assertStringNotContainsString( 'send4-screen', $admin->body_class( '' ) );
	}

	/**
	 * page_url() is the one place admin URLs are built, so the connect flow
	 * and the views cannot drift apart.
	 *
	 * @return void
	 */
	public function test_page_url_points_at_the_new_home() {
		$admin = new Send4_Admin();

		$this->assertStringContainsString( 'admin.php?page=' . Send4_Admin::PAGE_SLUG, $admin->page_url() );
		$this->assertStringContainsString(
			'page=' . Send4_Admin::CONNECTION_SLUG,
			$admin->page_url( array(), Send4_Admin::CONNECTION_SLUG )
		);
		$this->assertStringContainsString(
			'form=contact-form-7:1',
			$admin->page_url( array( 'form' => 'contact-form-7:1' ), Send4_Admin::FORMS_SLUG )
		);
	}

	/**
	 * A code is opaque to the plugin, so it is forwarded exactly as it arrived.
	 *
	 * add_query_arg() does not encode what it is handed, so an unencoded value
	 * carrying an ampersand would be split into further query arguments — and
	 * a `page` among them would win, because the last one does.
	 *
	 * @return void
	 */
	public function test_the_old_settings_url_forwards_the_code_intact() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$pagenow                  = 'options-general.php';
		$_GET['page']             = Send4_Admin::PAGE_SLUG;
		$_GET['send4_code']       = 'code&page=somewhere-else#x';
		$_GET['send4_connect_id'] = 'wpconnect_abc123';

		$destination = $this->captureRedirect();

		$this->assertNotNull( $destination );

		parse_str( (string) wp_parse_url( $destination, PHP_URL_QUERY ), $query );

		$this->assertSame( Send4_Admin::CONNECTION_SLUG, $query['page'], 'A forwarded value must not be able to change the page.' );
		$this->assertSame( 'code&page=somewhere-else#x', $query['send4_code'] );
		$this->assertSame( 'wpconnect_abc123', $query['send4_connect_id'] );
	}

	/**
	 * A notice is something the plugin says, not something a URL can make it
	 * say. With the text in the query string — as it used to be — anybody could
	 * link an administrator to a Send4 screen carrying an error, or a success,
	 * of their own choosing, in the plugin's voice.
	 *
	 * @return void
	 */
	public function test_a_notice_cannot_be_planted_through_the_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['send4_notice']  = 'error';
		$_GET['send4_message'] = 'Your licence has expired. Re-enter your card at evil.example to keep sending.';

		$this->assertNull( ( new Send4_Admin() )->current_notice() );
	}

	/**
	 * Nor can a crafted query string take the screen down: rawurldecode() on
	 * an array is a TypeError on PHP 8, and the old code called it on whatever
	 * arrived under send4_message.
	 *
	 * @return void
	 */
	public function test_an_array_in_the_query_string_is_not_a_fatal_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['send4_notice']  = array( 'success' );
		$_GET['send4_message'] = array( 'x' );

		$this->assertNull( ( new Send4_Admin() )->current_notice() );
	}

	/**
	 * The real thing: a handler's outcome reaches the screen it redirects to,
	 * once, and only for the user whose action it was.
	 *
	 * @return void
	 */
	public function test_a_handler_outcome_is_shown_once_to_the_user_who_caused_it() {
		$actor     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$bystander = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $actor );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4_clear_log' );

		$destination = $this->captureRedirect(
			static function () {
				( new Send4_Admin() )->handle_clear_log();
			}
		);

		$this->assertNotNull( $destination );
		$this->assertStringContainsString( 'send4_notice=success', $destination );
		$this->assertStringNotContainsString( 'send4_message', $destination, 'The text must not travel in the URL.' );
		$this->assertStringNotContainsString( 'cleared', $destination );

		$_GET['send4_notice'] = 'success';

		// Another administrator following the same link sees nothing.
		wp_set_current_user( $bystander );
		$this->assertNull( ( new Send4_Admin() )->current_notice() );

		wp_set_current_user( $actor );
		$notice = ( new Send4_Admin() )->current_notice();

		$this->assertSame( 'success', $notice['type'] );
		$this->assertSame( 'Activity log cleared.', $notice['message'] );

		$this->assertNull( ( new Send4_Admin() )->current_notice(), 'A notice is shown once; a reload does not repeat it.' );
	}

	/**
	 * The Connect button follows the approval link the API hands back, so the
	 * API — or anything able to answer as it — must not be able to send the
	 * administrator anywhere but Send4.
	 *
	 * @return void
	 */
	public function test_the_connect_button_refuses_an_approval_link_that_is_not_send4() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4_start_connect' );

		$this->http->queue(
			Send4_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://evil.example/approve',
				)
			)
		);

		$destination = $this->captureRedirect(
			static function () {
				( new Send4_Admin() )->handle_start_connect();
			}
		);

		$this->assertNotNull( $destination );
		$this->assertStringNotContainsString( 'evil.example', $destination );
		$this->assertStringContainsString( 'send4_notice=error', $destination );
		$this->assertStringContainsString( 'wp-admin', $destination, 'The administrator stays on this site.' );

		$this->assertSame( '', Send4_Settings::connection()['connect_id'], 'A refused registration leaves nothing to redeem.' );
	}

	/**
	 * And a genuine one is followed, so the check above is not "never leave".
	 *
	 * @return void
	 */
	public function test_the_connect_button_follows_a_send4_approval_link() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4_start_connect' );

		$this->http->queue(
			Send4_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://app.send4.ai/connect/wordpress/wpconnect_abc123',
				)
			)
		);

		$destination = $this->captureRedirect(
			static function () {
				( new Send4_Admin() )->handle_start_connect();
			}
		);

		$this->assertSame( 'https://app.send4.ai/connect/wordpress/wpconnect_abc123', $destination );
		$this->assertSame( 'wpconnect_abc123', Send4_Settings::connection()['connect_id'] );
	}
}

/**
 * Thrown in place of the exit that follows every wp-admin redirect.
 */
class Send4_Redirected extends Exception {

	/**
	 * Where the redirect was headed.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * @param string $location Redirect target.
	 */
	public function __construct( $location ) {
		parent::__construct( 'Redirected to ' . $location );

		$this->location = $location;
	}
}
