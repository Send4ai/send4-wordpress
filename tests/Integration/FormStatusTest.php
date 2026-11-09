<?php
/**
 * The last delivery's outcome is stored apart from the form's configuration.
 *
 * @package Send4
 */

/**
 * A delivery holds its copy of the settings across a blocking HTTP call — a
 * queue drain across many. When recording the outcome rewrote the whole forms
 * option from that copy, an admin who disabled a form or saved a mapping in
 * the meantime had the change silently reverted (#0696). These tests play the
 * other request by writing straight to the database, behind this request's
 * object cache, which is exactly what a second PHP process looks like.
 */
class Send4_Form_Status_Test extends Send4_Test_Case {

	const FORM_KEY = 'contact-form-7:1';
	const OTHER    = 'contact-form-7:2';

	/**
	 * Write an option the way another request would: to the database, leaving
	 * this request's cached copy stale.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	private function writeFromAnotherRequest( $name, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$name,
				maybe_serialize( $value )
			)
		);
	}

	/**
	 * An option as the database holds it now.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function storedOption( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return get_option( $name, false );
	}

	/**
	 * The reported race: the delivery read the settings, an admin disabled the
	 * form and remapped its phone, then the delivery recorded its outcome.
	 *
	 * @return void
	 */
	public function test_recording_a_delivery_keeps_an_admin_change_made_meanwhile() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => 'your-phone' ) );

		// What Send4_Delivery::capture() does first; the option is now in this
		// request's cache.
		$this->assertTrue( Send4_Settings::form_is_active( self::FORM_KEY ) );

		// Meanwhile, in the admin's request.
		$saved = Send4_Settings::forms();

		$saved[ self::FORM_KEY ]['enabled']          = false;
		$saved[ self::FORM_KEY ]['mapping']['phone'] = 'mobile';

		$this->writeFromAnotherRequest( Send4_Settings::FORMS_OPTION, $saved );

		// The delivery comes back from Send4 still holding the old copy.
		$this->assertTrue( Send4_Settings::form_is_active( self::FORM_KEY ), 'The test needs this request to hold a stale copy.' );
		Send4_Settings::record_delivery( self::FORM_KEY, 'delivered' );

		$stored = $this->storedOption( Send4_Settings::FORMS_OPTION );

		$this->assertFalse( $stored[ self::FORM_KEY ]['enabled'], 'The admin disabled this form; a delivery must not switch it back on.' );
		$this->assertSame( 'mobile', $stored[ self::FORM_KEY ]['mapping']['phone'], 'The admin\'s mapping save was reverted.' );

		$this->assertSame( 'delivered', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * Nothing about a delivery lands in the configuration option.
	 *
	 * @return void
	 */
	public function test_the_status_is_not_written_into_the_forms_option() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => 'your-phone' ) );
		$before = $this->storedOption( Send4_Settings::FORMS_OPTION );

		Send4_Settings::record_delivery( self::FORM_KEY, 'failed', 'Send4 returned status 500.' );

		$this->assertSame( $before, $this->storedOption( Send4_Settings::FORMS_OPTION ) );

		$status = $this->storedOption( Send4_Settings::STATUS_OPTION );
		$this->assertSame( 'failed', $status[ self::FORM_KEY ]['last_status'] );
		$this->assertSame( 'Send4 returned status 500.', $status[ self::FORM_KEY ]['last_error'] );

		$form = Send4_Settings::form( self::FORM_KEY );
		$this->assertSame( 'failed', $form['last_status'] );
		$this->assertSame( 'Send4 returned status 500.', $form['last_error'] );
		$this->assertNotSame( '', $form['last_at'] );
	}

	/**
	 * A delivery for a form nobody configured must not create a forms entry.
	 *
	 * @return void
	 */
	public function test_a_delivery_does_not_create_a_form() {
		Send4_Settings::record_delivery( self::FORM_KEY, 'queued', 'Send4 was unreachable.' );

		$this->assertFalse( Send4_Settings::has_form( self::FORM_KEY ) );
		$this->assertFalse( $this->storedOption( Send4_Settings::FORMS_OPTION ) );
	}

	/**
	 * Saving a form read back with its status merged in writes configuration
	 * only, and a caller cannot push a status in through update_form().
	 *
	 * @return void
	 */
	public function test_saving_a_form_never_writes_its_status() {
		$this->enableForm( self::FORM_KEY, array( 'phone' => 'your-phone' ) );
		Send4_Settings::record_delivery( self::FORM_KEY, 'delivered' );

		Send4_Settings::update_form(
			self::FORM_KEY,
			array_merge(
				Send4_Settings::form( self::FORM_KEY ),
				array(
					'enabled'     => false,
					'last_status' => 'failed',
				)
			)
		);

		$stored = $this->storedOption( Send4_Settings::FORMS_OPTION );

		$this->assertFalse( $stored[ self::FORM_KEY ]['enabled'] );
		$this->assertArrayNotHasKey( 'last_status', $stored[ self::FORM_KEY ] );
		$this->assertArrayNotHasKey( 'last_at', $stored[ self::FORM_KEY ] );
		$this->assertArrayNotHasKey( 'last_error', $stored[ self::FORM_KEY ] );
		$this->assertSame( 'delivered', Send4_Settings::form( self::FORM_KEY )['last_status'] );
	}

	/**
	 * Two deliveries racing on different forms: each re-reads the status
	 * option, so neither erases the other's line.
	 *
	 * @return void
	 */
	public function test_concurrent_deliveries_keep_each_others_status() {
		Send4_Settings::record_delivery( self::FORM_KEY, 'queued', 'Send4 was unreachable.' );

		// This request now has the status option cached. Another request
		// records a delivery for a different form.
		$status                = $this->storedOption( Send4_Settings::STATUS_OPTION );
		$status[ self::OTHER ] = array(
			'last_status' => 'delivered',
			'last_at'     => '2026-09-18 10:00:00',
			'last_error'  => '',
		);
		wp_cache_set( Send4_Settings::STATUS_OPTION, array( self::FORM_KEY => $status[ self::FORM_KEY ] ), 'options' );
		$this->writeFromAnotherRequest( Send4_Settings::STATUS_OPTION, $status );

		Send4_Settings::record_delivery( self::FORM_KEY, 'delivered' );

		$stored = $this->storedOption( Send4_Settings::STATUS_OPTION );

		$this->assertSame( 'delivered', $stored[ self::OTHER ]['last_status'], 'The other request\'s status was lost.' );
		$this->assertSame( 'delivered', $stored[ self::FORM_KEY ]['last_status'] );
	}

	/**
	 * The status option may not exist yet when this request first asks, and
	 * WordPress remembers that in `notoptions`. Another request creating it in
	 * the meantime must still be seen.
	 *
	 * @return void
	 */
	public function test_a_status_option_created_meanwhile_is_not_overwritten() {
		$this->assertSame( array(), Send4_Settings::form_statuses() );

		$this->writeFromAnotherRequest(
			Send4_Settings::STATUS_OPTION,
			array(
				self::OTHER => array(
					'last_status' => 'failed',
					'last_at'     => '2026-09-18 10:00:00',
					'last_error'  => 'Nope.',
				),
			)
		);

		Send4_Settings::record_delivery( self::FORM_KEY, 'delivered' );

		$stored = $this->storedOption( Send4_Settings::STATUS_OPTION );

		$this->assertSame( 'failed', $stored[ self::OTHER ]['last_status'] );
		$this->assertSame( 'delivered', $stored[ self::FORM_KEY ]['last_status'] );
	}

	/**
	 * A site upgraded from before #0696 keeps showing its last outcome, and the
	 * next admin save moves it out of the configuration for good.
	 *
	 * @return void
	 */
	public function test_a_legacy_status_is_shown_then_moved_out_on_the_next_save() {
		update_option(
			Send4_Settings::FORMS_OPTION,
			array(
				self::FORM_KEY => array(
					'enabled'     => true,
					'mapping'     => array( 'phone' => 'your-phone' ),
					'name'        => 'Roof quote',
					'last_status' => 'failed',
					'last_at'     => '2026-09-01 08:00:00',
					'last_error'  => 'Old error.',
				),
				self::OTHER    => array(
					'enabled'     => true,
					'mapping'     => array( 'phone' => 'your-phone' ),
					'last_status' => 'failed',
					'last_at'     => '2026-09-01 08:00:00',
					'last_error'  => 'Superseded.',
				),
			),
			false
		);

		$this->assertSame( 'Old error.', Send4_Settings::form( self::FORM_KEY )['last_error'] );

		// A newer outcome is recorded before anyone saves: it wins.
		Send4_Settings::record_delivery( self::OTHER, 'delivered' );
		$this->assertSame( 'delivered', Send4_Settings::form( self::OTHER )['last_status'] );
		$this->assertSame( '', Send4_Settings::form( self::OTHER )['last_error'] );

		Send4_Settings::update_form( self::FORM_KEY, array( 'enabled' => false ) );

		$forms = $this->storedOption( Send4_Settings::FORMS_OPTION );

		foreach ( array( self::FORM_KEY, self::OTHER ) as $key ) {
			$this->assertArrayNotHasKey( 'last_status', $forms[ $key ], $key . ' still carries a status in its configuration.' );
			$this->assertArrayNotHasKey( 'last_error', $forms[ $key ] );
		}

		$this->assertTrue( $forms[ self::OTHER ]['enabled'], 'Moving the status out must not touch the rest of the entry.' );

		$status = $this->storedOption( Send4_Settings::STATUS_OPTION );
		$this->assertSame( 'failed', $status[ self::FORM_KEY ]['last_status'] );
		$this->assertSame( 'Old error.', $status[ self::FORM_KEY ]['last_error'] );
		$this->assertSame( 'delivered', $status[ self::OTHER ]['last_status'] );
	}

	/**
	 * The status option is never autoloaded: it changes on every delivery,
	 * and nothing on the front end reads it.
	 *
	 * @return void
	 */
	public function test_the_status_option_is_not_autoloaded() {
		global $wpdb;

		Send4_Settings::record_delivery( self::FORM_KEY, 'delivered' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Send4_Settings::STATUS_OPTION ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}
}
