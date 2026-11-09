<?php
/**
 * GDPR plumbing: retention, disclosure, export and erasure.
 *
 * The retry queue holds the lead's phone number, name and email until delivery
 * succeeds, so this plugin is a place personal data can accumulate. WordPress
 * already has the hooks for that; using them is not optional for a plugin
 * distributed on wordpress.org.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Retention sweeps and the personal-data exporter and eraser.
 */
class Send4_Privacy {

	/** Rows that will never be delivered are kept this long, then deleted. */
	const RETENTION_DAYS = 7;

	/**
	 * Rows one exporter or eraser call reads. WordPress calls again, with the
	 * next page number, until a call answers "done". See page_of_rows().
	 */
	const SCAN_BATCH = 500;

	/** Transient holding where each page of a privacy request starts. */
	const CURSOR_TRANSIENT = 'send4_privacy_cursor_';

	/** Far longer than a request takes; short enough not to outlive it by much. */
	const CURSOR_TTL = HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		// Retention runs from Send4_Forms_Sync::run(), the daily event: the
		// every-minute queue event only exists while something is pending.
	}

	/**
	 * Suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When you submit a form on this site that is connected to Send4, the phone number, name and email address you entered, together with your other answers and this site\'s address, are sent to Send4 (send4.ai) so that we can follow up with you by text message. If Send4 cannot be reached at that moment, the submission is stored on this site and retried for up to about a day and a half; undeliverable submissions are deleted after seven days.', 'send4' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Send4', 'send4' ), wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['send4'] = array(
			'exporter_friendly_name' => __( 'Send4 form submissions', 'send4' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['send4'] = array(
			'eraser_friendly_name' => __( 'Send4 form submissions', 'send4' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export whatever is still queued for this email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number, 1-indexed.
	 * @return array
	 */
	public static function export( $email_address, $page = 1 ) {
		list( $rows, $done ) = self::page_of_rows( 'export', $email_address, $page );

		$items = array();

		foreach ( $rows as $row ) {
			$envelope = Send4_Queue::envelope( $row );
			$lead     = isset( $envelope['lead'] ) && is_array( $envelope['lead'] ) ? $envelope['lead'] : array();

			$data = array(
				array(
					'name'  => __( 'Form', 'send4' ),
					'value' => $row->form_key,
				),
				array(
					'name'  => __( 'Submitted', 'send4' ),
					'value' => $row->created_at . ' UTC',
				),
			);

			$labels = array(
				'name'  => __( 'Name', 'send4' ),
				'email' => __( 'Email', 'send4' ),
				'phone' => __( 'Phone', 'send4' ),
			);

			foreach ( $labels as $key => $label ) {
				if ( ! empty( $lead[ $key ] ) ) {
					$data[] = array(
						'name'  => $label,
						'value' => $lead[ $key ],
					);
				}
			}

			if ( ! empty( $envelope['data'] ) && is_array( $envelope['data'] ) ) {
				foreach ( $envelope['data'] as $key => $value ) {
					$data[] = array(
						'name'  => (string) $key,
						'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
					);
				}
			}

			$items[] = array(
				'group_id'    => 'send4-queue',
				'group_label' => __( 'Send4 form submissions awaiting delivery', 'send4' ),
				'item_id'     => 'send4-' . $row->id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Delete whatever is still queued for this email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number, 1-indexed.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		list( $rows, $done ) = self::page_of_rows( 'erase', $email_address, $page );

		$removed = 0;

		foreach ( $rows as $row ) {
			Send4_Queue::delete( (int) $row->id );
			++$removed;
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	/**
	 * One page of the queue rows whose envelope carries this email address.
	 *
	 * The envelope is JSON in a longtext column, so this is a scan rather than
	 * an indexed lookup. The queue is normally empty or tiny, and a privacy
	 * request is rare; indexing lead email would mean storing it twice.
	 *
	 * The scan covers the whole table, not the newest few hundred rows as it
	 * once did. An eraser that stops early answers "done" while the site still
	 * holds the person's data, which is the one thing a privacy tool must
	 * never do — and a queue that swelled during a long outage is exactly when
	 * somebody's request is most likely to be sitting past the cut-off.
	 *
	 * It covers it a batch per call, though, which is what WordPress's page
	 * number is for. Walking the whole table inside one call meant decoding
	 * every envelope, and holding every match, in a single request: on the
	 * queue an outage or a spam run leaves behind, that is a timeout, and a
	 * privacy request that times out has exported or erased nothing.
	 *
	 * Each page starts after the last id the page before it read. An id, not an
	 * offset, because the eraser deletes as it goes and the worker delivers
	 * underneath it: rows that disappear behind the cursor cannot shift the rows
	 * ahead of it out of view. WordPress hands a callback nothing but the
	 * address and the page number, so where each page starts is kept in a
	 * transient, per page, which also makes repeating a page harmless.
	 *
	 * When that record is missing (it expired, or the object cache dropped it)
	 * the call does not guess: it walks everything that is left and answers
	 * "done". Slower, as it used to be, but it can neither skip a row nor ask
	 * to be called again for ever.
	 *
	 * @param string $kind          `export` or `erase`: the two never share a cursor.
	 * @param string $email_address Email address.
	 * @param int    $page          Page number, 1-indexed.
	 * @return array{0:array<int,object>,1:bool} The matching rows, and whether the table is finished.
	 */
	private static function page_of_rows( $kind, $email_address, $page ) {
		$email_address = strtolower( trim( (string) $email_address ) );

		if ( '' === $email_address || ! Send4_Queue::table_exists() ) {
			return array( array(), true );
		}

		/**
		 * Filter how many queue rows one privacy export or erase call reads.
		 *
		 * @param int $batch Default Send4_Privacy::SCAN_BATCH.
		 */
		$batch_size = max( 1, (int) apply_filters( 'send4_privacy_batch', self::SCAN_BATCH ) );

		$page   = max( 1, (int) $page );
		$key    = self::CURSOR_TRANSIENT . md5( $kind . '|' . $email_address );
		$starts = 1 === $page ? array( 1 => 0 ) : get_transient( $key );
		$paged  = is_array( $starts ) && isset( $starts[ $page ] );
		$after  = $paged ? (int) $starts[ $page ] : 0;

		$matches = array();

		do {
			// One row more than the batch, to learn whether there is another
			// page without a call that exists only to find out there is not.
			$batch = Send4_Queue::after( $after, $batch_size + 1 );
			$more  = count( $batch ) > $batch_size;

			if ( $more ) {
				array_pop( $batch );
			}

			foreach ( $batch as $row ) {
				$after = (int) $row->id;

				if ( self::envelope_has_email( Send4_Queue::envelope( $row ), $email_address ) ) {
					$matches[] = $row;
				}
			}
		} while ( $more && ! $paged );

		if ( ! $more ) {
			delete_transient( $key );

			return array( $matches, true );
		}

		$starts[ $page + 1 ] = $after;

		set_transient( $key, $starts, self::CURSOR_TTL );

		return array( $matches, false );
	}

	/**
	 * Does this envelope carry the address, as the lead's email or as an answer?
	 *
	 * Mapping an email field is optional. When it is not mapped, the address
	 * travels as an ordinary answer in `data`, next to the phone number and the
	 * name — so a lookup that only read `lead.email` would export nothing and
	 * erase nothing for exactly the rows that still hold the person's details.
	 *
	 * @param array  $envelope      Decoded envelope.
	 * @param string $email_address Lower-cased, trimmed address.
	 * @return bool
	 */
	private static function envelope_has_email( array $envelope, $email_address ) {
		$candidates = array();

		if ( isset( $envelope['lead']['email'] ) ) {
			$candidates[] = $envelope['lead']['email'];
		}

		if ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) {
			array_walk_recursive(
				$envelope['data'],
				static function ( $value ) use ( &$candidates ) {
					$candidates[] = $value;
				}
			);
		}

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && strtolower( trim( (string) $candidate ) ) === $email_address ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete queue rows that will never be delivered and are past retention.
	 *
	 * Dead letters go RETENTION_DAYS after they last failed. Pending rows go
	 * once they are older than the whole retry schedule plus RETENTION_DAYS
	 * (about nine days): by then nothing is working them, which is what a
	 * disconnected site looks like, and they would otherwise be kept for ever.
	 *
	 * @return int Rows removed.
	 */
	public static function enforce_retention() {
		if ( ! Send4_Queue::table_exists() ) {
			return 0;
		}

		$removed = Send4_Queue::purge_older_than(
			self::RETENTION_DAYS,
			array( Send4_Queue::STATUS_FAILED, Send4_Queue::STATUS_PERMANENT )
		);

		return $removed + Send4_Queue::purge_stale_pending( Send4_Queue::retry_span() + self::RETENTION_DAYS * DAY_IN_SECONDS );
	}
}
