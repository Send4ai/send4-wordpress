<?php
/**
 * Queue rows belong to the Send4 connection they were queued under.
 *
 * @package Send4
 */

/**
 * Send4 dedupes a submission per API client, so a row queued while connected
 * to workspace A and sent after reconnecting to workspace B is a brand new
 * lead in B's account. These tests pin down that it never is, and that the
 * rows which were dead-lettered can be brought back once the cause is fixed.
 */
class Send4_Queue_Connection_Test extends Send4_Test_Case {

	const FORM_KEY = 'gravityforms:7';

	/**
	 * Log in as someone who may configure Send4, with a clean request.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear the request superglobals the admin handlers read.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Put a row on the queue.
	 *
	 * @param string      $suffix    Makes the submission id unique.
	 * @param string      $status    Row status.
	 * @param string|null $client_id Owning client, null for the current connection.
	 * @return object The row.
	 */
	private function seed( $suffix, $status = Send4_Queue::STATUS_PENDING, $client_id = null ) {
		$envelope = array(
			'submission_id' => 'gravityforms:7:512:' . $suffix,
			'site_url'      => 'http://example.org/',
			'form'          => array(
				'key'  => self::FORM_KEY,
				'name' => 'Roof quote',
			),
			'lead'          => array( 'phone' => '+37060012345' ),
			'data'          => array(),
		);

		Send4_Queue::enqueue( $envelope, self::FORM_KEY, 'Send4 returned status 500.', $status, $client_id );

		$row = Send4_Queue::find_by_submission_id( $envelope['submission_id'] );

		$this->makeDue( $row );

		return Send4_Queue::find_by_submission_id( $envelope['submission_id'] );
	}

	/**
	 * Switch the site to a different API client, as a reconnect would.
	 *
	 * @param string $client_id New client id.
	 * @return void
	 */
	private function reconnectAs( $client_id ) {
		Send4_Settings::update_connection(
			array(
				'client_id'     => $client_id,
				'client_secret' => 'another-secret',
			)
		);
		Send4_Api_Client::forget_token();
	}

	/**
	 * Run an admin-post handler and return where it redirected.
	 *
	 * @param string $action Nonce action, also the handler's suffix.
	 * @return string
	 */
	private function post( $action ) {
		$_POST    = array( '_wpnonce' => wp_create_nonce( $action ) );
		$_REQUEST = $_POST;

		$spy = static function ( $location ) {
			throw new Send4_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$method = 'handle_' . substr( $action, strlen( 'send4_' ) );
			( new Send4_Admin() )->$method();
		} catch ( Send4_Redirected $redirect ) {
			return rawurldecode( $redirect->location );
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->fail( 'The handler always redirects.' );

		return '';
	}

	/**
	 * Every row records the client it was queued under.
	 *
	 * @return void
	 */
	public function test_a_row_records_the_client_it_was_queued_under() {
		$this->assertSame( 'test-client-id', $this->seed( 'a' )->client_id );
	}

	/**
	 * The failure this whole column exists for.
	 *
	 * @return void
	 */
	public function test_a_row_queued_under_another_client_is_never_sent() {
		$this->seed( 'a' );
		$this->reconnectAs( 'workspace-b' );

		$this->http->fallback( Send4_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		$summary = Send4_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $row->status, 'Quarantined as a dead letter, so retention removes it.' );
		$this->assertStringContainsString( 'different Send4 account', $row->last_error );
		$this->assertSame( 'test-client-id', $row->client_id, 'It still says whose it was.' );
	}

	/**
	 * Rows from before the column existed carry '' and belong to whoever is
	 * connected — before this change nothing could have switched account
	 * under them without their being sent to the new one anyway.
	 *
	 * @return void
	 */
	public function test_a_row_from_before_the_upgrade_is_sent_under_the_current_connection() {
		$this->seed( 'a', Send4_Queue::STATUS_PENDING, '' );

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4_Delivery::process_queue()['delivered'] );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * Disconnecting binds those unowned rows to the connection being closed,
	 * so reconnecting to a different workspace cannot send them there...
	 *
	 * @return void
	 */
	public function test_disconnecting_binds_unowned_rows_to_the_old_connection() {
		$this->seed( 'a', Send4_Queue::STATUS_PENDING, '' );

		$this->post( 'send4_disconnect' );

		$this->assertSame( 'test-client-id', $this->onlyQueueRow()->client_id );

		$this->reconnectAs( 'workspace-b' );
		$this->http->fallback( Send4_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		Send4_Delivery::process_queue();

		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
	}

	/**
	 * ...while reconnecting the same API client still delivers them.
	 *
	 * @return void
	 */
	public function test_reconnecting_the_same_client_still_delivers_the_queue() {
		$this->seed( 'a', Send4_Queue::STATUS_PENDING, '' );

		$this->post( 'send4_disconnect' );
		$this->reconnectAs( 'test-client-id' );

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4_Delivery::process_queue()['delivered'] );
	}

	/**
	 * Disconnect is not the only way out of a connection. Pasting another
	 * account's key, coming back from the Connect button, or `wp send4 connect`
	 * replaces the credentials while the site is still connected, and none of
	 * them went through the step above: a lead queued before the upgrade was
	 * delivered to whichever account was connected next.
	 *
	 * @return void
	 */
	public function test_connecting_over_a_live_connection_binds_unowned_rows_to_the_old_one() {
		$this->seed( 'a', Send4_Queue::STATUS_PENDING, '' );

		$this->http->queueToken( 'workspace-b-token' );
		$this->http->queueHandshake( array(), 'Workspace B' );
		// The forms report every new connection starts with.
		$this->http->queueToken( 'workspace-b-token' );
		$this->http->queue( Send4_Http_Recorder::response( 200, array( 'data' => array( 'accepted' => 0 ) ) ) );

		$this->assertIsArray( Send4_Admin::establish_connection( 'workspace-b', 'another-secret' ) );

		$this->assertSame( 'workspace-b', Send4_Settings::connection()['client_id'] );
		$this->assertSame( 'test-client-id', $this->onlyQueueRow()->client_id, 'The lead belongs to the account it was captured for.' );

		$this->http->fallback( Send4_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		Send4_Delivery::process_queue();

		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/submissions' ) );
		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
	}

	/**
	 * A credential that is refused changes nothing, the queue included.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_leaves_unowned_rows_alone() {
		$this->seed( 'a', Send4_Queue::STATUS_PENDING, '' );

		$this->http->queueTokenRejected( 401 );

		$this->assertWPError( Send4_Admin::establish_connection( 'typo-id', 'typo-secret' ) );
		$this->assertSame( '', $this->onlyQueueRow()->client_id );
	}

	/**
	 * Requeue moves this connection's dead letters back to pending, with a
	 * fresh set of attempts, due now — and nobody else's.
	 *
	 * @return void
	 */
	public function test_requeue_revives_this_connections_dead_letters_only() {
		$mine     = $this->seed( 'mine', Send4_Queue::STATUS_PERMANENT );
		$unowned  = $this->seed( 'unowned', Send4_Queue::STATUS_FAILED, '' );
		$theirs   = $this->seed( 'theirs', Send4_Queue::STATUS_PERMANENT, 'workspace-b' );
		$retrying = $this->seed( 'retrying' );

		Send4_Cron::unschedule();

		$this->assertSame( 2, Send4_Delivery::requeue() );

		foreach ( array( $mine, $unowned ) as $row ) {
			$after = Send4_Queue::find_by_submission_id( $row->submission_id );

			$this->assertSame( Send4_Queue::STATUS_PENDING, $after->status );
			$this->assertSame( 0, (int) $after->attempts );
			$this->assertLessThanOrEqual( time(), strtotime( $after->next_attempt_at . ' UTC' ) );
		}

		$this->assertSame( Send4_Queue::STATUS_PERMANENT, Send4_Queue::find_by_submission_id( $theirs->submission_id )->status );
		$this->assertSame( Send4_Queue::STATUS_PENDING, Send4_Queue::find_by_submission_id( $retrying->submission_id )->status );

		$this->assertNotFalse( wp_next_scheduled( Send4_Cron::HOOK ), 'Requeued rows need the worker scheduled.' );
	}

	/**
	 * A requeued row goes out on the next run, as the same submission.
	 *
	 * @return void
	 */
	public function test_a_requeued_row_is_delivered_on_the_next_run() {
		$row = $this->seed( 'a', Send4_Queue::STATUS_PERMANENT );

		Send4_Delivery::requeue();

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4_Delivery::process_queue()['delivered'] );
		$this->assertSame( $row->submission_id, $this->http->lastBody()['submission_id'] );
	}

	/**
	 * The dashboard button.
	 *
	 * @return void
	 */
	public function test_the_requeue_admin_action_reports_what_it_moved() {
		$this->seed( 'a', Send4_Queue::STATUS_PERMANENT );
		$this->seed( 'b', Send4_Queue::STATUS_FAILED );

		$location = $this->post( 'send4_requeue' );

		$this->assertStringContainsString( 'send4_notice=success', $location );
		$this->assertStringContainsString( '2 failed submissions will be resent', $this->noticeAt( $location ) );
		$this->assertSame( 2, Send4_Queue::counts()[ Send4_Queue::STATUS_PENDING ] );
	}

	/**
	 * It is registered, and guarded like every other admin-post action.
	 *
	 * @return void
	 */
	public function test_the_requeue_admin_action_needs_the_nonce() {
		$admin = new Send4_Admin();
		$admin->boot();

		$this->assertNotFalse( has_action( 'admin_post_send4_requeue', array( $admin, 'handle_requeue' ) ) );

		$this->seed( 'a', Send4_Queue::STATUS_PERMANENT );

		$_POST    = array( '_wpnonce' => 'forged' );
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );

		try {
			$admin->handle_requeue();
		} finally {
			$this->assertSame( Send4_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
		}
	}

	/**
	 * The same submission queued again after a reconnect is the new account's
	 * own row. Reviving the old account's row kept its payload, so the worker
	 * sent what was built for one account to the other.
	 *
	 * @return void
	 */
	public function test_a_submission_queued_again_under_another_account_gets_its_own_row() {
		$submission_id = 'gravityforms:7:512:replayed';
		$theirs        = array(
			'submission_id' => $submission_id,
			'form'          => array( 'key' => self::FORM_KEY ),
			'lead'          => array( 'phone' => '+37060000001' ),
		);
		$ours          = array(
			'submission_id' => $submission_id,
			'form'          => array( 'key' => self::FORM_KEY ),
			'lead'          => array( 'phone' => '+37060000002' ),
		);

		$their_id = Send4_Queue::enqueue( $theirs, self::FORM_KEY, 'missing ability', Send4_Queue::STATUS_PERMANENT );

		$this->reconnectAs( 'client-b' );

		$our_id = Send4_Queue::enqueue( $ours, self::FORM_KEY, 'Send4 returned status 503.' );

		$this->assertIsInt( $our_id );
		$this->assertNotSame( $their_id, $our_id );

		$their_row = Send4_Queue::find_by_submission_id( $submission_id, 'test-client-id' );
		$our_row   = Send4_Queue::find_by_submission_id( $submission_id, 'client-b' );

		$this->assertSame( Send4_Queue::STATUS_PERMANENT, $their_row->status );
		$this->assertSame( '+37060000001', Send4_Queue::envelope( $their_row )['lead']['phone'] );
		$this->assertSame( 'client-b', $our_row->client_id );
		$this->assertSame( '+37060000002', Send4_Queue::envelope( $our_row )['lead']['phone'] );

		$this->makeDue( $our_row );
		$this->http->queueToken();
		$this->http->queue202();

		Send4_Delivery::process_queue();

		$sent = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $sent );
		$this->assertSame( '+37060000002', $sent[0]['body']['lead']['phone'] );
		$this->assertSame( Send4_Queue::STATUS_PERMANENT, Send4_Queue::find_by_submission_id( $submission_id, 'test-client-id' )->status );
	}

	/**
	 * The notice the screen at this URL would show.
	 *
	 * Read the way the screen reads it rather than out of the URL, so the test
	 * does not care whether the text travels in the query string or, as #0686
	 * arranges, in a per-user transient behind a marker.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	private function noticeAt( $location ) {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$saved = $_GET;

		wp_parse_str( $query, $_GET );

		try {
			$notice = ( new Send4_Admin() )->current_notice();
		} finally {
			$_GET = $saved;
		}

		return is_array( $notice ) ? $notice['message'] : '';
	}
}
