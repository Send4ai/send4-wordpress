<?php
/**
 * A real Contact Form 7 submit, end to end.
 *
 * @package Send4
 */

/**
 * Proves the adapter is hooked to something that actually fires.
 *
 * wpcf7_before_send_mail is used rather than wpcf7_mail_sent precisely because
 * it still runs when the form skips mail — demo mode, a broken wp_mail, a site
 * with no MTA. A unit test cannot show that; this one can.
 */
class Send4_Cf7_Submit_Test extends Send4_Test_Case {

	/**
	 * Clear Contact Form 7's submission singleton.
	 *
	 * WPCF7_Submission keeps a private static instance and offers no way to
	 * reset it, so a second submit in the same process returns null from
	 * get_instance() and fatals inside submit(). Every test here therefore
	 * starts by emptying it through reflection.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$_POST    = array();
		$_REQUEST = array();

		// Contact Form 7 marks a submission with an unnaturally short
		// User-Agent as spam, and PHPUnit's request has none at all.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Send4 test suite)';

		$instance = new ReflectionProperty( 'WPCF7_Submission', 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Leave no posted data behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		unset( $_SERVER['HTTP_USER_AGENT'] );

		parent::tear_down();
	}

	/**
	 * Put the fixture's answers where Contact Form 7 reads them.
	 *
	 * @param WPCF7_ContactForm $form  The form.
	 * @param array             $extra Values to override.
	 * @return void
	 */
	private function postFixture( $form, array $extra = array() ) {
		$posted = array_merge( $this->fixture( 'cf7/posted.php' ), $extra );

		$posted['_wpcf7']         = $form->id();
		$posted['_wpcf7_version'] = WPCF7_VERSION;
		$posted['_wpcf7_unit_tag'] = 'wpcf7-f' . $form->id() . '-o1';

		$_POST    = wp_slash( $posted );
		$_REQUEST = $_POST;
	}

	/**
	 * The submission reaches Send4 exactly once, without any mail being sent.
	 *
	 * @return void
	 */
	public function test_a_demo_mode_submit_posts_the_lead_exactly_once() {
		$form = Send4_Cf7_Factory::create();

		$this->enableForm(
			'contact-form-7:' . $form->id(),
			array(
				'phone' => 'your-phone',
				'name'  => 'your-name',
				'email' => 'your-email',
			),
			'Send4 test form'
		);

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$this->postFixture( $form );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'], 'Contact Form 7 did not accept the submission.' );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions );
		$this->assertSame( 'POST', $submissions[0]['method'] );

		$envelope = $submissions[0]['body'];

		$this->assertSame( 'contact-form-7:' . $form->id(), $envelope['form']['key'] );
		$this->assertSame( 'Send4 test form', $envelope['form']['name'] );
		$this->assertSame(
			array(
				'name'  => 'Jonas',
				'email' => 'j@x.lt',
				'phone' => '+37060012345',
			),
			$envelope['lead']
		);
		$this->assertSame( 'Vilnius', $envelope['data']['your_city'] );
		$this->assertSame( 'roof, gutters', $envelope['data']['your_topics'] );

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'delivered', Send4_Settings::form( 'contact-form-7:' . $form->id() )['last_status'] );
	}

	/**
	 * A form the operator has not enabled submits without touching the network.
	 *
	 * @return void
	 */
	public function test_a_submit_on_a_form_nobody_enabled_sends_nothing() {
		$form = Send4_Cf7_Factory::create();

		$this->postFixture( $form );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * A submission Contact Form 7 rejects never reaches Send4: the hook runs
	 * after validation, which is the reason it was chosen.
	 *
	 * @return void
	 */
	public function test_a_validation_failure_never_reaches_send4() {
		$form = Send4_Cf7_Factory::create();

		$this->enableForm(
			'contact-form-7:' . $form->id(),
			array( 'phone' => 'your-phone' ),
			'Send4 test form'
		);

		// your-phone is [tel*], so an empty one fails validation.
		$this->postFixture( $form, array( 'your-phone' => '' ) );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'validation_failed', $result['status'] );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * A Send4 outage must not break somebody's contact form: the visitor still
	 * gets the success message, and the lead is queued.
	 *
	 * @return void
	 */
	public function test_a_send4_outage_does_not_break_the_form() {
		$form = Send4_Cf7_Factory::create();

		$this->enableForm(
			'contact-form-7:' . $form->id(),
			array(
				'phone' => 'your-phone',
				'name'  => 'your-name',
				'email' => 'your-email',
			),
			'Send4 test form'
		);

		$this->http->queueToken();
		$this->http->queueTimeout();

		$this->postFixture( $form );

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );
		$this->assertSame( Send4_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
	}

	/**
	 * A later wpcf7_before_send_mail listener — an OTP or blocklist add-on —
	 * that aborts the submission makes Contact Form 7 tell the visitor it
	 * failed. Send4 must not have the lead either (#0698).
	 *
	 * @return void
	 */
	public function test_a_submission_another_listener_aborts_never_reaches_send4() {
		$form = Send4_Cf7_Factory::create();

		$this->enableForm(
			'contact-form-7:' . $form->id(),
			array( 'phone' => 'your-phone' ),
			'Send4 test form'
		);

		$abort = static function ( $contact_form, &$abort ) {
			unset( $contact_form );
			$abort = true;
		};

		// Later than CF7's own default of 10, as a third-party add-on would be.
		add_action( 'wpcf7_before_send_mail', $abort, 20, 2 );

		$this->postFixture( $form );

		$result = $form->submit( array( 'skip_mail' => true ) );

		remove_action( 'wpcf7_before_send_mail', $abort, 20 );

		$this->assertSame( 'aborted', $result['status'], 'The listener did not abort the submission.' );
		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * A piped choice arrives as the half after the bar, which is the half the
	 * adapter reports as the option — so a condition picked from the reported
	 * options matches what the submission carries.
	 *
	 * @return void
	 */
	public function test_a_piped_choice_arrives_as_the_option_the_adapter_reported() {
		$form = Send4_Cf7_Factory::create(
			'Send4 test form',
			'[tel* your-phone]
[select dept "Sales|sales@example.com" "Support|support@example.com"]
[submit "Send"]'
		);

		$this->enableForm(
			'contact-form-7:' . $form->id(),
			array( 'phone' => 'your-phone' ),
			'Send4 test form'
		);

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$_POST    = wp_slash(
			array(
				'your-phone'       => '+370 600 12345',
				'dept'             => 'Sales',
				'_wpcf7'           => $form->id(),
				'_wpcf7_version'   => WPCF7_VERSION,
				'_wpcf7_unit_tag'  => 'wpcf7-f' . $form->id() . '-o1',
			)
		);
		$_REQUEST = $_POST;

		$result = $form->submit( array( 'skip_mail' => true ) );

		$this->assertSame( 'mail_sent', $result['status'] );

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions );
		$this->assertSame( 'sales@example.com', $submissions[0]['body']['data']['dept'] );

		$options = array();

		foreach ( ( new Send4_Adapter_Cf7() )->detect_forms() as $detected ) {
			foreach ( $detected['fields'] as $field ) {
				if ( 'dept' === $field['id'] ) {
					$options = $field['options'];
				}
			}
		}

		$this->assertContains( $submissions[0]['body']['data']['dept'], $options );
	}
}
