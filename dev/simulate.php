<?php
/**
 * Fire a submission from a fixture, for the form plugins we cannot install
 * locally (Gravity Forms and Elementor Pro are both paid).
 *
 * Usage: wp eval-file dev/simulate.php gravityforms
 *        wp eval-file dev/simulate.php elementor
 *
 * @package Send4
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * A stand-in for ElementorPro's Form_Record.
 */
class Send4_Dev_Elementor_Record {

	/**
	 * Fixture data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Fixture data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Mirror of Form_Record::get().
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * Mirror of Form_Record::get_form_settings().
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get_form_settings( $key ) {
		return isset( $this->data['form_settings'][ $key ] ) ? $this->data['form_settings'][ $key ] : null;
	}
}

$send4_adapter  = isset( $args[0] ) ? (string) $args[0] : 'gravityforms';
$send4_fixtures = dirname( __DIR__ ) . '/tests/fixtures';

if ( 'gravityforms' === $send4_adapter ) {
	$send4_form  = require $send4_fixtures . '/gravityforms/form.php';
	$send4_entry = require $send4_fixtures . '/gravityforms/entry.php';

	$send4_submission = ( new Send4_Adapter_Gravityforms() )->capture( $send4_entry, $send4_form );
} elseif ( 'elementor' === $send4_adapter ) {
	$send4_record = require $send4_fixtures . '/elementor/record.php';

	$send4_submission = ( new Send4_Adapter_Elementor() )->capture( new Send4_Dev_Elementor_Record( $send4_record ) );
} elseif ( 'wpforms' === $send4_adapter ) {
	$send4_fixture = require $send4_fixtures . '/wpforms/submission.php';

	$send4_submission = ( new Send4_Adapter_Wpforms() )->capture(
		$send4_fixture['fields'],
		$send4_fixture['form_data'],
		$send4_fixture['entry_id']
	);
} else {
	WP_CLI::error( 'Unknown adapter. Use gravityforms, elementor or wpforms.' );
}

if ( ! $send4_submission instanceof Send4_Submission ) {
	WP_CLI::error( 'The adapter did not produce a submission.' );
}

WP_CLI::log( 'Form key:  ' . $send4_submission->form_key );
WP_CLI::log( 'Entry id:  ' . ( null === $send4_submission->entry_id ? '(none)' : $send4_submission->entry_id ) );

$send4_state = Send4_Settings::form( $send4_submission->form_key );

if ( '' === $send4_state['mapping']['phone'] ) {
	// Nothing has mapped this fixture form, so show what the envelope would look
	// like with the obvious mapping rather than refusing to do anything useful.
	$send4_guess = array(
		'phone' => 'gravityforms' === $send4_adapter ? '2' : 'phone',
		'name'  => 'gravityforms' === $send4_adapter ? '1' : 'name',
		'email' => 'gravityforms' === $send4_adapter ? '3' : 'email',
	);

	if ( 'wpforms' === $send4_adapter ) {
		$send4_guess = array(
			'phone' => '2',
			'name'  => '0',
			'email' => '1',
		);
	}

	$send4_envelope = Send4_Normalizer::build(
		$send4_submission,
		$send4_guess,
		Send4_Submission_Id::generate( $send4_submission )
	);

	WP_CLI::warning( 'This form is not enabled, so nothing was sent. The envelope would be:' );
	WP_CLI::log( wp_json_encode( $send4_envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

	exit( 0 );
}

$send4_result = Send4_Delivery::capture( $send4_submission );

if ( null === $send4_result ) {
	WP_CLI::warning( 'Nothing was sent. Check `wp send4 status`.' );

	exit( 0 );
}

WP_CLI::success( sprintf( 'Outcome: %s (HTTP %d) %s', $send4_result->outcome, $send4_result->code, $send4_result->message ) );
