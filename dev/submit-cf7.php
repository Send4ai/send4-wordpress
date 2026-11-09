<?php
/**
 * Submit the seeded Contact Form 7 form through CF7's own pipeline, so the
 * plugin is exercised by the real hook rather than a hand-built call.
 *
 * Usage: wp eval-file dev/submit-cf7.php [phone]
 *
 * The optional phone replaces the default +370 600 12345, so a form with the
 * phone check on can be driven down its refusal path (#0688):
 *
 *     wp eval-file dev/submit-cf7.php 060012345
 *
 * @package Send4
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	WP_CLI::error( 'Contact Form 7 is not active.' );
}

// Contact Form 7 creates its own "Contact form 1" on activation, so taking the
// first form submits the wrong one and then reports that the plugin logged
// nothing. Prefer a form that is actually enabled for Send4.
$send4_forms = WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) );

if ( empty( $send4_forms ) ) {
	WP_CLI::error( 'No Contact Form 7 form found. Run `make install` first.' );
}

$send4_form = null;

foreach ( $send4_forms as $send4_candidate ) {
	if ( Send4_Settings::form_is_active( 'contact-form-7:' . $send4_candidate->id() ) ) {
		$send4_form = $send4_candidate;
		break;
	}
}

if ( null === $send4_form ) {
	foreach ( $send4_forms as $send4_candidate ) {
		if ( 'Send4 test form' === $send4_candidate->title() ) {
			$send4_form = $send4_candidate;
			break;
		}
	}
}

if ( null === $send4_form ) {
	WP_CLI::error( 'No form is enabled for Send4. Run `wp send4 forms` to see what exists.' );
}

WP_CLI::log( sprintf( 'Submitting "%s" (contact-form-7:%d).', $send4_form->title(), $send4_form->id() ) );

$_POST = array(
	'_wpcf7'               => $send4_form->id(),
	'_wpcf7_version'       => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '5.9',
	'_wpcf7_locale'        => 'en_US',
	'_wpcf7_unit_tag'      => 'wpcf7-f' . $send4_form->id() . '-o1',
	'_wpcf7_container_post' => '0',
	'your-name'            => 'Jonas',
	'your-email'           => 'jonas@example.lt',
	'your-phone'           => isset( $args[0] ) ? (string) $args[0] : '+370 600 12345',
	'your-message'         => 'I want a quote for a new roof',
	'your-city'            => 'Vilnius',
);

$send4_result = $send4_form->submit();

WP_CLI::log( 'Contact Form 7 status: ' . ( isset( $send4_result['status'] ) ? $send4_result['status'] : 'unknown' ) );
WP_CLI::log( 'Message: ' . ( isset( $send4_result['message'] ) ? $send4_result['message'] : '' ) );

if ( ! empty( $send4_result['invalid_fields'] ) ) {
	foreach ( $send4_result['invalid_fields'] as $send4_name => $send4_invalid ) {
		WP_CLI::log( sprintf( '  %s: %s', $send4_name, isset( $send4_invalid['reason'] ) ? $send4_invalid['reason'] : '' ) );
	}
}

$send4_entries = Send4_Logger::entries();

if ( empty( $send4_entries ) ) {
	WP_CLI::warning( 'The plugin logged nothing. Is the form enabled? Run `wp send4 forms`.' );

	exit( 0 );
}

WP_CLI::log( '' );
WP_CLI::log( 'Most recent Send4 activity:' );

foreach ( array_slice( $send4_entries, 0, 3 ) as $send4_entry ) {
	WP_CLI::log(
		sprintf(
			'  [%s] %s %s',
			$send4_entry['level'],
			$send4_entry['message'],
			wp_json_encode( $send4_entry['context'] )
		)
	);
}
