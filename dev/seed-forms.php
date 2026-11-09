<?php
/**
 * Create the forms the development stack uses.
 *
 * Run with: wp eval-file dev/seed-forms.php
 * Prints the Contact Form 7 form id on the last line so shell scripts can use it.
 *
 * @package Send4
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Create (or find) a Contact Form 7 form.
 *
 * demo_mode keeps CF7 from trying to send mail on a container with no MTA,
 * while still firing wpcf7_before_send_mail — which is the hook the plugin uses.
 *
 * @param string $title Form title, which is also what makes it findable again.
 * @param string $body  Contact Form 7 form markup.
 * @return int Form id, or 0 when Contact Form 7 is not active.
 */
function send4_dev_seed_cf7_form( $title, $body ) {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return 0;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'wpcf7_contact_form',
			'title'          => $title,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		return (int) $existing[0];
	}

	$form = WPCF7_ContactForm::get_template( array( 'title' => $title ) );
	$form->set_properties(
		array(
			'form'                => $body,
			'additional_settings' => "demo_mode: on\n",
		)
	);

	$id = $form->save();

	WP_CLI::log( sprintf( 'Created Contact Form 7 form "%s" (id %d).', $title, $id ) );

	return (int) $id;
}

/**
 * The plain form: one tag per contact detail, nothing to trip over.
 *
 * @return int Form id, or 0.
 */
function send4_dev_seed_cf7() {
	$body = <<<'FORM'
<label>Your name
	[text* your-name] </label>

<label>Your email
	[email your-email] </label>

<label>Your phone
	[tel* your-phone] </label>

<label>What do you need?
	[textarea your-message] </label>

<label>City
	[text your-city] </label>

[submit "Send"]
FORM;

	return send4_dev_seed_cf7_form( 'Send4 test form', $body );
}

/**
 * A form the way a real site builds one.
 *
 * It is here for the parts the plain form has none of: theme markup wrapped
 * around the tags, names with their own delimiters, a checkbox and three
 * acceptance tags whose choices the form descriptors have to carry through to
 * Send4, and a raw <button> instead of a [submit] tag.
 *
 * @return int Form id, or 0.
 */
function send4_dev_seed_cf7_choices() {
	$body = <<<'FORM'
<div class="theme__contact-form">
	<div class="contact-form__row row__two-columns">
		<div class="contact-form__form-field">
			<label>Full name</label>
			[text* user-info__name-and-surname placeholder "Your full name"]
		</div>

		<div class="contact-form__form-field">
			<label>Email address</label>
			[email* user-info__email placeholder "Your email address"]
		</div>
	</div>

	<div class="contact-form__row row__two-columns">
		<div class="contact-form__form-field">
			<label>Phone number</label>
			[tel* user-info__phone]
		</div>
	</div>

	<div class="contact-form__row">
		<div class="contact-form__form-field">
			<label>Products you are interested in</label>
			[checkbox* user-info__products "Home installation" "Business installation" "Upgrade" "Spare parts" "Maintenance"]
		</div>
	</div>

	<div class="contact-form__row">
		<div class="contact-form__form-field">
			<label>Comments</label>
			[textarea user-info__message maxlength:1000 placeholder "Anything else we should know"]
		</div>
	</div>

	<div class="contact-form__row">
		<div class="w-btn-wrapper">
			<p class="text-initial">[acceptance privacy-policy] I agree to the <a href="https://example.com/privacy" target="_blank">privacy policy</a>
 [/acceptance]</p>
			<p class="text-initial">[acceptance marketing-agreement optional] I agree to receive newsletters and other marketing messages. [/acceptance]</p>
			<p class="text-initial">[acceptance contact-agreement] I agree to be contacted by phone about this request. [/acceptance]</p>
			<button class="w-btn us-btn-style_1 wpcf7-submit theme__button icon_atright"><span class="w-btn-label">Get an offer</span><i class="fal fa-arrow-right"></i></button>
		</div>
	</div>
</div>
FORM;

	return send4_dev_seed_cf7_form( 'Send4 test form (choices)', $body );
}

/**
 * Publish a page that renders a form.
 *
 * @param int    $form_id    Contact Form 7 form id.
 * @param string $form_title Form title, for the shortcode's title attribute.
 * @param string $slug       Page slug.
 * @param string $page_title Page title.
 * @return void
 */
function send4_dev_seed_page( $form_id, $form_title, $slug, $page_title ) {
	if ( ! $form_id ) {
		return;
	}

	send4_dev_seed_page_content( sprintf( '[contact-form-7 id="%d" title="%s"]', $form_id, $form_title ), $slug, $page_title );
}

/**
 * Publish a page with the given content, or bring an existing one up to date.
 *
 * @param string $content    Page content, shortcodes and all.
 * @param string $slug       Page slug.
 * @param string $page_title Page title.
 * @return void
 */
function send4_dev_seed_page_content( $content, $slug, $page_title ) {
	$existing = get_page_by_path( $slug );

	if ( $existing ) {
		wp_update_post(
			array(
				'ID'           => $existing->ID,
				'post_content' => $content,
			)
		);

		return;
	}

	wp_insert_post(
		array(
			'post_title'   => $page_title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => $content,
		)
	);

	WP_CLI::log( sprintf( 'Published /%s/.', $slug ) );
}

/**
 * Create a WPForms form with the same shape, when WPForms is active.
 *
 * @return int Form id, or 0.
 */
function send4_dev_seed_wpforms() {
	if ( ! function_exists( 'wpforms' ) ) {
		return 0;
	}

	$title    = 'Send4 test form (WPForms)';
	$existing = get_posts(
		array(
			'post_type'      => 'wpforms',
			'title'          => $title,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		return (int) $existing[0];
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'wpforms',
		)
	);

	if ( ! $post_id ) {
		return 0;
	}

	$definition = array(
		'id'       => (string) $post_id,
		'field_id' => 5,
		'fields'   => array(
			'0' => array(
				'id'    => '0',
				'type'  => 'name',
				'label' => 'Name',
				'format' => 'simple',
			),
			'1' => array(
				'id'    => '1',
				'type'  => 'email',
				'label' => 'Email',
			),
			// A text field, not `phone`: on WPForms Lite the Phone field is a
			// disabled Pro stub that renders nothing, so a Lite seed built with
			// it had no phone input at all. Lite sites collect numbers exactly
			// like this. (Pro's Smart phone field brings its own intl-tel-input,
			// which is the collision phone-field.js refuses to double up on.)
			'2' => array(
				'id'    => '2',
				'type'  => 'text',
				'label' => 'Phone',
			),
			'3' => array(
				'id'    => '3',
				'type'  => 'textarea',
				'label' => 'What do you need?',
			),
			'4' => array(
				'id'    => '4',
				'type'  => 'text',
				'label' => 'City',
			),
		),
		'settings' => array(
			'form_title' => $title,
			'submit_text' => 'Send',
		),
	);

	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => wpforms_encode( $definition ),
		)
	);

	WP_CLI::log( sprintf( 'Created WPForms form "%s" (id %d).', $title, $post_id ) );

	return (int) $post_id;
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	WP_CLI::warning( 'Contact Form 7 is not active; skipping.' );
}

$send4_cf7_id = send4_dev_seed_cf7();
send4_dev_seed_page( $send4_cf7_id, 'Send4 test form', 'contact', 'Contact' );

$send4_cf7_choices_id = send4_dev_seed_cf7_choices();
send4_dev_seed_page( $send4_cf7_choices_id, 'Send4 test form (choices)', 'contact-choices', 'Contact (choices)' );

$send4_wpforms_id = send4_dev_seed_wpforms();

// WPForms had a form but never a page, so its front end was never exercised.
if ( $send4_wpforms_id ) {
	send4_dev_seed_page_content( sprintf( '[wpforms id="%d"]', $send4_wpforms_id ), 'contact-wpforms', 'Contact (WPForms)' );
}

// The phone picker's awkward cases on one page (#0688): the same form twice,
// and two different form plugins, so one library load serves three pickers.
if ( $send4_cf7_choices_id && $send4_wpforms_id ) {
	send4_dev_seed_page_content(
		sprintf(
			"[contact-form-7 id=\"%1\$d\" title=\"Send4 test form (choices)\"]\n\n[contact-form-7 id=\"%1\$d\" title=\"Send4 test form (choices)\"]\n\n[wpforms id=\"%2\$d\"]",
			$send4_cf7_choices_id,
			$send4_wpforms_id
		),
		'phone-picker',
		'Phone picker'
	);
}

// Last line is the CF7 id so `FORM_ID=$(wp eval-file ...)` style callers work.
WP_CLI::log( (string) $send4_cf7_id );
