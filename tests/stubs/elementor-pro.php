<?php
/**
 * Hand-written Elementor Pro stubs, for static analysis only.
 *
 * Elementor Pro is a paid plugin and is never installed in CI. The adapter
 * reaches it through did_action(), a defined() check and one class_exists(), so
 * this declares just the class name and the Form_Record shape it reads.
 *
 * @see https://developers.elementor.com/docs/form-actions/
 * @package Send4
 */

namespace ElementorPro;

/**
 * Marker class the adapter checks for.
 */
class Plugin {}

namespace ElementorPro\Modules\Forms\Classes;

/**
 * The record passed to elementor_pro/forms/new_record.
 */
class Form_Record {

	/**
	 * Read a property of the record, such as `fields` or `form_settings`.
	 *
	 * @param string $property Property name.
	 * @return mixed
	 */
	public function get( $property ) {}

	/**
	 * Read one setting off the form the record came from.
	 *
	 * @param string $setting Setting name.
	 * @return mixed
	 */
	public function get_form_settings( $setting ) {}
}
