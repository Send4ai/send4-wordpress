<?php
/**
 * Everything the overview screen shows, assembled away from the markup.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin's own state — counters, queue, log, detected forms, the
 * cached handshake — and hands the view something already decided.
 *
 * Every number here is one the plugin can actually stand behind. Send4's own
 * conversation, quota and response-time figures are not reachable with the
 * credential this plugin holds, so the dashboard does not pretend to have them.
 */
class Send4_Admin_Dashboard {

	/**
	 * How many forms the per-form table lists, busiest first.
	 */
	const FORM_ROWS = 10;

	/**
	 * Build the whole view model.
	 *
	 * @param Send4_Admin $admin Admin instance, for handshake and labels.
	 * @param string      $range One of 24h|7d|30d.
	 * @return array
	 */
	public static function build( Send4_Admin $admin, $range ) {
		$handshake = Send4_Settings::has_credentials() ? $admin->handshake( false ) : null;
		$state     = Send4_Admin::connection_state( $handshake );
		$connected = 'connected' === $state;

		$totals = Send4_Stats::totals( $range );
		$sent   = self::sum( $totals, array( 'delivered', 'duplicate' ) );

		// The change is measured between complete buckets only, never from the
		// headline figure, which includes the bucket in progress. See
		// Send4_Stats::settled().
		$sent_settled = self::sum( Send4_Stats::settled( $range ), array( 'delivered', 'duplicate' ) );
		$sent_prev    = self::sum( Send4_Stats::previous( $range ), array( 'delivered', 'duplicate' ) );

		return array(
			'range'      => $range,
			'connected'  => $connected,
			'state'      => $state,
			'error'      => is_wp_error( $handshake ) ? $handshake->get_error_message() : '',
			'handshake'  => $handshake,
			'account'    => $connected && isset( $handshake['client']['name'] ) ? (string) $handshake['client']['name'] : '',
			'readiness'  => $connected ? Send4_Readiness::failing( $handshake ) : array(),
			'triggers'   => self::triggers(),
			'flows'      => self::flows( $handshake ),
			'queue'      => Send4_Queue::table_exists() ? Send4_Queue::counts() : array(),
			'activity'   => self::activity( $admin ),
			'sent'       => $sent,
			'sent_delta' => self::delta( $sent_settled, $sent_prev ),
			'last'       => self::last_delivery( $admin ),
			'outcomes'   => self::outcomes( $totals ),
			'rate'       => self::rate( $totals ),
			'forms'      => self::forms( $admin, $range ),
			'forms_note' => self::forms_note( $range ),
		);
	}

	/**
	 * The window's outcomes, one segment each, in the order the bar draws them.
	 *
	 * `queued` is not among them: it is a step, not an ending. A queued
	 * submission is counted again as delivered, unrouted or failed once the
	 * retries settle, and drawing both would show one lead twice. `refused` is
	 * carried apart from the segments because it never was a submission.
	 *
	 * @param array $totals Totals keyed by status.
	 * @return array{segments:array<int,array>,total:int,refused:int}
	 */
	private static function outcomes( array $totals ) {
		$segments = array(
			array(
				'status' => 'delivered',
				'tone'   => 'ok',
				'label'  => __( 'Delivered', 'send4' ),
			),
			array(
				'status' => 'duplicate',
				'tone'   => 'info',
				'label'  => __( 'Already received', 'send4' ),
			),
			array(
				'status' => 'unrouted',
				'tone'   => 'warn',
				'label'  => __( 'No flow listening', 'send4' ),
			),
			array(
				'status' => 'failed',
				'tone'   => 'bad',
				'label'  => __( 'Failed', 'send4' ),
			),
		);

		$total = 0;

		foreach ( $segments as $index => $segment ) {
			$segments[ $index ]['count'] = self::sum( $totals, array( $segment['status'] ) );

			$total += $segments[ $index ]['count'];
		}

		return array(
			'segments' => $segments,
			'total'    => $total,
			'refused'  => self::sum( $totals, array( 'refused' ) ),
		);
	}

	/**
	 * What share of settled submissions Send4 accepted, as a whole percentage.
	 *
	 * Null when nothing has settled: 0% of nothing would read as an outage.
	 * Rounded down, so 199 out of 200 is 99% and never a "100%" with a failure
	 * sitting next to it.
	 *
	 * @param array $totals Totals keyed by status.
	 * @return int|null
	 */
	private static function rate( array $totals ) {
		$reached = self::sum( $totals, array( 'delivered', 'duplicate' ) );
		$settled = $reached + self::sum( $totals, array( 'unrouted', 'failed' ) );

		if ( $settled <= 0 ) {
			return null;
		}

		return (int) floor( ( $reached / $settled ) * 100 );
	}

	/**
	 * One row per form that saw anything in the window, busiest first.
	 *
	 * @param Send4_Admin $admin Admin instance, for the status labels.
	 * @param string      $range One of 24h|7d|30d.
	 * @return array<int,array>
	 */
	private static function forms( Send4_Admin $admin, $range ) {
		$statuses = Send4_Settings::form_statuses();
		$detected = self::detected_titles();
		$rows     = array();

		foreach ( Send4_Stats::by_form( $range ) as $form_key => $totals ) {
			$form_key = (string) $form_key;
			$state    = isset( $statuses[ $form_key ] ) ? $statuses[ $form_key ] : array();
			$last     = isset( $state['last_status'] ) ? (string) $state['last_status'] : '';

			$rows[] = array(
				'key'        => $form_key,
				'label'      => self::form_label( $form_key ),
				'adapter'    => self::adapter_name( $form_key ),
				// A form that has since been deleted still has counts worth
				// showing, but no mapping screen to link to.
				'exists'     => isset( $detected[ $form_key ] ),
				'sent'       => self::sum( $totals, array( 'delivered', 'duplicate' ) ),
				'unrouted'   => self::sum( $totals, array( 'unrouted' ) ),
				'failed'     => self::sum( $totals, array( 'failed' ) ),
				'refused'    => self::sum( $totals, array( 'refused' ) ),
				'last_label' => '' !== $last ? $admin->status_label( $last ) : '',
				'last_tone'  => '' !== $last ? $admin->status_tone( $last ) : 'neutral',
				'last_ago'   => self::ago( isset( $state['last_at'] ) ? $state['last_at'] : '' ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$busy_a = $a['sent'] + $a['unrouted'] + $a['failed'] + $a['refused'];
				$busy_b = $b['sent'] + $b['unrouted'] + $b['failed'] + $b['refused'];

				if ( $busy_a === $busy_b ) {
					return strcmp( $a['label'], $b['label'] );
				}

				return $busy_b - $busy_a;
			}
		);

		return array_slice( $rows, 0, self::FORM_ROWS );
	}

	/**
	 * A line for under the per-form table while it covers less than the range.
	 *
	 * The site-wide counters are older than the per-form ones, so right after
	 * an upgrade the table adds up to less than the headline. Saying when the
	 * per-form counting began is more honest than letting that look like a bug.
	 *
	 * @param string $range One of 24h|7d|30d.
	 * @return string
	 */
	private static function forms_note( $range ) {
		$since = Send4_Stats::forms_since();

		if ( $since <= 0 || $since <= time() - Send4_Stats::range_seconds( $range ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: a date, e.g. "20 September 2026". */
			__( 'Counted per form since %s, so earlier submissions are in the totals above but not in this table.', 'send4' ),
			wp_date( get_option( 'date_format' ), $since )
		);
	}

	/**
	 * One row per form plugin, which is how the design's triggers card reads
	 * and how the app's planned triggers endpoint will group them.
	 *
	 * WooCommerce is listed only when it is actually running here: telling a
	 * site that does not use it that it is "not connected" is noise, and
	 * telling one that does is honest — its events are a follow-up, not
	 * something this plugin handles.
	 *
	 * @return array<int,array>
	 */
	private static function triggers() {
		$counts = array();

		foreach ( Send4_Adapters::detect_forms() as $descriptor ) {
			$slug = $descriptor['adapter'];

			if ( ! isset( $counts[ $slug ] ) ) {
				$counts[ $slug ] = array(
					'forms'  => 0,
					'active' => 0,
				);
			}

			++$counts[ $slug ]['forms'];

			if ( Send4_Settings::form_is_active( $descriptor['key'] ) ) {
				++$counts[ $slug ]['active'];
			}
		}

		$rows = array();

		foreach ( Send4_Adapters::all() as $slug => $adapter ) {
			$forms  = isset( $counts[ $slug ]['forms'] ) ? $counts[ $slug ]['forms'] : 0;
			$active = isset( $counts[ $slug ]['active'] ) ? $counts[ $slug ]['active'] : 0;

			$rows[] = array(
				'slug'      => $slug,
				'name'      => $adapter->name(),
				'available' => $adapter->is_available(),
				'forms'     => $forms,
				'active'    => $active,
				'detail'    => self::trigger_detail( $adapter->is_available(), $forms, $active ),
				'state'     => $active > 0 ? 'on' : ( $adapter->is_available() ? 'idle' : 'absent' ),
			);
		}

		if ( self::woocommerce_is_active() ) {
			$rows[] = array(
				'slug'      => 'woocommerce',
				'name'      => 'WooCommerce',
				'available' => true,
				'forms'     => 0,
				'active'    => 0,
				'detail'    => __( 'Store events are not supported yet', 'send4' ),
				'state'     => 'absent',
			);
		}

		return $rows;
	}

	/**
	 * The one-line status under a form plugin's name.
	 *
	 * @param bool $available Whether the form plugin is active.
	 * @param int  $forms     Forms detected.
	 * @param int  $active    Forms sending to Send4.
	 * @return string
	 */
	private static function trigger_detail( $available, $forms, $active ) {
		if ( ! $available ) {
			return __( 'Not installed', 'send4' );
		}

		if ( 0 === $forms ) {
			return __( 'No forms yet', 'send4' );
		}

		if ( 0 === $active ) {
			return sprintf(
				/* translators: %d: number of forms detected. */
				_n( '%d form · none set up', '%d forms · none set up', $forms, 'send4' ),
				$forms
			);
		}

		return sprintf(
			/* translators: 1: number of forms sending, 2: number of forms detected. */
			__( '%1$d of %2$d forms sending', 'send4' ),
			$active,
			$forms
		);
	}

	/**
	 * Is WooCommerce running on this site?
	 *
	 * @return bool
	 */
	private static function woocommerce_is_active() {
		return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
	}

	/**
	 * Flow counts from the handshake: how many are listening, how many are
	 * live, and how many take every form on the site.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @return array{total:int,active:int,catchall:int}
	 */
	private static function flows( $handshake ) {
		$counts = array(
			'total'    => 0,
			'active'   => 0,
			'catchall' => 0,
		);

		if ( ! is_array( $handshake ) || empty( $handshake['flows'] ) ) {
			return $counts;
		}

		foreach ( $handshake['flows'] as $flow ) {
			++$counts['total'];

			if ( ! empty( $flow['is_active'] ) ) {
				++$counts['active'];
			}

			if ( empty( $flow['form_key'] ) ) {
				++$counts['catchall'];
			}
		}

		return $counts;
	}

	/**
	 * The recent activity rows, shaped for the list card.
	 *
	 * The logger keeps a message and a context bag; the list wants a form key,
	 * an outcome and a time, so the mapping happens once, here.
	 *
	 * @param Send4_Admin $admin Admin instance, for the status labels.
	 * @return array<int,array>
	 */
	private static function activity( Send4_Admin $admin ) {
		$rows = array();

		foreach ( array_slice( Send4_Logger::entries(), 0, 8 ) as $entry ) {
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			$key     = isset( $context['form'] ) ? (string) $context['form'] : '';
			$status  = isset( $context['status'] ) ? (string) $context['status'] : '';

			$label = self::form_label( $key );

			$rows[] = array(
				'message' => isset( $entry['message'] ) ? $entry['message'] : '',
				'ago'     => self::ago( isset( $entry['at'] ) ? $entry['at'] : '' ),
				'label'   => $label,
				'initial' => self::initials( $label ),
				'adapter' => self::adapter_name( $key ),
				'status'  => $status,
				'tone'    => '' !== $status ? $admin->status_tone( $status ) : self::level_tone( $entry ),
			);
		}

		return $rows;
	}

	/**
	 * The most recent delivery across every form, for the headline tile.
	 *
	 * @param Send4_Admin $admin Admin instance, for the status label.
	 * @return array{at:string,ago:string,label:string,tone:string}|null
	 */
	private static function last_delivery( Send4_Admin $admin ) {
		$latest = null;

		foreach ( Send4_Settings::form_statuses() as $state ) {
			if ( empty( $state['last_at'] ) || empty( $state['last_status'] ) ) {
				continue;
			}

			if ( null === $latest || $state['last_at'] > $latest['last_at'] ) {
				$latest = $state;
			}
		}

		if ( null === $latest ) {
			return null;
		}

		return array(
			'at'    => $latest['last_at'],
			'ago'   => self::ago( $latest['last_at'] ),
			'label' => $admin->status_label( $latest['last_status'] ),
			'tone'  => $admin->status_tone( $latest['last_status'] ),
		);
	}

	/**
	 * Add up a few statuses out of a totals array.
	 *
	 * @param array    $totals   Totals keyed by status.
	 * @param string[] $statuses Statuses to add.
	 * @return int
	 */
	private static function sum( array $totals, array $statuses ) {
		$sum = 0;

		foreach ( $statuses as $status ) {
			$sum += isset( $totals[ $status ] ) ? (int) $totals[ $status ] : 0;
		}

		return $sum;
	}

	/**
	 * Percentage change between two windows.
	 *
	 * Null rather than a number when the previous window was empty: "up 100%"
	 * from zero is not a fact, it is an artefact of dividing by nothing.
	 *
	 * @param int $now      This window.
	 * @param int $previous The window before.
	 * @return int|null
	 */
	private static function delta( $now, $previous ) {
		if ( $previous <= 0 ) {
			return null;
		}

		return (int) round( ( ( $now - $previous ) / $previous ) * 100 );
	}

	/**
	 * A readable name for a form key.
	 *
	 * The stored name is only written once a form has been saved, so a log
	 * line about a form nobody ever set up would otherwise render as a raw
	 * key. Fall back to what the form plugin currently calls it, and only then
	 * to the key.
	 *
	 * @param string $form_key Form key.
	 * @return string
	 */
	private static function form_label( $form_key ) {
		if ( '' === $form_key ) {
			return '';
		}

		$state = Send4_Settings::form( $form_key );

		if ( '' !== $state['name'] ) {
			return $state['name'];
		}

		$titles = self::detected_titles();

		return isset( $titles[ $form_key ] ) && '' !== $titles[ $form_key ] ? $titles[ $form_key ] : $form_key;
	}

	/**
	 * Detected form titles keyed by form key, asked for once per request.
	 *
	 * Detecting forms walks every active form plugin, and the activity list
	 * would otherwise ask for that once per row.
	 *
	 * @return array<string,string>
	 */
	private static function detected_titles() {
		static $titles = null;

		if ( null !== $titles ) {
			return $titles;
		}

		$titles = array();

		foreach ( Send4_Adapters::detect_forms() as $descriptor ) {
			$titles[ $descriptor['key'] ] = $descriptor['title'];
		}

		return $titles;
	}

	/**
	 * The form plugin a key belongs to, by its slug prefix.
	 *
	 * @param string $form_key Form key.
	 * @return string
	 */
	private static function adapter_name( $form_key ) {
		if ( '' === $form_key || false === strpos( $form_key, ':' ) ) {
			return '';
		}

		list( $slug ) = explode( ':', $form_key, 2 );

		$adapter = Send4_Adapters::get( $slug );

		return null === $adapter ? $slug : $adapter->name();
	}

	/**
	 * Up to two initials for the avatar disc.
	 *
	 * @param string $label Form name.
	 * @return string
	 */
	private static function initials( $label ) {
		$label = trim( wp_strip_all_tags( (string) $label ) );

		if ( '' === $label ) {
			return '—';
		}

		$words    = preg_split( '/\s+/', $label );
		$initials = '';

		foreach ( array_slice( is_array( $words ) ? $words : array(), 0, 2 ) as $word ) {
			$initials .= mb_substr( $word, 0, 1 );
		}

		if ( '' === $initials ) {
			return '—';
		}

		// WordPress polyfills mb_substr when mbstring is missing, but not
		// mb_strtoupper, so this one has to be asked for rather than assumed.
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * Badge tone for a log entry that carries no delivery status of its own.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private static function level_tone( array $entry ) {
		$level = isset( $entry['level'] ) ? $entry['level'] : 'info';

		if ( 'error' === $level ) {
			return 'bad';
		}

		return 'warning' === $level ? 'warn' : 'neutral';
	}

	/**
	 * "5 minutes ago" for a stored UTC timestamp.
	 *
	 * @param string $utc Timestamp as stored, Y-m-d H:i:s in UTC.
	 * @return string
	 */
	private static function ago( $utc ) {
		$utc = (string) $utc;

		if ( '' === $utc ) {
			return '';
		}

		$stamp = strtotime( $utc . ' UTC' );

		if ( false === $stamp ) {
			return '';
		}

		$now = time();

		if ( $stamp > $now ) {
			return __( 'just now', 'send4' );
		}

		/* translators: %s: human readable time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'send4' ), human_time_diff( $stamp, $now ) );
	}
}
