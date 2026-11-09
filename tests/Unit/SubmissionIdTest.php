<?php
/**
 * The idempotency key Send4 dedupes on for 24 hours.
 *
 * @package Send4
 */

/**
 * Stable when the form plugin has an entry id, deliberately unstable when it
 * has not.
 */
class Send4_Submission_Id_Test extends Send4_Test_Case {

	/**
	 * A submission with, or without, an entry id.
	 *
	 * @param string|null $entry_id Entry id.
	 * @param string      $form_key Form key.
	 * @return Send4_Submission
	 */
	private function submission( $entry_id, $form_key = 'gravityforms:7' ) {
		return new Send4_Submission(
			$form_key,
			'Roof quote',
			array( Send4_Submission::field( 'phone', 'Phone', '+37060012345' ) ),
			$entry_id
		);
	}

	/**
	 * Gravity Forms and WPForms Pro: the same entry always gets the same id, so
	 * a hook that fires twice dedupes at Send4 instead of texting twice.
	 *
	 * @return void
	 */
	public function test_the_same_entry_id_gives_the_same_submission_id() {
		$first  = Send4_Submission_Id::generate( $this->submission( '512' ) );
		$second = Send4_Submission_Id::generate( $this->submission( '512' ) );

		$this->assertSame( $first, $second );
		$this->assertStringStartsWith( 'gravityforms:7:512:', $first );
	}

	/**
	 * The suffix is derived from the entry, so a different entry differs even
	 * on the same form and in the same second.
	 *
	 * @return void
	 */
	public function test_a_different_entry_id_gives_a_different_submission_id() {
		$this->assertNotSame(
			Send4_Submission_Id::generate( $this->submission( '512' ) ),
			Send4_Submission_Id::generate( $this->submission( '513' ) )
		);
	}

	/**
	 * The same entry id on a different form is a different submission.
	 *
	 * @return void
	 */
	public function test_the_form_key_is_part_of_the_suffix() {
		$this->assertNotSame(
			Send4_Submission_Id::generate( $this->submission( '512', 'gravityforms:7' ) ),
			Send4_Submission_Id::generate( $this->submission( '512', 'gravityforms:8' ) )
		);
	}

	/**
	 * Contact Form 7 and WPForms Lite store no entry. A constant id there would
	 * make Send4's dedupe window swallow every lead after the first.
	 *
	 * @dataProvider noEntryIdProvider
	 *
	 * @param string|null $entry_id What the adapter reports.
	 * @return void
	 */
	public function test_without_an_entry_id_every_submission_gets_its_own_id( $entry_id ) {
		$first  = Send4_Submission_Id::generate( $this->submission( $entry_id ), 1700000000 );
		$second = Send4_Submission_Id::generate( $this->submission( $entry_id ), 1700000000 );

		$this->assertNotSame( $first, $second );

		// Same second, same form: only the random suffix can be doing the work.
		$this->assertStringStartsWith( 'gravityforms:7:1700000000:', $first );
		$this->assertStringStartsWith( 'gravityforms:7:1700000000:', $second );
	}

	/**
	 * The two ways a form plugin says "no entry".
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public function noEntryIdProvider() {
		return array(
			'WPForms Lite reports 0'   => array( '0' ),
			'Contact Form 7 has none'  => array( null ),
		);
	}

	/**
	 * The suffix is long enough that a busy form does not collide with itself.
	 *
	 * @return void
	 */
	public function test_the_random_suffix_is_not_repeated_across_many_submissions() {
		$ids = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$ids[] = Send4_Submission_Id::generate( $this->submission( null ), 1700000000 );
		}

		$this->assertCount( 200, array_unique( $ids ) );
	}

	/**
	 * varchar(191) at Send4 and in the queue table's unique key.
	 *
	 * @dataProvider longFormKeyProvider
	 *
	 * @param string|null $entry_id Entry id.
	 * @return void
	 */
	public function test_the_id_never_exceeds_the_column_width( $entry_id ) {
		$id = Send4_Submission_Id::generate(
			$this->submission( $entry_id, 'contact-form-7:' . str_repeat( 'x', 400 ) )
		);

		$this->assertLessThanOrEqual( 191, strlen( $id ) );

		// The tail has to survive the truncation, or the id stops being unique.
		$this->assertStringEndsWith(
			':' . substr( strrchr( $id, ':' ), 1 ),
			$id
		);
		$this->assertSame( 12, strlen( substr( strrchr( $id, ':' ), 1 ) ) );
	}

	/**
	 * Both id paths, against an over-long form key.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public function longFormKeyProvider() {
		return array(
			'with an entry id'    => array( '512' ),
			'without an entry id' => array( null ),
		);
	}

	/**
	 * The secret is created once and reused, so ids stay stable across requests.
	 *
	 * @return void
	 */
	public function test_the_secret_is_generated_once_and_is_not_a_wordpress_salt() {
		delete_option( Send4_Submission_Id::SECRET_OPTION );

		$secret = Send4_Submission_Id::secret();

		$this->assertSame( 32, strlen( $secret ) );
		$this->assertSame( $secret, Send4_Submission_Id::secret() );
		$this->assertNotSame( wp_salt(), $secret );
	}
}
