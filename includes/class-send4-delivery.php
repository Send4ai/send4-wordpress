<?php
/**
 * Capture, send, and retry. The only path a submission takes to Send4.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Synchronous POST first, queue second.
 */
class Send4_Delivery {

	/**
	 * Seconds one queue run may spend sending, filterable as
	 * `send4_queue_time_budget`. A third of WP-Cron's 60 second lock, leaving
	 * room for the one request still in flight when the budget runs out.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Handle a submission an adapter just captured.
	 *
	 * Runs inside the visitor's request, so it must be fast and must never
	 * throw: a Send4 outage may not break somebody's contact form.
	 *
	 * @param Send4_Submission $submission Captured submission.
	 * @return Send4_Api_Result|null Null when nothing was sent: the form is not set up, or this is a repeat.
	 */
	public static function capture( Send4_Submission $submission ) {
		if ( ! Send4_Settings::form_is_active( $submission->form_key ) ) {
			return null;
		}

		if ( ! Send4_Settings::has_credentials() ) {
			Send4_Logger::log( 'warning', 'Submission ignored: Send4 is not connected.', array( 'form' => $submission->form_key ) );

			return null;
		}

		$form          = Send4_Settings::form( $submission->form_key );
		$submission_id = Send4_Submission_Id::generate( $submission );
		$envelope      = Send4_Normalizer::build( $submission, $form['mapping'], $submission_id );

		if ( empty( $envelope['lead']['phone'] ) ) {
			Send4_Settings::record_delivery(
				$submission->form_key,
				'failed',
				__( 'The submission had no phone number, so there is nobody to follow up with.', 'send4' )
			);
			Send4_Logger::log(
				'warning',
				'Submission skipped: mapped phone field was empty.',
				array(
					'form'          => $submission->form_key,
					'submission_id' => $submission_id,
					'status'        => 'failed',
				)
			);

			return null;
		}

		// Deliberately not recorded as the form's last delivery: the request
		// that did claim it is running alongside this one, and its real outcome
		// is the one the admin should see.
		if ( ! Send4_Duplicate_Guard::claim( $envelope ) ) {
			Send4_Logger::log(
				'info',
				'Submission skipped: an identical one was captured moments ago.',
				array(
					'form'          => $submission->form_key,
					'submission_id' => $submission_id,
					'status'        => 'duplicate',
				)
			);

			return null;
		}

		return self::send( $envelope, $submission->form_key, Send4_Api_Client::for_visitor() );
	}

	/**
	 * Send one envelope, queueing it when the attempt fails in a recoverable way.
	 *
	 * @param array                 $envelope The envelope.
	 * @param string                $form_key Routing key.
	 * @param Send4_Api_Client|null $client   API client, defaults to the interactive one.
	 * @return Send4_Api_Result
	 */
	public static function send( array $envelope, $form_key, $client = null ) {
		$client = $client instanceof Send4_Api_Client ? $client : new Send4_Api_Client();
		$result = $client->submit( $envelope );

		$context = array(
			'form'          => $form_key,
			'submission_id' => isset( $envelope['submission_id'] ) ? $envelope['submission_id'] : '',
			'code'          => $result->code,
		);

		switch ( $result->outcome ) {
			case Send4_Api_Result::DELIVERED:
				$context['run_id'] = $result->run_id;
				Send4_Settings::record_delivery( $form_key, 'delivered' );
				$context['status'] = 'delivered';
				Send4_Logger::log( 'info', 'Submission delivered and routed to a flow.', $context );
				break;

			case Send4_Api_Result::DUPLICATE:
				Send4_Settings::record_delivery( $form_key, 'duplicate' );
				$context['status'] = 'duplicate';
				Send4_Logger::log( 'info', 'Send4 had already received this submission.', $context );
				break;

			case Send4_Api_Result::UNROUTED:
				Send4_Settings::record_delivery( $form_key, 'unrouted', $result->message );
				$context['status'] = 'unrouted';
				Send4_Logger::log( 'warning', 'Delivered, but no flow is listening for this form.', $context );
				break;

			case Send4_Api_Result::PERMANENT:
				$context['error']  = $result->message;
				$context['status'] = 'failed';
				Send4_Settings::record_delivery( $form_key, 'failed', $result->message );

				if ( self::store( $envelope, $form_key, $result->message, Send4_Queue::STATUS_PERMANENT ) ) {
					Send4_Logger::log( 'error', 'Send4 rejected the submission; it will not be retried.', $context );
				} else {
					Send4_Logger::log( 'error', 'Send4 rejected the submission, and it could not be kept on the queue: the lead is lost.', $context );
				}
				break;

			case Send4_Api_Result::TRANSIENT:
			default:
				$context['error'] = $result->message;

				if ( ! self::store( $envelope, $form_key, $result->message, Send4_Queue::STATUS_PENDING, $result->retry_after ) ) {
					// Saying "queued" here would be a lie the admin has no way
					// to catch: nothing is on the queue, and nothing will retry.
					Send4_Settings::record_delivery(
						$form_key,
						'failed',
						__( 'Send4 was unreachable and the submission could not be queued for a retry: the queue table is missing or not writable.', 'send4' )
					);
					$context['status'] = 'failed';
					Send4_Logger::log( 'error', 'Send4 was unreachable and the queue could not store the submission: the lead is lost.', $context );
					break;
				}

				Send4_Settings::record_delivery( $form_key, 'queued', $result->message );
				$context['status'] = 'queued';
				Send4_Logger::log( 'warning', 'Send4 was unreachable; submission queued for retry.', $context );
				Send4_Cron::ensure_scheduled();
				break;
		}

		return $result;
	}

	/**
	 * Put an envelope on the queue, creating the table once if that is why it failed.
	 *
	 * The table can be missing when the schema install failed on activation, or
	 * someone dropped it. Before this the insert failed silently and the lead
	 * was reported as queued while nothing was stored.
	 *
	 * @param array  $envelope    The envelope.
	 * @param string $form_key    Routing key.
	 * @param string $error       Why the attempt failed.
	 * @param string $status      Queue status to store.
	 * @param int    $retry_after Seconds Send4 asked us to wait, 0 for none.
	 * @return bool True when the row is on the queue.
	 */
	private static function store( array $envelope, $form_key, $error, $status, $retry_after = 0 ) {
		$id = Send4_Queue::enqueue( $envelope, $form_key, $error, $status );

		if ( false === $id ) {
			Send4_Queue::install();
			$id = Send4_Queue::enqueue( $envelope, $form_key, $error, $status );
		}

		if ( false === $id ) {
			return false;
		}

		// A 429 names its own wait, and a retry sooner than that only earns
		// another 429 and burns an attempt.
		if ( Send4_Queue::STATUS_PENDING === $status && $retry_after > Send4_Queue::delay_for_attempt( 1 ) ) {
			Send4_Queue::postpone( $id, $retry_after );
		}

		return true;
	}

	/**
	 * Work the queue. Called by WP-Cron, by the dashboard and by WP-CLI.
	 *
	 * Built for the moment it matters most, which is an outage: the token is
	 * exchanged once for the whole batch (sign-in is rate limited to ten a
	 * minute), each row is claimed before it is sent so an overlapping run
	 * cannot send it too, and the batch stops at the first sign Send4 is down
	 * or once TIME_BUDGET is spent, rather than spending a timeout on every row.
	 *
	 * @param int $limit Maximum rows to attempt in one pass.
	 * @return array{attempted:int,delivered:int,failed:int}
	 */
	public static function process_queue( $limit = 20 ) {
		$summary = array(
			'attempted' => 0,
			'delivered' => 0,
			'failed'    => 0,
		);

		if ( ! Send4_Settings::has_credentials() ) {
			// Nothing can be sent, but a pending row must keep the event alive
			// for the moment the site is reconnected.
			self::unschedule_when_idle();

			return $summary;
		}

		// Rows queued under another API client belong to another workspace.
		// Send4 dedupes per client, so sending one here would be accepted as
		// a new lead by the wrong account. Parked, never sent; retention
		// deletes it like any other dead letter.
		$quarantined = Send4_Queue::quarantine_foreign(
			Send4_Queue::current_client_id(),
			__( 'Queued while this site was connected to a different Send4 account, so it was not sent to this one.', 'send4' )
		);

		if ( $quarantined > 0 ) {
			Send4_Logger::log(
				'warning',
				'Queued submissions from a previous Send4 connection were set aside, not sent.',
				array( 'count' => $quarantined )
			);
		}

		$rows = Send4_Queue::due( $limit );

		if ( empty( $rows ) ) {
			self::unschedule_when_idle();

			return $summary;
		}

		$client  = new Send4_Api_Client();
		$token   = $client->token();
		$failure = null;

		if ( is_wp_error( $token ) ) {
			$failure = $client->result_from_token_error( $token );

			if ( $failure->is_retryable() ) {
				// Send4 is down or rate limiting sign-in. Every row would fail
				// the same way, and each one would cost an exchange and an
				// attempt; leave them exactly as they are for the next run.
				// While a Retry-After runs no request was made at all, and
				// that was logged when Send4 asked for it.
				if ( 'send4_sign_in_cooldown' === $token->get_error_code() ) {
					return $summary;
				}

				Send4_Logger::log(
					'warning',
					'Could not sign in to Send4; queued submissions left for the next run.',
					array(
						'code'  => $failure->code,
						'error' => $failure->message,
					)
				);

				return $summary;
			}
		}

		$started = microtime( true );

		/**
		 * Seconds one queue run may spend sending before it leaves the rest.
		 *
		 * @param int $budget Seconds. Default Send4_Delivery::TIME_BUDGET.
		 */
		$budget = (float) apply_filters( 'send4_queue_time_budget', self::TIME_BUDGET );

		foreach ( $rows as $row ) {
			// Checked before every row but the first, so a run always makes
			// progress however small the budget.
			if ( $summary['attempted'] > 0 && ( microtime( true ) - $started ) >= $budget ) {
				break;
			}

			if ( ! Send4_Queue::claim( $row ) ) {
				// Another run took it between our SELECT and now.
				continue;
			}

			$envelope = Send4_Queue::envelope( $row );
			++$summary['attempted'];

			if ( empty( $envelope ) ) {
				Send4_Queue::record_permanent_failure( $row, __( 'The stored envelope could not be read.', 'send4' ) );
				++$summary['failed'];
				continue;
			}

			// A credential that can never work is the same answer for every
			// row, and it is already known: no request needed to learn it.
			$result  = null !== $failure ? $failure : $client->submit( $envelope );
			$context = array(
				'form'          => $row->form_key,
				'submission_id' => $row->submission_id,
				'attempt'       => (int) $row->attempts + 1,
				'code'          => $result->code,
			);

			if ( $result->is_final() ) {
				$status = Send4_Api_Result::UNROUTED === $result->outcome ? 'unrouted' : 'delivered';

				Send4_Queue::delete( (int) $row->id );
				Send4_Settings::record_delivery( $row->form_key, $status, $result->message );
				$context['status'] = $status;
				Send4_Logger::log( 'info', 'Queued submission delivered on retry.', $context );
				++$summary['delivered'];
				continue;
			}

			$context['error'] = $result->message;

			if ( Send4_Api_Result::PERMANENT === $result->outcome ) {
				Send4_Queue::record_permanent_failure( $row, $result->message );
				Send4_Settings::record_delivery( $row->form_key, 'failed', $result->message );
				$context['status'] = 'failed';
				Send4_Logger::log( 'error', 'Queued submission rejected; it will not be retried.', $context );
				++$summary['failed'];
				continue;
			}

			$status = Send4_Queue::record_failure( $row, $result->message, $result->retry_after );
			++$summary['failed'];

			if ( Send4_Queue::STATUS_FAILED === $status ) {
				Send4_Settings::record_delivery( $row->form_key, 'failed', $result->message );
				$context['status'] = 'failed';
				Send4_Logger::log(
					'error',
					sprintf( 'Queued submission still failing; giving up after %d attempts.', Send4_Queue::MAX_ATTEMPTS ),
					$context
				);
			} else {
				$context['status'] = 'queued';
				Send4_Logger::log( 'warning', 'Queued submission still failing; rescheduled.', $context );
			}

			// Unreachable, down, or telling us to slow down: the rows behind
			// this one would get the same answer, each after its own timeout.
			if ( 0 === $result->code || 429 === $result->code || $result->code >= 500 ) {
				break;
			}
		}

		self::unschedule_when_idle();

		return $summary;
	}

	/**
	 * Move this connection's dead letters back to pending and wake the worker.
	 *
	 * Called from the dashboard and `wp send4 requeue`, once whatever made the
	 * rows fail has been fixed.
	 *
	 * @return int Rows requeued.
	 */
	public static function requeue() {
		if ( ! Send4_Settings::has_credentials() || ! Send4_Queue::table_exists() ) {
			return 0;
		}

		$moved = Send4_Queue::requeue( Send4_Queue::current_client_id() );

		if ( $moved > 0 ) {
			Send4_Cron::ensure_scheduled();
			Send4_Logger::log( 'info', 'Failed submissions put back on the queue for another attempt.', array( 'count' => $moved ) );
		}

		return $moved;
	}

	/**
	 * Drop the every-minute event once there is nothing left for it to do.
	 *
	 * An idle site should not wake WP-Cron sixty times an hour. Enqueueing and
	 * requeueing schedule it again, and the daily sweep re-ensures it as a
	 * safety net.
	 *
	 * @return void
	 */
	private static function unschedule_when_idle() {
		if ( ! Send4_Queue::has_pending() ) {
			Send4_Cron::unschedule();
		}
	}
}
