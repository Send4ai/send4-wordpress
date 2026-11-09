<?php
/**
 * A Gravity Forms form definition, in the shape gform_after_submission receives.
 *
 * Composite fields (name) carry `inputs` whose ids are the entry keys, which is
 * the detail an adapter written from memory always gets wrong.
 *
 * @see https://docs.gravityforms.com/form-object/
 * @package Send4
 */

return array(
	'id'     => '7',
	'title'  => 'Roof quote',
	'fields' => array(
		array(
			'id'     => 1,
			'label'  => 'Name',
			'type'   => 'name',
			'inputs' => array(
				array(
					'id'    => '1.3',
					'label' => 'First',
				),
				array(
					'id'    => '1.6',
					'label' => 'Last',
				),
			),
		),
		array(
			'id'    => 2,
			'label' => 'Phone',
			'type'  => 'phone',
		),
		array(
			'id'    => 3,
			'label' => 'Email',
			'type'  => 'email',
		),
		array(
			'id'    => 4,
			'label' => 'What do you need?',
			'type'  => 'textarea',
		),
		array(
			'id'    => 5,
			'label' => 'City',
			'type'  => 'text',
		),
		array(
			'id'    => 6,
			'label' => 'Your details',
			'type'  => 'section',
		),
	),
);
