<?php
/**
 * The retry queue: a form lead must never be lost because Send4 was deploying.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows live in {prefix}send4_queue and are worked by WP-Cron.
 */
class Send4_Queue {

	const STATUS_PENDING   = 'pending';
	const STATUS_FAILED    = 'failed';
	const STATUS_PERMANENT = 'failed_permanent';

	const MAX_ATTEMPTS = 10;

	/**
	 * Seconds a worker holds a row it has claimed.
	 *
	 * Comfortably longer than the worst single delivery (a 10s submit, a 401,
	 * a 10s token exchange and a 10s replay), so a slow send is never claimed
	 * twice; short enough that a worker which died mid-send hands the row back
	 * within a couple of minutes.
	 */
	const LEASE_SECONDS = 120;

	/**
	 * Backoff in seconds. The last value repeats until MAX_ATTEMPTS is reached,
	 * which puts the final attempt roughly 1.5 days after the submission.
	 *
	 * @var int[]
	 */
	private static $backoff = array( 60, 300, 900, 3600, 21600 );

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'send4_queue';
	}

	/**
	 * Create the table. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta parses this itself rather than handing it to the database, and
		// it is picky: two spaces after PRIMARY KEY, uppercase KEY (never INDEX),
		// one column per line, no IF NOT EXISTS, no inline column-level UNIQUE.
		// TEXT columns may not carry a DEFAULT at all, and the epoch sentinel is
		// used instead of the zero date because NO_ZERO_DATE rejects the latter.
		//
		// client_id is the Send4 API client the row was queued under. Send4's
		// idempotency key is per client, so a row sent under a different
		// client would be a brand new lead in somebody else's workspace. The
		// same goes for uniqueness: one row per submission *per client*, so a
		// submission captured again after a reconnect gets a row of its own
		// instead of reviving the old account's row and payload.
		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	submission_id varchar(191) NOT NULL,
	form_key varchar(191) NOT NULL,
	client_id varchar(191) NOT NULL DEFAULT '',
	envelope longtext NOT NULL,
	attempts int(11) NOT NULL DEFAULT 0,
	status varchar(32) NOT NULL DEFAULT 'pending',
	next_attempt_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	last_error text NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY submission_client (submission_id,client_id),
	KEY status_next_attempt_at (status,next_attempt_at),
	KEY form_key (form_key)
) {$collate};";

		dbDelta( $sql );

		if ( ! self::table_exists() ) {
			// dbDelta compares against SHOW/DESCRIBE output, which not every
			// storage backend answers the way it expects. A plain create is a
			// cheap safety net; without the table the plugin loses leads. The
			// statement is the one dbDelta() was given, built only from the
			// table name and $wpdb->get_charset_collate(): no input reaches it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- creating the plugin's own table.
			$wpdb->query( str_replace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql ) );
		}

		self::drop_legacy_unique_key();
	}

	/**
	 * Drop schema 2's one-row-per-submission key, once its replacement exists.
	 *
	 * Needed because dbDelta adds indexes but never removes one, and while
	 * the old key is there the same submission cannot be queued for two
	 * accounts. Only dropped once submission_client is in place: a table left
	 * with neither would queue a submission twice for the same account.
	 *
	 * @return void
	 */
	private static function drop_legacy_unique_key() {
		global $wpdb;

		if ( ! self::has_index( 'submission_id' ) || ! self::has_index( 'submission_client' ) ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table, migrating its own index.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX submission_id', $table ) );
	}

	/**
	 * Does the table have an index by this name?
	 *
	 * @param string $name Index name.
	 * @return bool
	 */
	private static function has_index( $name ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, (string) $name ) );

		return ! empty( $rows );
	}

	/**
	 * Does the table exist?
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// esc_like matters here: the prefix and the table name both contain
		// underscores, which LIKE reads as single-character wildcards, so
		// without it this answers true for a table that merely resembles ours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Drop the table. Used by uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	/**
	 * Store an envelope for a later attempt.
	 *
	 * The submission id and the client it is queued under are the unique key,
	 * so queuing the same submission again for the same account never creates
	 * a second delivery. A row another account left behind is not this one:
	 * its payload was built for that account and stays with it, and the new
	 * attempt gets a row of its own. What happens to a row already there for
	 * this account (or for nobody, from before client_id existed):
	 *
	 * - it is failed or failed_permanent and this attempt is retryable: it goes
	 *   back to pending, with this attempt's error and a fresh set of attempts,
	 *   because the condition that dead-lettered it has evidently changed;
	 * - otherwise it is left exactly as it is, and its id returned. A pending
	 *   row will get its own answer on its next attempt.
	 *
	 * @param array       $envelope  The envelope.
	 * @param string      $form_key  Routing key.
	 * @param string      $error     Why the first attempt failed.
	 * @param string      $status    Initial status.
	 * @param string|null $client_id Send4 API client the row belongs to; null for the current connection.
	 * @return int|false Row id, or false when the row could not be written.
	 */
	public static function enqueue( array $envelope, $form_key, $error = '', $status = self::STATUS_PENDING, $client_id = null ) {
		global $wpdb;

		$now           = gmdate( 'Y-m-d H:i:s' );
		$submission_id = isset( $envelope['submission_id'] ) ? (string) $envelope['submission_id'] : '';
		$client_id     = null === $client_id ? self::current_client_id() : (string) $client_id;

		if ( '' === $submission_id ) {
			return false;
		}

		$next     = self::STATUS_PENDING === $status ? time() + self::$backoff[0] : time();
		$existing = self::find_by_submission_id( $submission_id, $client_id );

		if ( $existing ) {
			$dead = in_array( $existing->status, array( self::STATUS_FAILED, self::STATUS_PERMANENT ), true );

			if ( $dead && self::STATUS_PENDING === $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					self::table(),
					array(
						'status'          => self::STATUS_PENDING,
						'attempts'        => 1,
						'client_id'       => $client_id,
						'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next ),
						'updated_at'      => $now,
						'last_error'      => (string) $error,
					),
					array( 'id' => (int) $existing->id ),
					array( '%s', '%d', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return false;
				}
			}

			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'submission_id'   => $submission_id,
				'form_key'        => (string) $form_key,
				'client_id'       => $client_id,
				'envelope'        => wp_json_encode( $envelope ),
				'attempts'        => 1,
				'status'          => $status,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next ),
				'created_at'      => $now,
				'updated_at'      => $now,
				'last_error'      => (string) $error,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			return (int) $wpdb->insert_id;
		}

		// Another request queued it for this account between the look-up and
		// the insert: that row is this submission's.
		$existing = self::find_by_submission_id( $submission_id, $client_id );

		if ( $existing ) {
			return (int) $existing->id;
		}

		return self::take_over( $submission_id, $envelope, $form_key, $error, $status, $client_id, $next );
	}

	/**
	 * Give another account's row for this submission to this one, whole.
	 *
	 * Only reached where schema 2's one-row-per-submission key is still in
	 * place: a database whose index size limit refused submission_client
	 * (767 bytes, before MySQL 5.7 and MariaDB 10.2). Two rows cannot exist
	 * there, and a lead must not be dropped, so the old account's row gives
	 * way, and every column is rewritten: the old payload was built for the
	 * old account and must not be sent under this one.
	 *
	 * @param string $submission_id Idempotency key.
	 * @param array  $envelope      The envelope.
	 * @param string $form_key      Routing key.
	 * @param string $error         Why the first attempt failed.
	 * @param string $status        Initial status.
	 * @param string $client_id     The client the row now belongs to.
	 * @param int    $next          Unix time of the next attempt.
	 * @return int|false Row id, or false when there was nothing to take over or the write failed.
	 */
	private static function take_over( $submission_id, array $envelope, $form_key, $error, $status, $client_id, $next ) {
		global $wpdb;

		$foreign = self::find_by_submission_id( $submission_id );

		if ( ! $foreign || (string) $foreign->client_id === (string) $client_id ) {
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array(
				'form_key'        => (string) $form_key,
				'client_id'       => (string) $client_id,
				'envelope'        => wp_json_encode( $envelope ),
				'attempts'        => 1,
				'status'          => $status,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next ),
				'created_at'      => $now,
				'updated_at'      => $now,
				'last_error'      => (string) $error,
			),
			array( 'id' => (int) $foreign->id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? false : (int) $foreign->id;
	}

	/**
	 * The API client id of the current connection, '' when disconnected.
	 *
	 * @return string
	 */
	public static function current_client_id() {
		$connection = Send4_Settings::connection();

		return (string) $connection['client_id'];
	}

	/**
	 * Take a due row for this worker, so an overlapping run cannot send it too.
	 *
	 * WP-Cron's lock lasts 60 seconds and a batch against a slow Send4 can take
	 * longer, so two workers holding the same batch is ordinary, not a corner
	 * case. The claim is one conditional UPDATE: it pushes next_attempt_at out
	 * by the lease, and only the worker whose UPDATE still matched the value it
	 * SELECTed wins. A worker that dies mid-send needs no cleanup — once the
	 * lease runs out the row is simply due again.
	 *
	 * @param object $row Row as returned by due().
	 * @return bool True when this worker now owns the row.
	 */
	public static function claim( $row ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET next_attempt_at = %s WHERE id = %d AND status = %s AND next_attempt_at = %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ),
				(int) $row->id,
				self::STATUS_PENDING,
				(string) $row->next_attempt_at
			)
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Dead-letter every pending row queued under a different API client.
	 *
	 * Rows queued before client_id existed carry '' and are treated as the
	 * current connection's: they were queued under whatever was connected,
	 * and before this column nothing could have changed that since.
	 *
	 * @param string $client_id The current connection's client id.
	 * @param string $error     Reason shown on the settings screen.
	 * @return int Rows quarantined.
	 */
	public static function quarantine_foreign( $client_id, $error ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, updated_at = %s, last_error = %s WHERE status = %s AND client_id <> '' AND client_id <> %s",
				$table,
				self::STATUS_PERMANENT,
				gmdate( 'Y-m-d H:i:s' ),
				(string) $error,
				self::STATUS_PENDING,
				(string) $client_id
			)
		);

		return (int) $moved;
	}

	/**
	 * Bind rows that predate client_id to the connection being closed.
	 *
	 * Called on disconnect. Without it those rows carry '' and would be sent
	 * under whatever connects next — possibly another workspace. Reconnecting
	 * the same API client still delivers them.
	 *
	 * @param string $client_id Client id of the connection being closed.
	 * @return int Rows stamped.
	 */
	public static function adopt_unowned( $client_id ) {
		global $wpdb;

		if ( '' === (string) $client_id ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stamped = $wpdb->query( $wpdb->prepare( "UPDATE IGNORE %i SET client_id = %s WHERE client_id = ''", $table, (string) $client_id ) );

		return (int) $stamped;
	}

	/**
	 * Put dead-lettered rows back in line for delivery.
	 *
	 * A permanent failure is only permanent until somebody fixes its cause: a
	 * missing ability ticked, a revoked key replaced, a destination corrected.
	 * Without this the only way out was to wait for retention to delete them.
	 *
	 * Only rows belonging to the given client (or to nobody in particular)
	 * move: another workspace's leads must never be sent under this one.
	 *
	 * @param string $client_id The current connection's client id.
	 * @return int Rows requeued.
	 */
	public static function requeue( $client_id ) {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// attempts goes to 0, not 1: the next attempt is the first of a fresh
		// run of MAX_ATTEMPTS, and record_failure() counts from here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status IN (%s, %s) AND client_id IN (%s, '')",
				$table,
				self::STATUS_PENDING,
				$now,
				$now,
				self::STATUS_FAILED,
				self::STATUS_PERMANENT,
				(string) $client_id
			)
		);

		return (int) $moved;
	}

	/**
	 * Is anything still waiting to be delivered?
	 *
	 * @return bool
	 */
	public static function has_pending() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s LIMIT 1', $table, self::STATUS_PENDING ) );
	}

	/**
	 * Look a row up by its idempotency key.
	 *
	 * With a client id, only a row that client may send: its own, or one
	 * from before client_id existed, its own preferred.
	 *
	 * @param string      $submission_id Idempotency key.
	 * @param string|null $client_id     Owning client, or null for any row.
	 * @return object|null
	 */
	public static function find_by_submission_id( $submission_id, $client_id = null ) {
		global $wpdb;

		$table = self::table();

		if ( null === $client_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE submission_id = %s ORDER BY id ASC LIMIT 1', $table, $submission_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE submission_id = %s AND client_id IN (%s, '') ORDER BY client_id DESC LIMIT 1",
				$table,
				$submission_id,
				(string) $client_id
			)
		);
	}

	/**
	 * Rows that are due for another attempt.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object>
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d',
				$table,
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All rows, newest first, for the settings screen.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object>
	 */
	public static function all( $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, (int) $limit ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows with an id above the given one, oldest first.
	 *
	 * A cursor rather than an offset, so the whole table can be walked in
	 * batches — including by a caller that deletes rows as it goes, which
	 * would shift an offset underneath itself.
	 *
	 * @param int $after_id Row id to start after; 0 for the beginning.
	 * @param int $limit    Maximum rows.
	 * @return array<int,object>
	 */
	public static function after( $after_id, $limit = 500 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, (int) $after_id, (int) $limit )
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows sit in each status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::table();
		$counts = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_FAILED    => 0,
			self::STATUS_PERMANENT => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ) );

		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * The envelope stored on a row.
	 *
	 * @param object $row Queue row.
	 * @return array
	 */
	public static function envelope( $row ) {
		$decoded = json_decode( isset( $row->envelope ) ? $row->envelope : '', true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Record a failed attempt and schedule the next one.
	 *
	 * @param object $row         Queue row.
	 * @param string $error       Failure detail.
	 * @param int    $retry_after Seconds Send4 asked us to wait; the longer of this and the backoff wins.
	 * @return string The row's new status: pending, or failed once the attempts are used up.
	 */
	public static function record_failure( $row, $error, $retry_after = 0 ) {
		global $wpdb;

		$attempts = (int) $row->attempts + 1;
		$status   = $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : self::STATUS_PENDING;
		$delay    = max( self::delay_for_attempt( $attempts ), (int) $retry_after );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'attempts'        => $attempts,
				'status'          => $status,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				'last_error'      => (string) $error,
			),
			array( 'id' => (int) $row->id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $status;
	}

	/**
	 * Hold a pending row back for at least this many seconds from now.
	 *
	 * @param int $id      Row id.
	 * @param int $seconds Seconds from now.
	 * @return void
	 */
	public static function postpone( $id, $seconds ) {
		global $wpdb;

		$table = self::table();
		$until = gmdate( 'Y-m-d H:i:s', time() + (int) $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET next_attempt_at = %s WHERE id = %d AND status = %s AND next_attempt_at < %s',
				$table,
				$until,
				(int) $id,
				self::STATUS_PENDING,
				$until
			)
		);
	}

	/**
	 * Flag a row that will never succeed as it stands.
	 *
	 * @param object $row   Queue row.
	 * @param string $error Failure detail.
	 * @return void
	 */
	public static function record_permanent_failure( $row, $error ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_PERMANENT,
				'attempts'   => (int) $row->attempts + 1,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error' => (string) $error,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove a row that has been delivered.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete every row. Used by the settings screen's "clear" action.
	 *
	 * @param string|null $status Limit to one status, or null for all.
	 * @return void
	 */
	public static function purge( $status = null ) {
		global $wpdb;

		$table = self::table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s', $table, $status ) );
	}

	/**
	 * Delete rows in the given statuses older than N days.
	 *
	 * @param int      $days     Age in days.
	 * @param string[] $statuses Statuses to sweep.
	 * @return int Rows removed.
	 */
	public static function purge_older_than( $days, array $statuses ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		$deleted = 0;

		// One statement per status rather than an IN list built at run time,
		// so every query is a fixed string with placeholders. There are at
		// most three statuses, and a sweep need not be atomic.
		foreach ( array_unique( array_map( 'strval', $statuses ) ) as $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted += (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND updated_at < %s', $table, $status, $cutoff )
			);
		}

		return $deleted;
	}

	/**
	 * Delete pending rows nobody is working any more.
	 *
	 * A pending row normally ends as delivered or failed within retry_span(),
	 * but not on a site that was disconnected (process_queue() sends nothing
	 * without credentials) or whose cron stopped: those rows would otherwise
	 * hold a lead's phone number for ever.
	 *
	 * Both timestamps must be past the cutoff. created_at alone would delete a
	 * row an operator requeued yesterday; updated_at is what requeue and every
	 * attempt touch, and it can never be older than created_at.
	 *
	 * @param int $seconds Age in seconds.
	 * @return int Rows removed.
	 */
	public static function purge_stale_pending( $seconds ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND created_at < %s AND updated_at < %s',
				$table,
				self::STATUS_PENDING,
				$cutoff,
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Seconds from a row's first failure to its last scheduled attempt.
	 *
	 * The first failure schedules attempt 2 after delay_for_attempt(1), and so
	 * on until attempt MAX_ATTEMPTS: about a day and a third with the current
	 * schedule. Derived rather than written down so it follows the schedule.
	 *
	 * @return int
	 */
	public static function retry_span() {
		$span = 0;

		for ( $attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$span += self::delay_for_attempt( $attempt );
		}

		return $span;
	}

	/**
	 * Seconds to wait before attempt number N.
	 *
	 * @param int $attempts Attempts made so far.
	 * @return int
	 */
	public static function delay_for_attempt( $attempts ) {
		$index = max( 0, (int) $attempts - 1 );

		if ( $index >= count( self::$backoff ) ) {
			$index = count( self::$backoff ) - 1;
		}

		return self::$backoff[ $index ];
	}
}
