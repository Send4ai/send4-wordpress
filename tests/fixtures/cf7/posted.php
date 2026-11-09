<?php
/**
 * What WPCF7_Submission::get_posted_data() returns for the seeded test form.
 *
 * Contact Form 7 keys posted data by tag name and hands multi-value tags back
 * as arrays; it stores no entry, which is why its submission ids are random.
 *
 * @package Send4
 */

return array(
	'your-name'    => 'Jonas',
	'your-email'   => 'j@x.lt',
	'your-phone'   => '+370 600 12345',
	'your-message' => 'I want a quote for a new roof',
	'your-city'    => 'Vilnius',
	'your-topics'  => array( 'roof', 'gutters' ),
);
