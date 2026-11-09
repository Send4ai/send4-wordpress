<?php
/**
 * A site with more forms than one query returns.
 *
 * @package Send4
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * Contact Form 7 and WPForms were each asked for 100 forms and no more. The
 * 101st could not be set up from the forms list, was called deleted by its own
 * mapping screen, and dropped out of what Send4 is told about the site. On
 * WPForms, which lists by title, adding a form could push an existing one out.
 *
 * Detection now reads a page at a time up to a ceiling, and both adapters can
 * look one form up by id, so a form past even that is still a form.
 */
class Send4_Large_Site_Forms_Test extends Send4_Test_Case {

	/**
	 * A saved WPForms form.
	 *
	 * @param string $title Title, which is what WPForms lists by.
	 * @return int The form id.
	 */
	private function wpform( $title ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wpforms_encode(
					array(
						'id'       => (string) $post_id,
						'fields'   => array(
							'1' => array(
								'id'    => '1',
								'type'  => 'phone',
								'label' => 'Phone',
							),
						),
						'settings' => array( 'form_title' => $title ),
					)
				),
			)
		);

		return $post_id;
	}

	/**
	 * List at most this many forms per form plugin.
	 *
	 * @param int $limit The ceiling.
	 * @return void
	 */
	private function limitDetectionTo( $limit ) {
		add_filter(
			'send4_detection_limit',
			static function () use ( $limit ) {
				return $limit;
			}
		);

		Send4_Adapters::forget_forms();
	}

	/**
	 * The failure as it was reported: 101 forms, 100 listed.
	 *
	 * @return void
	 */
	public function test_contact_form_7_forms_past_the_first_hundred_are_detected() {
		$ids = array();

		for ( $i = 1; $i <= 101; $i++ ) {
			$ids[] = (string) Send4_Cf7_Factory::create( 'Form ' . $i )->id();
		}

		$detected = wp_list_pluck( ( new Send4_Adapter_Cf7() )->detect_forms(), 'id' );

		$this->assertCount( 101, $detected );
		$this->assertSame( $ids, $detected, 'Every form, once, oldest first.' );
	}

	/**
	 * The same for WPForms, which pages through a list ordered by title: forms
	 * that share a title must not be able to change places between two pages.
	 *
	 * @return void
	 */
	public function test_wpforms_forms_past_the_first_hundred_are_detected() {
		$ids = array();

		for ( $i = 1; $i <= 101; $i++ ) {
			$ids[] = (string) self::factory()->post->create(
				array(
					'post_type'   => 'wpforms',
					'post_status' => 'publish',
					'post_title'  => 'Contact us',
				)
			);
		}

		$detected = wp_list_pluck( ( new Send4_Adapter_Wpforms() )->detect_forms(), 'id' );

		sort( $ids );
		sort( $detected );

		$this->assertSame( $ids, $detected, 'Every form, once.' );
	}

	/**
	 * The ceiling exists so a site with thousands of forms does not load them
	 * all on every admin screen. A form past it is not listed, and is still
	 * found by key: that is what lets its mapping screen open, its settings
	 * save and `wp send4 enable` work.
	 *
	 * @return void
	 */
	public function test_a_form_past_the_ceiling_is_still_found_by_key() {
		$cf7 = array(
			Send4_Cf7_Factory::create( 'First' )->id(),
			Send4_Cf7_Factory::create( 'Second' )->id(),
			Send4_Cf7_Factory::create( 'Third' )->id(),
		);
		$wpf = array(
			$this->wpform( 'Alpha' ),
			$this->wpform( 'Beta' ),
			$this->wpform( 'Gamma' ),
		);

		$this->limitDetectionTo( 2 );

		$listed = wp_list_pluck( Send4_Adapters::detect_forms(), 'key' );

		$this->assertContains( 'contact-form-7:' . $cf7[1], $listed );
		$this->assertNotContains( 'contact-form-7:' . $cf7[2], $listed );
		$this->assertContains( 'wpforms:' . $wpf[1], $listed );
		$this->assertNotContains( 'wpforms:' . $wpf[2], $listed );

		$third = Send4_Adapters::find_form( 'contact-form-7:' . $cf7[2] );

		$this->assertIsArray( $third, 'A form past the ceiling is not a deleted form.' );
		$this->assertSame( 'Third', $third['title'] );
		$this->assertSame( 'Contact Form 7', $third['adapter_name'] );
		$this->assertContains( 'your-phone', wp_list_pluck( $third['fields'], 'id' ) );

		$gamma = Send4_Adapters::find_form( 'wpforms:' . $wpf[2] );

		$this->assertIsArray( $gamma );
		$this->assertSame( 'Gamma', $gamma['title'] );
		$this->assertSame( array( '1' ), wp_list_pluck( $gamma['fields'], 'id' ) );
	}

	/**
	 * A lookup by key answers for forms only: not another post type's id, not
	 * a trashed form, and not something that is not an id at all.
	 *
	 * @return void
	 */
	public function test_a_lookup_by_key_only_answers_for_a_live_form() {
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$trashed = $this->wpform( 'Trashed' );

		wp_trash_post( $trashed );

		$this->limitDetectionTo( 1 );

		$this->assertNull( Send4_Adapters::find_form( 'contact-form-7:' . $page ) );
		$this->assertNull( Send4_Adapters::find_form( 'wpforms:' . $page ) );
		$this->assertNull( Send4_Adapters::find_form( 'wpforms:' . $trashed ) );
		$this->assertNull( Send4_Adapters::find_form( 'contact-form-7:1 OR 1=1' ) );
		$this->assertNull( Send4_Adapters::find_form( 'wpforms:' ) );
	}

	/**
	 * A form that is set up never drops off the list, whatever the ceiling:
	 * the forms list, the dashboard and what Send4 is told are all built from
	 * detection, and "this site's live form has vanished" is the one thing none
	 * of them may say by accident.
	 *
	 * @return void
	 */
	public function test_a_form_that_is_set_up_is_listed_even_past_the_ceiling() {
		Send4_Cf7_Factory::create( 'First' );
		Send4_Cf7_Factory::create( 'Second' );

		$live = Send4_Cf7_Factory::create( 'The one that delivers' )->id();

		$this->enableForm( 'contact-form-7:' . $live, array( 'phone' => 'your-phone' ), 'The one that delivers' );
		$this->limitDetectionTo( 1 );

		$this->assertContains( 'contact-form-7:' . $live, wp_list_pluck( Send4_Adapters::detect_forms(), 'key' ) );

		$reported = wp_list_pluck( Send4_Form_Descriptors::build(), 'enabled', 'form_key' );

		$this->assertArrayHasKey( 'contact-form-7:' . $live, $reported );
		$this->assertTrue( $reported[ 'contact-form-7:' . $live ] );
	}

	/**
	 * `wp send4 enable` goes through the same lookup, so the command line can
	 * set up a form the list does not reach.
	 *
	 * @return void
	 */
	public function test_the_command_line_can_enable_a_form_past_the_ceiling() {
		Send4_Cf7_Factory::create( 'First' );

		$second = Send4_Cf7_Factory::create( 'Second' )->id();

		$this->limitDetectionTo( 1 );
		$this->http->fallback( Send4_Http_Recorder::response( 200, array( 'access_token' => 't', 'expires_in' => 3600 ) ) );

		WP_CLI::$output = array();

		( new Send4_Cli() )->enable( array( 'contact-form-7:' . $second ), array( 'phone' => 'your-phone' ) );

		$this->assertTrue( Send4_Settings::form_is_active( 'contact-form-7:' . $second ) );
	}

	/**
	 * Send4 takes 200 forms and refuses the whole list at 201, so a bigger site
	 * is cut to fit. What is cut must not be a form that delivers: those go
	 * first, and the rest keep the order they had.
	 *
	 * @return void
	 */
	public function test_when_send4_cannot_take_every_form_the_live_ones_go_first() {
		$forms = array();

		for ( $i = 1; $i <= Send4_Form_Descriptors::MAX_FORMS + 5; $i++ ) {
			$forms[] = array(
				'key'    => 'many-forms:' . $i,
				'id'     => (string) $i,
				'title'  => 'Form ' . $i,
				'fields' => array(
					array(
						'id'    => 'phone',
						'label' => 'Phone',
						'type'  => 'tel',
					),
				),
			);
		}

		$adapter = new class( $forms ) extends Send4_Fake_Adapter {

			/**
			 * The forms to report.
			 *
			 * @var array<int,array>
			 */
			private $forms;

			/**
			 * @param array<int,array> $forms The forms to report.
			 */
			public function __construct( array $forms ) {
				parent::__construct( 'many-forms' );

				$this->forms = $forms;
			}

			/**
			 * @return array<int,array>
			 */
			public function detect_forms() {
				return $this->forms;
			}
		};

		Send4_Adapters::reset();
		add_filter(
			'send4_form_adapters',
			static function () use ( $adapter ) {
				return array( $adapter );
			}
		);

		$last = 'many-forms:' . ( Send4_Form_Descriptors::MAX_FORMS + 5 );

		$this->enableForm( $last, array( 'phone' => 'phone' ), 'The last form' );

		$reported = wp_list_pluck( Send4_Form_Descriptors::build(), 'form_key' );

		$this->assertCount( Send4_Form_Descriptors::MAX_FORMS, $reported );
		$this->assertSame( $last, $reported[0], 'The form that delivers is never the one cut.' );
		$this->assertSame( 'many-forms:1', $reported[1], 'The rest keep their order.' );
		$this->assertSame( 'many-forms:2', $reported[2] );
	}
}
