<?php
/**
 * The optional contract for an adapter that can look up one form directly.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * An adapter that can resolve a single form by its id without enumerating
 * every form it knows (#0697).
 *
 * Detection stops at a ceiling (Send4_Adapters::detection_limit() forms for
 * Contact Form 7 and WPForms, 100 pages for Elementor), so on a larger site the
 * form an operator is editing may simply not be in it, and the mapping screen
 * would call it deleted. An adapter that implements this is asked for that one
 * form instead, and a form that is set up is added back to the list the same way.
 *
 * A separate interface rather than a method on Send4_Form_Adapter, for the
 * reason Send4_Validating_Adapter gives: that interface is public through the
 * send4_form_adapters filter, and growing it would fatal third-party adapters.
 */
interface Send4_Findable_Adapter {

	/**
	 * One form, shaped exactly like an entry of detect_forms().
	 *
	 * @param string $form_id The adapter's own id for the form: the part of the
	 *                        form key after "<slug>:".
	 * @return array|null Null when the form does not exist.
	 */
	public function find_form( $form_id );
}
