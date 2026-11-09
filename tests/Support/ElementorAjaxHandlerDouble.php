<?php
/**
 * A stand-in for Elementor Pro's form Ajax_Handler.
 *
 * Elementor Pro is paid, so the validation binding is exercised against this.
 * It mirrors the slice of ElementorPro\Modules\Forms\Classes\Ajax_Handler that
 * elementor_pro/forms/validation listeners use: the public $errors map and
 * add_error().
 *
 * Not a marker class — the adapter never checks for it by name — so the
 * deliberate late loading ElementorProDouble needs does not apply, and it is
 * required alongside Send4_Elementor_Record_Double.
 *
 * @package Send4
 */

/**
 * Mirror of ElementorPro\Modules\Forms\Classes\Ajax_Handler.
 */
class Send4_Elementor_Ajax_Handler_Double {

	/**
	 * Field errors, keyed by field id.
	 *
	 * @var array<string,string>
	 */
	public $errors = array();

	/**
	 * Mirror of Ajax_Handler::add_error().
	 *
	 * @param string $field   Field id.
	 * @param string $message Message.
	 * @return $this
	 */
	public function add_error( $field, $message = '' ) {
		$this->errors[ $field ] = $message;

		return $this;
	}
}
