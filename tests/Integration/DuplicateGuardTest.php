<?php
/**
 * The same submission arriving several times in a few seconds.
 *
 * @package Send4
 */

/**
 * Contact Form 7 lets an impatient visitor submit five times with five clicks.
 * Send4 must hear about that lead once.
 */
class Send4_Duplicate_Guard_Test extends Send4_Test_Case {

	const FORM_KEY = 'contact-form-7:42';

	/**
	 * A Contact Form 7 style submission: no entry id, so a random submission id.
	 *
	 * @param string $phone The phone the visitor typed.
	 * @return Send4_Submission
	 */
	private function submission( $phone = '+37060012345' ) {
		$this->enableForm(
			self::FORM_KEY,
			array(
				'phone' => 'your-tel',
				'name'  => 'your-name',
				'email' => '',
			)
		);

		return new Send4_Submission(
			self::FORM_KEY,
			'Kontaktai',
			array(
				Send4_Submission::field( 'your-name', 'Vardas', 'Jonas' ),
				Send4_Submission::field( 'your-tel', 'Telefonas', $phone ),
				Send4_Submission::field( 'your-message', 'Žinutė', 'Sveiki' ),
			)
		);
	}

	/**
	 * Five clicks, one request to Send4.
	 *
	 * @return void
	 */
	public function test_an_identical_submission_moments_later_is_not_sent_again() {
		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$first = Send4_Delivery::capture( $this->submission() );
		$sent  = count( $this->http->requests() );

		$this->assertSame( Send4_Api_Result::DELIVERED, $first->outcome );

		for ( $click = 0; $click < 4; $click++ ) {
			$this->assertNull( Send4_Delivery::capture( $this->submission() ) );
		}

		$this->assertCount( $sent, $this->http->requests(), 'The repeats must not reach Send4 at all.' );
		$this->assertSame( array(), $this->queueRows() );

		$form = Send4_Settings::form( self::FORM_KEY );

		$this->assertSame( 'delivered', $form['last_status'], 'A skipped repeat must not overwrite the real outcome.' );
		$this->assertSame( 'duplicate', Send4_Logger::entries()[0]['context']['status'] );
	}

	/**
	 * Two different people inside the same minute are two leads.
	 *
	 * @return void
	 */
	public function test_a_different_answer_is_a_different_lead() {
		$this->http->queueToken();
		$this->http->queue202( true, 1 );
		$this->http->queue202( true, 2 );

		$first  = Send4_Delivery::capture( $this->submission( '+37060012345' ) );
		$second = Send4_Delivery::capture( $this->submission( '+37060054321' ) );

		$this->assertSame( Send4_Api_Result::DELIVERED, $first->outcome );
		$this->assertSame( Send4_Api_Result::DELIVERED, $second->outcome );
	}

	/**
	 * The guard is for double clicks, not for somebody who genuinely writes
	 * in again later with the same details.
	 *
	 * @return void
	 */
	public function test_the_claim_lapses_with_the_window() {
		$envelope = array(
			'submission_id' => 'a',
			'form'          => array( 'key' => self::FORM_KEY ),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);

		$this->assertTrue( Send4_Duplicate_Guard::claim( $envelope, 1000 ) );
		$this->assertFalse( Send4_Duplicate_Guard::claim( array( 'submission_id' => 'b' ) + $envelope, 1059 ) );
		$this->assertTrue( Send4_Duplicate_Guard::claim( $envelope, 1060 ) );
	}

	/**
	 * A site that wants every submission sent can switch the guard off.
	 *
	 * @return void
	 */
	public function test_a_zero_window_turns_the_guard_off() {
		add_filter( 'send4_duplicate_window', '__return_zero' );

		$envelope = array(
			'form' => array( 'key' => self::FORM_KEY ),
			'lead' => array( 'phone' => '+37060012345' ),
		);

		$this->assertTrue( Send4_Duplicate_Guard::claim( $envelope ) );
		$this->assertTrue( Send4_Duplicate_Guard::claim( $envelope ) );

		remove_filter( 'send4_duplicate_window', '__return_zero' );
	}

	/**
	 * The option name is stored in the site's database; it must not be the
	 * visitor's phone number in another form.
	 *
	 * @return void
	 */
	public function test_the_fingerprint_does_not_contain_what_the_visitor_typed() {
		$fingerprint = Send4_Duplicate_Guard::fingerprint(
			array(
				'form' => array( 'key' => self::FORM_KEY ),
				'lead' => array( 'phone' => '+37060012345' ),
			)
		);

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $fingerprint );
		$this->assertStringNotContainsString( '37060012345', $fingerprint );
	}
}
