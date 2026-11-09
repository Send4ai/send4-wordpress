<?php
/**
 * Builds real Contact Form 7 forms for the suite.
 *
 * @package Send4
 */

/**
 * Contact Form 7 has no factory of its own, and the adapter reads form tags off
 * a real WPCF7_ContactForm, so the tests build one rather than mock it.
 */
class Send4_Cf7_Factory {

	/**
	 * The form body the fixtures describe: the seeded development form plus a
	 * checkbox, which is the multi-value case the normalizer has to join.
	 */
	const BODY = '<label>Your name [text* your-name]</label>
<label>Your email [email your-email]</label>
<label>Your phone [tel* your-phone]</label>
<label>What do you need? [textarea your-message]</label>
<label>City [text your-city]</label>
<label>Topics [checkbox your-topics "roof" "gutters"]</label>
[submit "Send"]';

	/**
	 * Create a saved Contact Form 7 form.
	 *
	 * @param string      $title Form title.
	 * @param string|null $body  Form body, defaults to self::BODY.
	 * @param string      $extra Additional settings block.
	 * @return WPCF7_ContactForm
	 */
	public static function create( $title = 'Send4 test form', $body = null, $extra = "demo_mode: on\n" ) {
		$form = WPCF7_ContactForm::get_template( array( 'title' => $title ) );

		$form->set_properties(
			array(
				'form'                => null === $body ? self::BODY : $body,
				'mail'                => array(
					'active'             => true,
					'subject'            => 'Send4 test',
					'sender'             => 'Site <wordpress@example.org>',
					'recipient'          => 'owner@example.org',
					'body'               => '[your-name] [your-phone]',
					'additional_headers' => '',
					'attachments'        => '',
					'use_html'           => false,
					'exclude_blank'      => false,
				),
				'additional_settings' => $extra,
			)
		);

		$form->save();

		return WPCF7_ContactForm::get_instance( $form->id() );
	}
}
