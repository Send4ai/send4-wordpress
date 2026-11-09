<?php
/**
 * What ElementorPro\Modules\Forms\Classes\Form_Record::get( 'fields' ) returns,
 * plus the form settings the adapter reads for the key and title.
 *
 * @see https://developers.elementor.com/docs/form-actions/
 * @package Send4
 */

return array(
	'form_settings' => array(
		'id'        => 'a1b2c3d',
		'form_name' => 'Contact',
	),
	'fields'        => array(
		'name'    => array(
			'id'        => 'name',
			'type'      => 'text',
			'title'     => 'Name',
			'value'     => 'Jonas',
			'raw_value' => 'Jonas',
		),
		'phone'   => array(
			'id'        => 'phone',
			'type'      => 'tel',
			'title'     => 'Phone',
			'value'     => '+370 600 12345',
			'raw_value' => '+370 600 12345',
		),
		'email'   => array(
			'id'        => 'email',
			'type'      => 'email',
			'title'     => 'Email',
			'value'     => 'j@x.lt',
			'raw_value' => 'j@x.lt',
		),
		'message' => array(
			'id'        => 'message',
			'type'      => 'textarea',
			'title'     => 'What do you need?',
			'value'     => 'I want a quote for a new roof',
			'raw_value' => 'I want a quote for a new roof',
		),
		'city'    => array(
			'id'        => 'city',
			'type'      => 'text',
			'title'     => 'City',
			'value'     => 'Vilnius',
			'raw_value' => 'Vilnius',
		),
		'consent' => array(
			'id'        => 'consent',
			'type'      => 'acceptance',
			'title'     => 'I agree to be contacted',
			'value'     => 'on',
			'raw_value' => 'on',
		),
	),
);
