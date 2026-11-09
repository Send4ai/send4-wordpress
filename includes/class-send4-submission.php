<?php
/**
 * One captured form submission, before it becomes an envelope.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shape every adapter produces: a form key, a title, and flat fields.
 */
class Send4_Submission {

	/**
	 * Routing key, e.g. contact-form-7:42.
	 *
	 * @var string
	 */
	public $form_key;

	/**
	 * Human readable form title.
	 *
	 * @var string
	 */
	public $form_name;

	/**
	 * Fields as array{id:string,label:string,value:mixed}.
	 *
	 * @var array<int,array>
	 */
	public $fields;

	/**
	 * The form plugin's own entry id, when it stores entries.
	 *
	 * Present means the submission id can be derived deterministically, so a
	 * form plugin that fires its hook twice for one entry dedupes for free.
	 *
	 * @var string|null
	 */
	public $entry_id;

	/**
	 * Constructor.
	 *
	 * @param string      $form_key  Routing key.
	 * @param string      $form_name Form title.
	 * @param array       $fields    Fields.
	 * @param string|null $entry_id  Entry id when the form plugin has one.
	 */
	public function __construct( $form_key, $form_name, array $fields, $entry_id = null ) {
		$this->form_key  = (string) $form_key;
		$this->form_name = (string) $form_name;
		$this->fields    = $fields;
		$this->entry_id  = ( null === $entry_id || '' === $entry_id ) ? null : (string) $entry_id;
	}

	/**
	 * Build a field array, normalizing whatever the form plugin handed us.
	 *
	 * @param string $id    Field id.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @return array{id:string,label:string,value:mixed}
	 */
	public static function field( $id, $label, $value ) {
		return array(
			'id'    => (string) $id,
			'label' => (string) $label,
			'value' => $value,
		);
	}
}
