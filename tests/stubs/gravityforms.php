<?php
/**
 * Hand-written Gravity Forms stubs, for static analysis only.
 *
 * Gravity Forms is a paid plugin, so it is never installed in CI and its
 * adapter is never executed there. Without these, PHPStan reports the adapter's
 * class_exists() guards as always false and every GFAPI call as unknown.
 *
 * Only the surface Send4_Adapter_Gravityforms touches is declared.
 *
 * @see https://docs.gravityforms.com/api-functions/
 * @package Send4
 */

/**
 * Marker class the adapter checks for.
 */
class GFForms {}

/**
 * The Gravity Forms API.
 */
class GFAPI {

	/**
	 * Every form definition.
	 *
	 * Entries in the returned array can be arrays or GF_Field-carrying objects
	 * depending on the Gravity Forms version, which is why the adapter checks.
	 *
	 * @param bool $active  Only active forms.
	 * @param bool $trash   Only trashed forms.
	 * @return array<int,mixed>
	 */
	public static function get_forms( $active = true, $trash = false ) {}

	/**
	 * One form definition.
	 *
	 * @param int|string $form_id Form id.
	 * @return mixed
	 */
	public static function get_form( $form_id ) {}

	/**
	 * One entry.
	 *
	 * @param int|string $entry_id Entry id.
	 * @return mixed
	 */
	public static function get_entry( $entry_id ) {}
}
