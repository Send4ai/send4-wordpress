<?php
/**
 * The optional contract for an adapter that can refuse a submission.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * An adapter that can hook its form plugin's validation stage (#0688).
 *
 * A separate interface rather than a sixth method on Send4_Form_Adapter:
 * that interface is public through the send4_form_adapters filter, so growing
 * it would fatal every third-party adapter on upgrade. Send4_Adapters probes
 * for this with instanceof, the same way it checks for the base interface.
 */
interface Send4_Validating_Adapter {

	/**
	 * Attach to the form plugin's validation stage.
	 *
	 * The binding must ask the guard for the field before anything else and do
	 * nothing when it answers '', and must fail open: when the mapped field
	 * cannot be found in the submission, the form goes through. An error
	 * attached to a field that renders nowhere is a form the visitor can never
	 * send and never see why.
	 *
	 * @param Send4_Phone_Guard $guard Decides which field to check and what to say.
	 * @return void
	 */
	public function register_validation( Send4_Phone_Guard $guard );
}
