<?php
/**
 * A stand-in for ElementorPro's Form_Record.
 *
 * Elementor Pro is a paid plugin, so the adapter can only ever be exercised
 * against a double. This mirrors the two methods the adapter calls, and is fed
 * by the same fixture dev/simulate.php uses.
 *
 * @package Send4
 */

/**
 * Mirror of ElementorPro\Modules\Forms\Classes\Form_Record.
 */
class Send4_Elementor_Record_Double {

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
