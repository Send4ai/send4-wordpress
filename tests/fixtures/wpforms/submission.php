<?php
/**
 * WPForms: the processed $fields array and the $form_data definition that
 * wpforms_process_complete receives.
 *
 * @see https://wpforms.com/developers/wpforms_process_complete/
 * @package Send4
 */

return array(
	'form_data' => array(
		'id'       => '31',
		'fields'   => array(
			'0' => array(
				'id'    => '0',
				'type'  => 'name',
				'label' => 'Name',
			),
			'1' => array(
				'id'    => '1',
				'type'  => 'email',
				'label' => 'Email',
			),
			'2' => array(
				'id'    => '2',
				'type'  => 'phone',
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
			'5' => array(
				'id'   => '5',
				'type' => 'divider',
			),
		),
		'settings' => array(
			'form_title' => 'Roof quote',
		),
	),
	'fields'    => array(
		0 => array(
			'name'  => 'Name',
			'value' => 'Jonas Jonaitis',
			'id'    => 0,
			'type'  => 'name',
		),
		1 => array(
			'name'  => 'Email',
			'value' => 'j@x.lt',
			'id'    => 1,
			'type'  => 'email',
		),
		2 => array(
			'name'  => 'Phone',
			'value' => '+370 600 12345',
			'id'    => 2,
			'type'  => 'phone',
		),
		3 => array(
			'name'  => 'What do you need?',
			'value' => 'I want a quote for a new roof',
			'id'    => 3,
			'type'  => 'textarea',
		),
		4 => array(
			'name'  => 'City',
			'value' => 'Vilnius',
			'id'    => 4,
			'type'  => 'text',
		),
	),
	'entry_id'  => 8171,
);
