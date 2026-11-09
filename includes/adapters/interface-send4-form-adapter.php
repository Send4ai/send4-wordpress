<?php
/**
 * The contract every form-plugin integration implements.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adding support for another form plugin is one class implementing this.
 */
interface Send4_Form_Adapter {

	/**
	 * Short machine name; the first segment of every form key this adapter owns.
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Human readable name of the form plugin.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Is the form plugin installed and active right now?
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Every form this plugin knows about.
	 *
	 * @return array<int,array{key:string,id:string,title:string,fields:array<int,array{id:string,label:string,type:string}>}>
	 */
	public function detect_forms();

	/**
	 * Attach to the form plugin's submission hook.
	 *
	 * The callback takes one Send4_Submission.
	 *
	 * @param callable $on_submit Receives a Send4_Submission.
	 * @return void
	 */
	public function register_hooks( callable $on_submit );
}
