<?php
/**
 * The optional contract for an adapter whose listing can stop early.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * An adapter that says when its last detect_forms() left forms out.
 *
 * The registry otherwise infers it from the count reaching
 * Send4_Adapters::detection_limit(), which only holds for an adapter that
 * stops at that many forms. Elementor stops at a number of pages instead, and
 * a hundred pages can carry far fewer forms than the limit, so its configured
 * forms past those pages were never looked up and dropped out of the list and
 * of what Send4 is told.
 *
 * A separate interface for the reason Send4_Validating_Adapter gives.
 */
interface Send4_Truncating_Adapter {

	/**
	 * Did the most recent detect_forms() stop before it had seen every form?
	 *
	 * @return bool
	 */
	public function detection_truncated();
}
