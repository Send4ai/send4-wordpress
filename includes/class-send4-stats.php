<?php
/**
 * Delivery outcome counters, bucketed by hour and by day.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * A tiny time series behind the dashboard's headline numbers.
 *
 * The plugin already records the *last* outcome per form, and the logger keeps
 * the last fifty lines, but neither can answer "how many in the last 30 days,
 * and was that more than the 30 before it?". This can, out of one option.
 *
 * Two resolutions, because the dashboard offers a 24 hour range and a day
 * bucket cannot honestly answer it: hours for the last two days, days for the
 * last four months. Both are trimmed on every write, so the option stays small
 * — roughly 120 day buckets and 48 hour buckets, whatever the traffic.
 *
 * The same counts are kept a second time per form, so the dashboard can say
 * which form the leads come from. Those series are sparse — a form with no
 * traffic writes nothing — so they are trimmed by age rather than by bucket
 * count, and a form with nothing left in the window is dropped entirely. That
 * is what keeps the option bounded on a site that deletes forms.
 *
 * No lead data is ever written here, only outcome counts. A form key names a
 * form, not a person.
 */
class Send4_Stats {

	const OPTION = 'send4_stats';

	/**
	 * Day buckets to keep: the 30 day window plus the 30 before it for the
	 * comparison, with headroom.
	 */
	const DAYS = 120;

	/**
	 * Hour buckets to keep: the one in progress, the 24 complete hours before
	 * it, and the 24 before those for the comparison.
	 */
	const HOURS = 49;

	/**
	 * Days of per-form buckets to keep: the 30 day window and the day in
	 * progress. The per-form table shows no change, so no window before it.
	 */
	const FORM_DAYS = 31;

	/**
	 * Hours of per-form buckets to keep: the 24 hour window and the hour in
	 * progress.
	 */
	const FORM_HOURS = 25;

	/**
	 * Times record() reads the counters again after losing a write to a
	 * concurrent request, before it gives the count up.
	 */
	const WRITE_ATTEMPTS = 10;

	/**
	 * The ranges the dashboard offers, as hours.
	 *
	 * @var array<string,int>
	 */
	private static $ranges = array(
		'24h' => 24,
		'7d'  => 168,
		'30d' => 720,
	);

	/**
	 * Count one delivery outcome, now.
	 *
	 * `refused` is the phone check turning a visitor away. It is counted here
	 * and nowhere else: it is not a delivery, so it never becomes a form's last
	 * status, and nothing that adds up "sent" includes it.
	 *
	 * @param string $status   One of delivered|duplicate|unrouted|queued|failed|refused.
	 * @param string $form_key The form it happened on, or '' to count it site-wide only.
	 * @return void
	 */
	public static function record( $status, $form_key = '' ) {
		$status   = (string) $status;
		$form_key = (string) $form_key;

		if ( '' === $status ) {
			return;
		}

		// Every outcome on the site lands in this one option, and two visitors
		// submitting at once each read it, add one and write it back: the later
		// write erased the earlier count. So the write only lands if the option
		// still holds what was read, and a write that lost the race reads again.
		for ( $try = 0; $try < self::WRITE_ATTEMPTS; $try++ ) {
			$stored = self::read_raw();
			$stats  = self::count( self::normalize( $stored ), $status, $form_key, time() );

			if ( self::swap( $stored, $stats ) ) {
				return;
			}
		}
	}

	/**
	 * Add one outcome to a set of counters.
	 *
	 * @param array  $stats    Counters, as normalize() shapes them.
	 * @param string $status   The outcome.
	 * @param string $form_key The form, or '' for site-wide only.
	 * @param int    $now      Unix timestamp to count it at.
	 * @return array
	 */
	private static function count( array $stats, $status, $form_key, $now ) {
		$day  = gmdate( 'Y-m-d', $now );
		$hour = gmdate( 'Y-m-d H', $now );

		$stats['days'][ $day ][ $status ]   = isset( $stats['days'][ $day ][ $status ] ) ? (int) $stats['days'][ $day ][ $status ] + 1 : 1;
		$stats['hours'][ $hour ][ $status ] = isset( $stats['hours'][ $hour ][ $status ] ) ? (int) $stats['hours'][ $hour ][ $status ] + 1 : 1;

		$stats['days']  = self::trim( $stats['days'], self::DAYS );
		$stats['hours'] = self::trim( $stats['hours'], self::HOURS );

		if ( '' !== $form_key ) {
			$form = isset( $stats['forms'][ $form_key ] ) ? $stats['forms'][ $form_key ] : self::empty_series();

			$form['days'][ $day ][ $status ]   = isset( $form['days'][ $day ][ $status ] ) ? (int) $form['days'][ $day ][ $status ] + 1 : 1;
			$form['hours'][ $hour ][ $status ] = isset( $form['hours'][ $hour ][ $status ] ) ? (int) $form['hours'][ $hour ][ $status ] + 1 : 1;

			$stats['forms'][ $form_key ] = $form;

			if ( 0 === $stats['forms_since'] ) {
				$stats['forms_since'] = $now;
			}
		}

		$stats['forms'] = self::trim_forms( $stats['forms'], $now );

		return $stats;
	}

	/**
	 * Outcome counts per form over the window ending now.
	 *
	 * Forms with nothing in the window are left out rather than returned as
	 * rows of zeroes.
	 *
	 * @param string $range One of 24h|7d|30d.
	 * @return array<string,array<string,int>>
	 */
	public static function by_form( $range ) {
		$stats = self::read();
		$forms = array();

		foreach ( $stats['forms'] as $form_key => $series ) {
			$totals = self::window( $range, 0, 0, $series );

			if ( ! empty( $totals ) ) {
				$forms[ (string) $form_key ] = $totals;
			}
		}

		return $forms;
	}

	/**
	 * When per-form counting began, as a unix timestamp, or 0 if it has not.
	 *
	 * The site-wide counters are older than the per-form ones, so for a while
	 * after an upgrade the two do not add up. The dashboard says so, from this.
	 *
	 * @return int
	 */
	public static function forms_since() {
		$stats = self::read();

		return $stats['forms_since'];
	}

	/**
	 * Outcome counts over the window ending now.
	 *
	 * @param string $range One of 24h|7d|30d.
	 * @return array<string,int>
	 */
	public static function totals( $range ) {
		return self::window( $range, 0 );
	}

	/**
	 * Outcome counts over the most recent window of *complete* buckets: the
	 * one in progress left out.
	 *
	 * This, not totals(), is what a change is measured from. totals() counts
	 * the bucket in progress, so at five past midnight a 7 day window holds six
	 * full days and five minutes, and comparing that with seven full days read
	 * as a 14% drop for a site whose traffic had not moved at all. Leaving the
	 * partial bucket out of both sides compares like with like; prorating it
	 * instead would guess at how the rest of the day goes.
	 *
	 * @param string $range One of 24h|7d|30d.
	 * @return array<string,int>
	 */
	public static function settled( $range ) {
		return self::window( $range, 0, 1 );
	}

	/**
	 * Outcome counts over the window immediately before settled(), so the
	 * dashboard can show a real change rather than an invented one.
	 *
	 * @param string $range One of 24h|7d|30d.
	 * @return array<string,int>
	 */
	public static function previous( $range ) {
		return self::window( $range, 1, 1 );
	}

	/**
	 * Every range key the dashboard may ask for.
	 *
	 * @return string[]
	 */
	public static function ranges() {
		return array_keys( self::$ranges );
	}

	/**
	 * Narrow whatever arrived in the query string to a range we serve.
	 *
	 * @param string $range Candidate range.
	 * @return string
	 */
	public static function valid_range( $range ) {
		$range = (string) $range;

		return isset( self::$ranges[ $range ] ) ? $range : '30d';
	}

	/**
	 * How long a range is, in seconds.
	 *
	 * @param string $range Range key.
	 * @return int
	 */
	public static function range_seconds( $range ) {
		return self::$ranges[ self::valid_range( $range ) ] * HOUR_IN_SECONDS;
	}

	/**
	 * Human label for a range, for the segmented control and the subtitle.
	 *
	 * @param string $range Range key.
	 * @return string
	 */
	public static function range_label( $range ) {
		switch ( self::valid_range( $range ) ) {
			case '24h':
				return __( 'last 24 hours', 'send4' );
			case '7d':
				return __( 'last 7 days', 'send4' );
			default:
				return __( 'last 30 days', 'send4' );
		}
	}

	/**
	 * Short label for a range, for the segmented control.
	 *
	 * @param string $range Range key.
	 * @return string
	 */
	public static function range_short_label( $range ) {
		switch ( self::valid_range( $range ) ) {
			case '24h':
				return _x( '24h', 'time range: the last 24 hours', 'send4' );
			case '7d':
				return _x( '7d', 'time range: the last 7 days', 'send4' );
			default:
				return _x( '30d', 'time range: the last 30 days', 'send4' );
		}
	}

	/**
	 * Throw the counters away.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Sum the buckets covering one window.
	 *
	 * `$back` counts windows backwards: 0 is the window ending now, 1 the one
	 * before it. `$skip` moves both back by that many buckets, which is how
	 * the bucket in progress is left out. The 24 hour range reads hour
	 * buckets; everything longer reads day buckets, whose granularity is
	 * enough at that scale.
	 *
	 * @param string     $range Range key.
	 * @param int        $back  How many windows back.
	 * @param int        $skip  How many of the newest buckets to leave out.
	 * @param array|null $stats A days/hours pair to read instead of the
	 *                          site-wide one: one form's series.
	 * @return array<string,int>
	 */
	private static function window( $range, $back, $skip = 0, $stats = null ) {
		$range = self::valid_range( $range );
		$hours = self::$ranges[ $range ];
		$stats = is_array( $stats ) ? $stats : self::read();

		$hourly = 24 === $hours;
		$format = $hourly ? 'Y-m-d H' : 'Y-m-d';
		$step   = $hourly ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		$slots  = $hourly ? $hours : (int) ( $hours / 24 );
		$series = $hourly ? $stats['hours'] : $stats['days'];

		$now     = time();
		$ends_at = $now - ( ( $back * $slots + $skip ) * $step );
		$totals  = array();

		for ( $i = 0; $i < $slots; $i++ ) {
			$key = gmdate( $format, $ends_at - ( $i * $step ) );

			if ( ! isset( $series[ $key ] ) || ! is_array( $series[ $key ] ) ) {
				continue;
			}

			foreach ( $series[ $key ] as $status => $count ) {
				$totals[ $status ] = isset( $totals[ $status ] ) ? $totals[ $status ] + (int) $count : (int) $count;
			}
		}

		return $totals;
	}

	/**
	 * Keep the newest N buckets.
	 *
	 * Bucket keys sort chronologically as strings, which is the whole reason
	 * for the `Y-m-d H` format.
	 *
	 * @param array $buckets Buckets keyed by date.
	 * @param int   $keep    How many to keep.
	 * @return array
	 */
	private static function trim( array $buckets, $keep ) {
		if ( count( $buckets ) <= $keep ) {
			return $buckets;
		}

		krsort( $buckets );

		return array_slice( $buckets, 0, $keep, true );
	}

	/**
	 * Drop per-form buckets that have aged out, and forms left with none.
	 *
	 * By age, not by count: a form submitted once a month would otherwise keep
	 * thirty-one buckets going back years.
	 *
	 * @param array $forms Per-form series keyed by form key.
	 * @param int   $now   Unix timestamp to measure age from.
	 * @return array
	 */
	private static function trim_forms( array $forms, $now ) {
		$oldest_day  = gmdate( 'Y-m-d', $now - ( ( self::FORM_DAYS - 1 ) * DAY_IN_SECONDS ) );
		$oldest_hour = gmdate( 'Y-m-d H', $now - ( ( self::FORM_HOURS - 1 ) * HOUR_IN_SECONDS ) );

		foreach ( $forms as $form_key => $series ) {
			$series = self::series( $series );

			$series['days']  = self::newer_than( $series['days'], $oldest_day );
			$series['hours'] = self::newer_than( $series['hours'], $oldest_hour );

			if ( empty( $series['days'] ) && empty( $series['hours'] ) ) {
				unset( $forms[ $form_key ] );
				continue;
			}

			$forms[ $form_key ] = $series;
		}

		return $forms;
	}

	/**
	 * Keep the buckets at or after a key.
	 *
	 * @param array  $buckets Buckets keyed by date.
	 * @param string $oldest  The oldest key to keep.
	 * @return array
	 */
	private static function newer_than( array $buckets, $oldest ) {
		foreach ( array_keys( $buckets ) as $key ) {
			if ( strcmp( (string) $key, $oldest ) < 0 ) {
				unset( $buckets[ $key ] );
			}
		}

		return $buckets;
	}

	/**
	 * A days/hours pair with nothing in it.
	 *
	 * @return array{days:array,hours:array}
	 */
	private static function empty_series() {
		return array(
			'days'  => array(),
			'hours' => array(),
		);
	}

	/**
	 * Whatever was stored for a series, with both halves guaranteed arrays.
	 *
	 * @param mixed $stored Stored value.
	 * @return array{days:array,hours:array}
	 */
	private static function series( $stored ) {
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'days'  => isset( $stored['days'] ) && is_array( $stored['days'] ) ? $stored['days'] : array(),
			'hours' => isset( $stored['hours'] ) && is_array( $stored['hours'] ) ? $stored['hours'] : array(),
		);
	}

	/**
	 * The stored counters, with every part guaranteed to be the right type.
	 *
	 * An option written before the per-form series existed has no `forms` key;
	 * it reads as no forms counted yet, which is the truth.
	 *
	 * @return array{days:array,hours:array,forms:array,forms_since:int}
	 */
	private static function read() {
		return self::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * The option's serialized value as the database holds it now, or null
	 * when there is no such row.
	 *
	 * Straight from the table: the object cache may hold an older copy, and
	 * the swap has to compare against exactly what is stored.
	 *
	 * @return string|null
	 */
	private static function read_raw() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Write new counters, only if the option still holds what was read.
	 *
	 * @param string|null $expected The serialized value read, null for no row.
	 * @param array       $stats    The counters to write.
	 * @return bool False when another request wrote in between.
	 */
	private static function swap( $expected, array $stats ) {
		global $wpdb;

		if ( null === $expected ) {
			// add_option() is an INSERT ... ON DUPLICATE KEY UPDATE, which
			// would overwrite a row a concurrent request just added. INSERT
			// IGNORE loses to it instead, and the retry counts on top.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$written = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::OPTION,
					maybe_serialize( $stats ),
					self::autoload_off()
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$written = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $stats ),
					self::OPTION,
					$expected
				)
			);
		}

		if ( 1 !== (int) $written ) {
			return false;
		}

		// What get_option() would otherwise answer from, until the next request.
		wp_cache_delete( self::OPTION, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
			unset( $notoptions[ self::OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return true;
	}

	/**
	 * The autoload value WordPress stores for an option that is not autoloaded.
	 *
	 * @return string
	 */
	private static function autoload_off() {
		return function_exists( 'wp_determine_option_autoload_value' ) ? 'off' : 'no';
	}

	/**
	 * Stored counters, with every part guaranteed to be the right type.
	 *
	 * @param mixed $stored The option's value, serialized or not.
	 * @return array{days:array,hours:array,forms:array,forms_since:int}
	 */
	private static function normalize( $stored ) {
		$stored = maybe_unserialize( $stored );
		$stored = is_array( $stored ) ? $stored : array();
		$series = self::series( $stored );

		$forms = array();

		if ( isset( $stored['forms'] ) && is_array( $stored['forms'] ) ) {
			foreach ( $stored['forms'] as $form_key => $form ) {
				$forms[ $form_key ] = self::series( $form );
			}
		}

		return array(
			'days'        => $series['days'],
			'hours'       => $series['hours'],
			'forms'       => $forms,
			'forms_since' => isset( $stored['forms_since'] ) ? (int) $stored['forms_since'] : 0,
		);
	}
}
