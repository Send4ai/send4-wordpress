<?php
/**
 * A form adapter that exists only in the test suite.
 *
 * The send4_form_adapters filter is the documented way a third-party plugin
 * adds support for a form plugin Send4 does not ship an adapter for. Testing it
 * needs an adapter that is not one of the four, and that can say whether it was
 * actually asked to hook itself up.
 *
 * @package Send4
 */

/**
 * Implements the whole Send4_Form_Adapter contract and records the calls.
 */
class Send4_Fake_Adapter implements Send4_Form_Adapter {

	/**
	 * Adapter slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * What is_available() should answer.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * The callback register_hooks() was handed, if it was called.
	 *
	 * @var callable|null
	 */
	public $on_submit = null;

	/**
	 * How many times register_hooks() was called.
	 *
	 * @var int
	 */
	public $registrations = 0;

	/**
	 * How many times detect_forms() was called.
	 *
	 * @var int
	 */
	public $detections = 0;

	/**
	 * Constructor.
	 *
	 * @param string $slug      Adapter slug.
	 * @param bool   $available What is_available() should answer.
	 */
	public function __construct( $slug = 'fake-forms', $available = true ) {
		$this->slug      = $slug;
		$this->available = (bool) $available;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name() {
		return 'Fake Forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,array>
	 */
	public function detect_forms() {
		++$this->detections;

		return array(
			array(
				'key'    => $this->slug . ':1',
				'id'     => '1',
				'title'  => 'Fake form',
				'fields' => array(
					array(
						'id'    => 'phone',
						'label' => 'Phone',
						'type'  => 'tel',
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param callable $on_submit Receives a Send4_Submission.
	 * @return void
	 */
	public function register_hooks( callable $on_submit ) {
		$this->on_submit = $on_submit;
		++$this->registrations;
	}

	/**
	 * Hand a submission to whatever register_hooks() was given.
	 *
	 * @param Send4_Submission $submission Submission.
	 * @return void
	 */
	public function submit( Send4_Submission $submission ) {
		call_user_func( $this->on_submit, $submission );
	}
}
