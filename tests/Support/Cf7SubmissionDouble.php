<?php
/**
 * A stand-in for WPCF7_Submission.
 *
 * @package Send4
 */

/**
 * The one method Send4_Adapter_Cf7::capture() reads a submission through.
 *
 * Contact Form 7's real submission object can only be constructed inside a live
 * submit, and it keeps a private static instance that cannot be reset, so the
 * envelope tests use this instead and Cf7SubmitTest exercises the real thing.
 */
class Send4_Cf7_Submission_Double {

	/**
	 * Posted data.
	 *
	 * @var array
	 */
	private $posted;

	/**
	 * Constructor.
	 *
	 * @param array $posted Posted data keyed by tag name.
	 */
	public function __construct( array $posted ) {
		$this->posted = $posted;
	}

	/**
	 * Mirror of WPCF7_Submission::get_posted_data().
	 *
	 * @return array
	 */
	public function get_posted_data() {
		return $this->posted;
	}
}
