<?php
/**
 * The counters behind the dashboard's headline numbers.
 *
 * @package Send4
 */

/**
 * Two resolutions, both trimmed, and a window that really does end now.
 */
class Send4_Stats_Test extends Send4_Test_Case {

	/**
	 * Write a bucket directly, so a test can talk about the past without
	 * waiting for it.
	 *
	 * @param string $day    Day bucket key, Y-m-d.
	 * @param string $status Delivery status.
	 * @param int    $count  How many.
	 * @return void
	 */
	private function seedDay( $day, $status, $count ) {
		$stored = get_option( Send4_Stats::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['days'][ $day ][ $status ] = $count;

		update_option( Send4_Stats::OPTION, $stored, false );
	}

	/**
	 * A day key N days before today, in UTC as the counters store it.
	 *
	 * @param int $days How far back.
	 * @return string
	 */
	private function daysAgo( $days ) {
		return gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
	}

	public function test_recording_counts_the_outcome_in_both_resolutions() {
		Send4_Stats::record( 'delivered' );
		Send4_Stats::record( 'delivered' );
		Send4_Stats::record( 'failed' );

		$stored = get_option( Send4_Stats::OPTION );

		$this->assertSame( 2, $stored['days'][ gmdate( 'Y-m-d' ) ]['delivered'] );
		$this->assertSame( 1, $stored['days'][ gmdate( 'Y-m-d' ) ]['failed'] );
		$this->assertSame( 2, $stored['hours'][ gmdate( 'Y-m-d H' ) ]['delivered'] );
	}

	/**
	 * Two requests counting at once: the second read the option before the
	 * first wrote it. Its write used to replace the first request's count;
	 * now it loses the swap, reads again and counts on top.
	 *
	 * @return void
	 */
	public function test_a_count_written_in_between_is_not_overwritten() {
		global $wpdb;

		Send4_Stats::record( 'delivered', 'gravityforms:7' );

		$interleaved = false;
		$interleave  = static function ( $query ) use ( &$interleaved, $wpdb ) {
			if ( $interleaved || 0 !== strpos( ltrim( (string) $query ), 'UPDATE' ) || false === strpos( (string) $query, Send4_Stats::OPTION ) ) {
				return $query;
			}

			$interleaved = true;

			// The other request's write lands first.
			Send4_Stats::record( 'failed', 'gravityforms:7' );

			return $query;
		};

		add_filter( 'query', $interleave );
		Send4_Stats::record( 'delivered', 'gravityforms:7' );
		remove_filter( 'query', $interleave );

		$this->assertTrue( $interleaved );

		$today = get_option( Send4_Stats::OPTION )['days'][ gmdate( 'Y-m-d' ) ];
		$form  = Send4_Stats::by_form( '24h' )['gravityforms:7'];

		$this->assertSame( 2, $today['delivered'] );
		$this->assertSame( 1, $today['failed'] );
		$this->assertSame( 2, $form['delivered'] );
		$this->assertSame( 1, $form['failed'] );
	}

	/**
	 * The first count creates the option, not autoloaded.
	 *
	 * @return void
	 */
	public function test_the_first_count_creates_the_option() {
		global $wpdb;

		Send4_Stats::record( 'queued' );

		$this->assertSame( 1, get_option( Send4_Stats::OPTION )['days'][ gmdate( 'Y-m-d' ) ]['queued'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Send4_Stats::OPTION ) );

		$this->assertContains( $autoload, array( 'off', 'no' ) );
	}

	public function test_totals_add_up_every_bucket_inside_the_window() {
		$this->seedDay( $this->daysAgo( 0 ), 'delivered', 3 );
		$this->seedDay( $this->daysAgo( 5 ), 'delivered', 4 );
		$this->seedDay( $this->daysAgo( 20 ), 'delivered', 5 );

		$this->assertSame( 7, Send4_Stats::totals( '7d' )['delivered'] );
		$this->assertSame( 12, Send4_Stats::totals( '30d' )['delivered'] );
	}

	/**
	 * The comparison window is the one immediately before, not the same one
	 * again — otherwise every delta would be zero.
	 *
	 * @return void
	 */
	public function test_the_previous_window_does_not_overlap_the_current_one() {
		$this->seedDay( $this->daysAgo( 2 ), 'delivered', 10 );
		$this->seedDay( $this->daysAgo( 9 ), 'delivered', 4 );

		$this->assertSame( 10, Send4_Stats::totals( '7d' )['delivered'] );
		$this->assertSame( 4, Send4_Stats::previous( '7d' )['delivered'] );
	}

	/**
	 * The change is measured between complete buckets. Counting today's
	 * partial bucket on one side and seven full days on the other read a
	 * steady 10 a day as "▼ 14%" just after midnight.
	 *
	 * @return void
	 */
	public function test_a_steady_rate_reads_as_no_change_however_early_in_the_day() {
		// Today has barely started: one so far.
		$this->seedDay( $this->daysAgo( 0 ), 'delivered', 1 );

		for ( $i = 1; $i <= 14; $i++ ) {
			$this->seedDay( $this->daysAgo( $i ), 'delivered', 10 );
		}

		$this->assertSame( 70, Send4_Stats::settled( '7d' )['delivered'] );
		$this->assertSame( 70, Send4_Stats::previous( '7d' )['delivered'] );

		// The headline still counts today.
		$this->assertSame( 61, Send4_Stats::totals( '7d' )['delivered'] );

		set_transient( Send4_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Stogas UAB' ), 'flows' => array() ), 300 );

		$data = Send4_Admin_Dashboard::build( new Send4_Admin(), '7d' );

		$this->assertSame( 0, $data['sent_delta'] );
	}

	/**
	 * The 24 hour comparison needs the hour in progress plus 48 complete ones,
	 * so the trim has to keep that many.
	 *
	 * @return void
	 */
	public function test_the_24h_comparison_has_the_hours_it_needs() {
		$stored = array(
			'days'  => array(),
			'hours' => array(),
		);

		for ( $i = 0; $i <= 48; $i++ ) {
			$stored['hours'][ gmdate( 'Y-m-d H', time() - ( $i * HOUR_IN_SECONDS ) ) ] = array( 'delivered' => 1 );
		}

		update_option( Send4_Stats::OPTION, $stored, false );
		Send4_Stats::record( 'failed' );

		$this->assertSame( 24, Send4_Stats::settled( '24h' )['delivered'] );
		$this->assertSame( 24, Send4_Stats::previous( '24h' )['delivered'] );
	}

	/**
	 * A day bucket cannot answer "the last 24 hours", so that range reads the
	 * hour buckets instead.
	 *
	 * @return void
	 */
	public function test_the_24h_range_reads_hours_not_days() {
		$this->seedDay( gmdate( 'Y-m-d' ), 'delivered', 99 );

		$this->assertSame( array(), Send4_Stats::totals( '24h' ) );

		Send4_Stats::record( 'delivered' );

		$this->assertSame( 1, Send4_Stats::totals( '24h' )['delivered'] );
	}

	/**
	 * The option is written on every delivery, so it has to stay bounded no
	 * matter how long the site runs.
	 *
	 * @return void
	 */
	public function test_old_buckets_are_trimmed_away() {
		$stored = array(
			'days'  => array(),
			'hours' => array(),
		);

		for ( $i = 0; $i < Send4_Stats::DAYS + 25; $i++ ) {
			$stored['days'][ $this->daysAgo( $i ) ] = array( 'delivered' => 1 );
		}

		for ( $i = 0; $i < Send4_Stats::HOURS + 25; $i++ ) {
			$stored['hours'][ gmdate( 'Y-m-d H', time() - ( $i * HOUR_IN_SECONDS ) ) ] = array( 'delivered' => 1 );
		}

		update_option( Send4_Stats::OPTION, $stored, false );

		Send4_Stats::record( 'delivered' );

		$after = get_option( Send4_Stats::OPTION );

		$this->assertCount( Send4_Stats::DAYS, $after['days'] );
		$this->assertCount( Send4_Stats::HOURS, $after['hours'] );

		// The newest survived the trim, the oldest did not.
		$this->assertArrayHasKey( gmdate( 'Y-m-d' ), $after['days'] );
		$this->assertArrayNotHasKey( $this->daysAgo( Send4_Stats::DAYS + 24 ), $after['days'] );
	}

	public function test_an_unknown_range_falls_back_to_30d() {
		$this->assertSame( '30d', Send4_Stats::valid_range( 'all-time' ) );
		$this->assertSame( '30d', Send4_Stats::valid_range( '' ) );
		$this->assertSame( '7d', Send4_Stats::valid_range( '7d' ) );
	}

	public function test_a_corrupt_option_does_not_take_the_dashboard_down() {
		update_option( Send4_Stats::OPTION, 'not an array', false );

		$this->assertSame( array(), Send4_Stats::totals( '30d' ) );

		Send4_Stats::record( 'delivered' );

		$this->assertSame( 1, Send4_Stats::totals( '30d' )['delivered'] );
	}

	/**
	 * Recording is wired into the one place every outcome funnels through, so
	 * the counters cannot drift from what the forms screen reports.
	 *
	 * @return void
	 */
	public function test_recording_a_delivery_feeds_the_counters() {
		Send4_Settings::record_delivery( 'contact-form-7:1', 'delivered' );
		Send4_Settings::record_delivery( 'contact-form-7:1', 'queued', 'Send4 was unreachable.' );

		$totals = Send4_Stats::totals( '30d' );

		$this->assertSame( 1, $totals['delivered'] );
		$this->assertSame( 1, $totals['queued'] );
	}

	public function test_an_outcome_is_also_counted_under_its_form() {
		Send4_Stats::record( 'delivered', 'contact-form-7:1' );
		Send4_Stats::record( 'delivered', 'contact-form-7:1' );
		Send4_Stats::record( 'failed', 'gravityforms:2' );
		Send4_Stats::record( 'delivered' );

		$stored = get_option( Send4_Stats::OPTION );

		$this->assertSame( 2, $stored['forms']['contact-form-7:1']['days'][ gmdate( 'Y-m-d' ) ]['delivered'] );
		$this->assertSame( 2, $stored['forms']['contact-form-7:1']['hours'][ gmdate( 'Y-m-d H' ) ]['delivered'] );

		foreach ( array( '24h', '7d', '30d' ) as $range ) {
			$this->assertSame(
				array(
					'contact-form-7:1' => array( 'delivered' => 2 ),
					'gravityforms:2'   => array( 'failed' => 1 ),
				),
				Send4_Stats::by_form( $range )
			);
		}

		// The site-wide count has all four; the one without a form is in no row.
		$this->assertSame( 3, Send4_Stats::totals( '30d' )['delivered'] );
	}

	public function test_the_funnel_passes_the_form_key_along() {
		Send4_Settings::record_delivery( 'contact-form-7:1', 'unrouted', 'No flow.' );

		$this->assertSame( array( 'contact-form-7:1' => array( 'unrouted' => 1 ) ), Send4_Stats::by_form( '7d' ) );
	}

	/**
	 * A form leaves the per-form window by age. Trimming by bucket count, as
	 * the site-wide series does, would keep a quiet form's buckets for years.
	 *
	 * @return void
	 */
	public function test_per_form_buckets_age_out_and_take_an_empty_form_with_them() {
		$old_day  = $this->daysAgo( Send4_Stats::FORM_DAYS + 5 );
		$old_hour = gmdate( 'Y-m-d H', time() - ( ( Send4_Stats::FORM_HOURS + 5 ) * HOUR_IN_SECONDS ) );
		// The oldest day the 30 day window still reads.
		$kept_day = $this->daysAgo( 29 );

		update_option(
			Send4_Stats::OPTION,
			array(
				'days'        => array(),
				'hours'       => array(),
				'forms_since' => time() - YEAR_IN_SECONDS,
				'forms'       => array(
					'gone:1'  => array(
						'days'  => array( $old_day => array( 'delivered' => 9 ) ),
						'hours' => array( $old_hour => array( 'delivered' => 9 ) ),
					),
					'quiet:2' => array(
						'days'  => array(
							$old_day  => array( 'delivered' => 9 ),
							$kept_day => array( 'delivered' => 4 ),
						),
						'hours' => array(),
					),
				),
			),
			false
		);

		Send4_Stats::record( 'delivered', 'busy:3' );

		$after = get_option( Send4_Stats::OPTION );

		$this->assertArrayNotHasKey( 'gone:1', $after['forms'] );
		$this->assertSame( array( $kept_day => array( 'delivered' => 4 ) ), $after['forms']['quiet:2']['days'] );
		$this->assertSame( 4, Send4_Stats::by_form( '30d' )['quiet:2']['delivered'] );
		$this->assertEqualsWithDelta( time() - YEAR_IN_SECONDS, Send4_Stats::forms_since(), 2 );
	}

	/**
	 * 0.1.5 and earlier wrote no per-form series. That reads as "none counted
	 * yet", and the first write starts the clock.
	 *
	 * @return void
	 */
	public function test_counters_written_before_the_per_form_series_still_read() {
		$this->seedDay( $this->daysAgo( 1 ), 'delivered', 6 );

		$this->assertSame( array(), Send4_Stats::by_form( '30d' ) );
		$this->assertSame( 0, Send4_Stats::forms_since() );

		Send4_Stats::record( 'delivered', 'contact-form-7:1' );

		$this->assertSame( 7, Send4_Stats::totals( '30d' )['delivered'] );
		$this->assertEqualsWithDelta( time(), Send4_Stats::forms_since(), 2 );
	}

	public function test_a_corrupt_per_form_series_does_not_take_the_dashboard_down() {
		update_option(
			Send4_Stats::OPTION,
			array(
				'forms'       => array( 'contact-form-7:1' => 'not an array' ),
				'forms_since' => 'soon',
			),
			false
		);

		$this->assertSame( array(), Send4_Stats::by_form( '30d' ) );

		Send4_Stats::record( 'delivered', 'contact-form-7:1' );

		$this->assertSame( 1, Send4_Stats::by_form( '30d' )['contact-form-7:1']['delivered'] );
	}

	/**
	 * A refusal is counted, but it is not a delivery: it is in no "sent"
	 * figure and it never becomes the form's last status.
	 *
	 * @return void
	 */
	public function test_a_refused_phone_number_is_counted_without_becoming_a_delivery() {
		Send4_Settings::update_form(
			'contact-form-7:1',
			array(
				'enabled'        => true,
				'mapping'        => array( 'phone' => 'your-phone' ),
				'validate_phone' => true,
			)
		);

		$guard = new Send4_Phone_Guard();

		$this->assertNotSame( '', $guard->reject( 'contact-form-7:1', '8 612 34567' ) );
		$this->assertSame( '', $guard->reject( 'contact-form-7:1', '+370 612 34567' ) );

		$this->assertSame( array( 'refused' => 1 ), Send4_Stats::totals( '30d' ) );
		$this->assertSame( array( 'refused' => 1 ), Send4_Stats::by_form( '30d' )['contact-form-7:1'] );
		$this->assertArrayNotHasKey( 'contact-form-7:1', Send4_Settings::form_statuses() );

		set_transient( Send4_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Stogas UAB' ), 'flows' => array() ), 300 );

		$data = Send4_Admin_Dashboard::build( new Send4_Admin(), '30d' );

		$this->assertSame( 0, $data['sent'] );
		$this->assertNull( $data['rate'] );
		$this->assertSame( 1, $data['outcomes']['refused'] );
		$this->assertSame( 0, $data['outcomes']['total'] );
	}
}
