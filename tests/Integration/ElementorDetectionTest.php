<?php
/**
 * Which Elementor forms the forms screen lists, and which one it trusts.
 *
 * @package Send4
 */

/**
 * Elementor keeps a form inside the builder data of whatever post it sits on,
 * and copies that data onto every revision. Detection has to see each live
 * form once, with the field list the site is serving, however many old copies
 * the database holds (#0697).
 */
class Send4_Elementor_Detection_Test extends Send4_Test_Case {

	/**
	 * The double has to make the adapter look installed.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue( ( new Send4_Adapter_Elementor() )->is_available() );
	}

	/**
	 * A form edited many times is one form, with its current fields; revisions
	 * and a trashed page count for nothing.
	 *
	 * @return void
	 */
	public function test_revisions_and_trash_are_not_forms() {
		$page = $this->page( 'publish', 'a1b2c3d', array( 'name', 'phone' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->revision( $page, 'a1b2c3d', array( 'old_field_' . $i ) );
		}

		$this->page( 'trash', 'deadbee', array( 'phone' ) );

		$forms = ( new Send4_Adapter_Elementor() )->detect_forms();

		$this->assertSame( array( 'elementor:a1b2c3d' ), wp_list_pluck( $forms, 'key' ) );
		$this->assertSame( array( 'name', 'phone' ), wp_list_pluck( $forms[0]['fields'], 'id' ) );

		$this->assertNull( Send4_Adapters::find_form( 'elementor:deadbee' ), 'A form on a trashed page is gone.' );
	}

	/**
	 * Revisions used to fill the query's limit, so a live form further down
	 * the table vanished from the list and its mapping screen called it
	 * deleted.
	 *
	 * @return void
	 */
	public function test_revisions_cannot_push_a_live_form_out_of_the_list() {
		$busy = $this->page( 'publish', 'b0b0b0b', array( 'phone' ) );

		for ( $i = 0; $i < Send4_Adapter_Elementor::PAGE_LIMIT; $i++ ) {
			$this->revision( $busy, 'b0b0b0b', array( 'phone' ) );
		}

		$this->page( 'publish', 'c0ffee1', array( 'phone' ) );

		$keys = wp_list_pluck( ( new Send4_Adapter_Elementor() )->detect_forms(), 'key' );

		sort( $keys );

		$this->assertSame( array( 'elementor:b0b0b0b', 'elementor:c0ffee1' ), $keys );
	}

	/**
	 * The same widget on a published page and a newer draft copy: the page
	 * visitors submit is the one described, by the list and by a lookup alike.
	 *
	 * @return void
	 */
	public function test_a_published_copy_wins_over_a_newer_draft() {
		$this->page( 'publish', 'a1b2c3d', array( 'phone' ), '2026-01-01 10:00:00' );
		$this->page( 'draft', 'a1b2c3d', array( 'phone', 'draft_only' ), '2026-06-01 10:00:00' );

		$forms = ( new Send4_Adapter_Elementor() )->detect_forms();

		$this->assertCount( 1, $forms );
		$this->assertSame( array( 'phone' ), wp_list_pluck( $forms[0]['fields'], 'id' ) );
		$this->assertSame( $forms[0], ( new Send4_Adapter_Elementor() )->find_form( 'a1b2c3d' ) );
	}

	/**
	 * Of two live copies, the one edited last.
	 *
	 * @return void
	 */
	public function test_the_most_recently_edited_copy_wins() {
		$this->page( 'publish', 'a1b2c3d', array( 'phone' ), '2026-01-01 10:00:00' );
		$this->page( 'publish', 'a1b2c3d', array( 'phone', 'city' ), '2026-06-01 10:00:00' );

		$forms = ( new Send4_Adapter_Elementor() )->detect_forms();

		$this->assertCount( 1, $forms );
		$this->assertSame( array( 'phone', 'city' ), wp_list_pluck( $forms[0]['fields'], 'id' ) );
	}

	/**
	 * Popups live in elementor_library, and their forms are as live as any.
	 *
	 * @return void
	 */
	public function test_a_form_in_a_popup_is_detected() {
		$this->page( 'publish', 'f00d123', array( 'phone' ), '', 'elementor_library' );

		$this->assertSame( array( 'elementor:f00d123' ), wp_list_pluck( ( new Send4_Adapter_Elementor() )->detect_forms(), 'key' ) );
	}

	/**
	 * A form past the detection limit is still found by key, so its mapping
	 * screen opens and its save is accepted.
	 *
	 * @return void
	 */
	public function test_a_form_past_the_limit_is_found_by_key() {
		// Created first, so equal timestamps leave it last in the ordering.
		$this->page( 'publish', 'aaaaaaa', array( 'phone' ) );

		for ( $i = 0; $i < Send4_Adapter_Elementor::PAGE_LIMIT; $i++ ) {
			$this->page( 'publish', sprintf( 'b%06d', $i ), array( 'phone' ) );
		}

		$this->assertNotContains( 'elementor:aaaaaaa', wp_list_pluck( Send4_Adapters::detect_forms(), 'key' ) );

		$form = Send4_Adapters::find_form( 'elementor:aaaaaaa' );

		$this->assertIsArray( $form );
		$this->assertSame( 'elementor:aaaaaaa', $form['key'] );
		$this->assertSame( 'elementor', $form['adapter'] );
		$this->assertSame( 'Elementor Pro', $form['adapter_name'] );

		$this->assertNull( Send4_Adapters::find_form( 'elementor:nothere' ) );
	}

	/**
	 * A set-up form on a page past the page limit stays in the list, and so
	 * in what Send4 is told. A hundred pages hold far fewer forms than the
	 * registry's ceiling, so the registry never knew the list had stopped.
	 *
	 * @return void
	 */
	public function test_a_configured_form_past_the_page_limit_stays_listed() {
		$this->page( 'publish', 'aaaaaaa', array( 'phone' ) );

		for ( $i = 0; $i < Send4_Adapter_Elementor::PAGE_LIMIT; $i++ ) {
			$this->page( 'publish', sprintf( 'b%06d', $i ), array( 'phone' ) );
		}

		$this->enableForm( 'elementor:aaaaaaa', array( 'phone' => 'phone' ) );
		Send4_Adapters::forget_forms();

		$keys = wp_list_pluck( Send4_Adapters::detect_forms(), 'key' );

		$this->assertContains( 'elementor:aaaaaaa', $keys );
		$this->assertCount( Send4_Adapter_Elementor::PAGE_LIMIT + 1, $keys );
	}

	/**
	 * Fewer pages than the limit is a complete list.
	 *
	 * @return void
	 */
	public function test_a_short_page_list_is_not_reported_as_truncated() {
		$this->page( 'publish', 'a1b2c3d', array( 'phone' ) );

		$adapter = new Send4_Adapter_Elementor();
		$adapter->detect_forms();

		$this->assertFalse( $adapter->detection_truncated() );
	}

	/**
	 * A password never leaves the site: not declared as a mappable field, and
	 * not in the submission.
	 *
	 * @return void
	 */
	public function test_a_password_field_is_neither_declared_nor_sent() {
		$this->page( 'publish', 'a1b2c3d', array( 'phone', 'secret' ), '', 'page', array( 'secret' => 'password' ) );

		$forms = ( new Send4_Adapter_Elementor() )->detect_forms();

		$this->assertSame( array( 'phone' ), wp_list_pluck( $forms[0]['fields'], 'id' ) );

		$record                     = $this->fixture( 'elementor/record.php' );
		$record['fields']['secret'] = array(
			'id'        => 'secret',
			'type'      => 'password',
			'title'     => 'Password',
			'value'     => 'hunter2',
			'raw_value' => 'hunter2',
		);

		$submission = ( new Send4_Adapter_Elementor() )->capture( new Send4_Elementor_Record_Double( $record ) );

		$this->assertInstanceOf( 'Send4_Submission', $submission );
		$this->assertNotContains( 'secret', wp_list_pluck( $submission->fields, 'id' ) );
		$this->assertStringNotContainsString( 'hunter2', (string) wp_json_encode( $submission ) );
	}

	/**
	 * A post carrying one form widget.
	 *
	 * @param string   $status   Post status.
	 * @param string   $widget   Widget id.
	 * @param string[] $fields   Field custom ids.
	 * @param string   $modified post_modified_gmt, or '' for now.
	 * @param string   $type     Post type.
	 * @param array    $types    Field types by id; text otherwise.
	 * @return int Post id.
	 */
	private function page( $status, $widget, array $fields, $modified = '', $type = 'page', array $types = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'post_title'  => 'Contact ' . $widget,
			)
		);

		if ( '' !== $modified ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => $modified,
					'post_modified_gmt' => $modified,
				),
				array( 'ID' => $post_id )
			);
		}

		update_metadata( 'post', $post_id, '_elementor_data', wp_slash( $this->data( $widget, $fields, $types ) ) );

		return $post_id;
	}

	/**
	 * A revision of a page, carrying its own copy of the builder data, the way
	 * Elementor's revisions manager leaves it.
	 *
	 * @param int      $parent Page id.
	 * @param string   $widget Widget id.
	 * @param string[] $fields Field custom ids.
	 * @return void
	 */
	private function revision( $parent, $widget, array $fields ) {
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $parent,
				'post_name'   => $parent . '-revision-v1',
			)
		);

		// Not update_post_meta(), which writes a revision's meta to its parent.
		update_metadata( 'post', $revision_id, '_elementor_data', wp_slash( $this->data( $widget, $fields ) ) );
	}

	/**
	 * _elementor_data holding one section with one form widget.
	 *
	 * @param string   $widget Widget id.
	 * @param string[] $fields Field custom ids.
	 * @param array    $types  Field types by id.
	 * @return string
	 */
	private function data( $widget, array $fields, array $types = array() ) {
		$form_fields = array();

		foreach ( $fields as $field ) {
			$form_fields[] = array(
				'custom_id'   => $field,
				'field_label' => ucfirst( $field ),
				'field_type'  => isset( $types[ $field ] ) ? $types[ $field ] : 'text',
			);
		}

		return (string) wp_json_encode(
			array(
				array(
					'id'       => 'sec' . $widget,
					'elType'   => 'section',
					'elements' => array(
						array(
							'id'         => $widget,
							'elType'     => 'widget',
							'widgetType' => 'form',
							'settings'   => array(
								'form_name'   => 'Contact',
								'form_fields' => $form_fields,
							),
							'elements'   => array(),
						),
					),
				),
			)
		);
	}
}
