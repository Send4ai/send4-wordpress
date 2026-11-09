<?php
/**
 * WP-CLI commands.
 *
 * These exist so the plugin can be configured without a browser: the hosted
 * test site seeds itself from environment variables on boot, and the local
 * development stack is set up by a Makefile.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the Send4 connection, forms and delivery queue.
 */
class Send4_Cli {

	/**
	 * Register with WP-CLI.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'send4', __CLASS__ );
	}

	/**
	 * Verify API credentials against Send4, then store them.
	 *
	 * Credentials that fail the check are not stored, and neither is --url-base:
	 * whatever the site was connected with before, and wherever, stays in place.
	 *
	 * ## OPTIONS
	 *
	 * --client-id=<id>
	 * : The API client ID from your Send4 dashboard.
	 *
	 * --client-secret=<secret>
	 * : The API client secret. Pass - to read it from STDIN instead, which keeps
	 * : it out of the process arguments on a shared or logged host.
	 *
	 * [--url-base=<url>]
	 * : Override the Send4 URL. Ignored when SEND4_API_BASE_URL is defined.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4 connect --client-id=abc --client-secret=shh
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function connect( $args, $assoc_args ) {
		unset( $args );

		$client_id     = isset( $assoc_args['client-id'] ) ? (string) $assoc_args['client-id'] : '';
		$client_secret = isset( $assoc_args['client-secret'] ) ? (string) $assoc_args['client-secret'] : '';

		if ( '-' === $client_secret ) {
			$client_secret = trim( (string) file_get_contents( 'php://stdin' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( '' === $client_id || '' === $client_secret ) {
			WP_CLI::error( 'Both --client-id and --client-secret are required.' );
		}

		$url_base = ! empty( $assoc_args['url-base'] ) && ! Send4_Settings::base_url_is_locked()
			? untrailingslashit( (string) $assoc_args['url-base'] )
			: '';

		// Checked here for the sake of the message. The request layer refuses
		// such a destination too, but cannot say what an acceptable one looks like.
		if ( '' !== $url_base && ! Send4_Settings::base_url_is_allowed( $url_base ) ) {
			WP_CLI::error( sprintf( '%s is not a Send4 address. Use https://*.send4.ai, or http:// on a local development host.', $url_base ) );
		}

		// The same routine as the Connection screen: the pair is proven before
		// it is stored, and a connect reports the forms to the (possibly new)
		// workspace straight away. The destination goes in with the pair rather
		// than being saved first: saved on its own, a refused pair left the
		// credentials that did work talking to the wrong environment.
		$had_credentials = Send4_Settings::has_credentials();
		$result          = Send4_Admin::establish_connection( $client_id, $client_secret, $url_base );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error(
				$result->get_error_message() . ' ' . (
					$had_credentials
						? 'The new credentials were not saved; this site is still using the ones it had.'
						: 'The credentials were not saved.'
				)
			);
		}

		$name = isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '';

		WP_CLI::success(
			sprintf(
				'Connected to %s at %s. %d flow(s) listening for WordPress forms.',
				$name,
				Send4_Settings::base_url(),
				isset( $result['flows'] ) ? count( $result['flows'] ) : 0
			)
		);
	}

	/**
	 * Run the connection test and print the flows listening for WordPress forms.
	 *
	 * @return void
	 */
	public function handshake() {
		$client = new Send4_Api_Client();
		$result = $client->handshake();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( 'Account: ' . ( isset( $result['client']['name'] ) ? $result['client']['name'] : '(unknown)' ) );

		Send4_Minimum_Version::remember( $result );

		$unmet = Send4_Minimum_Version::unmet();

		if ( null !== $unmet ) {
			WP_CLI::warning( sprintf( 'Send4 asks for version %s or newer of this plugin; this is %s.', $unmet, SEND4_VERSION ) );
		}

		foreach ( Send4_Readiness::failing( $result ) as $check ) {
			WP_CLI::warning( 'Not ready to text leads: ' . $check['message'] . ( '' !== $check['action_url'] ? ' ' . $check['action_url'] : '' ) );
		}

		if ( empty( $result['flows'] ) ) {
			WP_CLI::warning( 'No flow is listening for WordPress forms.' );

			return;
		}

		$rows = array();

		foreach ( $result['flows'] as $flow ) {
			$rows[] = array(
				'flow'     => isset( $flow['flow_name'] ) ? $flow['flow_name'] : '',
				'status'   => isset( $flow['flow_status'] ) ? $flow['flow_status'] : '',
				'form_key' => empty( $flow['form_key'] ) ? '(every form)' : $flow['form_key'],
				'active'   => empty( $flow['is_active'] ) ? 'no' : 'yes',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'flow', 'status', 'form_key', 'active' ) );
	}

	/**
	 * List the forms Send4 can see on this site.
	 *
	 * `validates` is the first column to read when a site owner says their form
	 * "rejects every phone number": yes means the plugin refuses a number
	 * without a country code there, and `picker` whether the visitor was given
	 * a country picker to fix it with.
	 *
	 * @return void
	 */
	public function forms() {
		$rows = array();

		foreach ( Send4_Adapters::detect_forms() as $form ) {
			$state = Send4_Settings::form( $form['key'] );

			$rows[] = array(
				'form_key'  => $form['key'],
				'title'     => $form['title'],
				'plugin'    => $form['adapter_name'],
				'enabled'   => Send4_Settings::form_is_active( $form['key'] ) ? 'yes' : 'no',
				'phone'     => $state['mapping']['phone'],
				'validates' => Send4_Settings::form_validates_phone( $form['key'] ) ? 'yes' : 'no',
				'picker'    => Send4_Settings::form_uses_phone_picker( $form['key'] ) ? 'yes' : 'no',
				'fields'    => implode( ',', wp_list_pluck( $form['fields'], 'id' ) ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No forms detected. Is a supported form plugin active?' );

			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'form_key', 'title', 'plugin', 'enabled', 'phone', 'validates', 'picker', 'fields' ) );
	}

	/**
	 * Map a form's fields and start sending it to Send4.
	 *
	 * ## OPTIONS
	 *
	 * <form_key>
	 * : The form key, e.g. contact-form-7:42. See `wp send4 forms`.
	 *
	 * --phone=<field>
	 * : Field id holding the phone number. Required: Send4 follows up by SMS.
	 *
	 * [--name=<field>]
	 * : Field id holding the lead's name.
	 *
	 * [--email=<field>]
	 * : Field id holding the lead's email address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4 enable contact-form-7:42 --phone=your-phone --name=your-name
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function enable( $args, $assoc_args ) {
		$form_key = isset( $args[0] ) ? (string) $args[0] : '';
		$phone    = isset( $assoc_args['phone'] ) ? (string) $assoc_args['phone'] : '';

		if ( '' === $form_key ) {
			WP_CLI::error( 'A form key is required.' );
		}

		if ( '' === $phone ) {
			WP_CLI::error( 'A --phone field is required: Send4 follows up by SMS.' );
		}

		// By key rather than from the list: the list stops at a ceiling, and a
		// form past it is exactly the one that can only be set up from here.
		$descriptor = Send4_Adapters::find_form( $form_key );

		if ( null === $descriptor ) {
			WP_CLI::error( sprintf( 'No form with key %s. Run `wp send4 forms` to list them.', $form_key ) );
		}

		$ids = wp_list_pluck( $descriptor['fields'], 'id' );

		foreach ( array( 'phone', 'name', 'email' ) as $role ) {
			$value = isset( $assoc_args[ $role ] ) ? (string) $assoc_args[ $role ] : '';

			if ( '' !== $value && ! in_array( $value, $ids, true ) ) {
				WP_CLI::error( sprintf( 'Field "%s" is not on this form. Available: %s', $value, implode( ', ', $ids ) ) );
			}
		}

		Send4_Settings::update_form(
			$form_key,
			array(
				'enabled' => true,
				'name'    => $descriptor['title'],
				'mapping' => array(
					'phone' => $phone,
					'name'  => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
					'email' => isset( $assoc_args['email'] ) ? (string) $assoc_args['email'] : '',
				),
			)
		);

		// As the admin screen does: the mapping decides which fields Send4
		// sees as `lead.*`, and the form now reports as on.
		Send4_Forms_Sync::maybe_push();

		WP_CLI::success( sprintf( '%s is now sending to Send4.', $form_key ) );
	}

	/**
	 * Stop sending a form to Send4.
	 *
	 * ## OPTIONS
	 *
	 * <form_key>
	 * : The form key.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function disable( $args ) {
		$form_key = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $form_key ) {
			WP_CLI::error( 'A form key is required.' );
		}

		// Same rule as the admin screen: a typo must not create a junk form,
		// but a stored form whose plugin is deactivated can still be turned off.
		if ( ! Send4_Settings::has_form( $form_key ) && ! $this->is_detected( $form_key ) ) {
			WP_CLI::error( sprintf( 'No form with key %s. Run `wp send4 forms` to list them.', $form_key ) );
		}

		Send4_Settings::update_form( $form_key, array( 'enabled' => false ) );
		Send4_Forms_Sync::maybe_push();

		WP_CLI::success( sprintf( '%s is no longer sending to Send4.', $form_key ) );
	}

	/**
	 * True when a form plugin currently reports this form.
	 *
	 * @param string $form_key Form key.
	 * @return bool
	 */
	private function is_detected( $form_key ) {
		return null !== Send4_Adapters::find_form( $form_key );
	}

	/**
	 * Report this site's detected forms to Send4.
	 *
	 * Runs automatically on connect, whenever a mapping changes, and once a
	 * day. This is the manual version, for a form that was just created in the
	 * CMS — nothing tells the plugin about that.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Send even when nothing has changed since the last report.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function sync_forms( $args, $assoc_args ) {
		unset( $args );

		$force  = isset( $assoc_args['force'] );
		$result = Send4_Forms_Sync::maybe_push( $force );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( null === $result ) {
			if ( ! Send4_Settings::has_credentials() ) {
				WP_CLI::error( 'This site is not connected to Send4.' );
			}

			WP_CLI::success( 'Nothing changed since the last report. Use --force to send anyway.' );

			return;
		}

		WP_CLI::success( sprintf( 'Reported %d form(s) to Send4.', count( Send4_Form_Descriptors::build() ) ) );
	}

	/**
	 * Show what is waiting in the retry queue.
	 *
	 * @return void
	 */
	public function queue() {
		if ( ! Send4_Queue::table_exists() ) {
			WP_CLI::error( 'The Send4 queue table is missing. Deactivate and reactivate the plugin.' );
		}

		$rows = array();

		foreach ( Send4_Queue::all( 50 ) as $row ) {
			$rows[] = array(
				'id'            => $row->id,
				'submission_id' => $row->submission_id,
				'form_key'      => $row->form_key,
				'status'        => $row->status,
				'attempts'      => $row->attempts,
				'next_attempt'  => $row->next_attempt_at,
				'last_error'    => (string) $row->last_error,
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'The queue is empty.' );

			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'id', 'submission_id', 'form_key', 'status', 'attempts', 'next_attempt', 'last_error' )
		);
	}

	/**
	 * Attempt every queued submission that is due.
	 *
	 * @return void
	 */
	public function run_queue() {
		$summary = Send4_Delivery::process_queue( 100 );

		WP_CLI::success(
			sprintf(
				'Attempted %d, delivered %d, still failing %d.',
				$summary['attempted'],
				$summary['delivered'],
				$summary['failed']
			)
		);
	}

	/**
	 * Put failed submissions back on the queue.
	 *
	 * For after the cause is fixed: an ability ticked, a key replaced, the
	 * destination corrected. Only submissions queued under the current Send4
	 * connection are resent; another account's are never sent to this one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4 requeue && wp send4 run_queue
	 *
	 * @return void
	 */
	public function requeue() {
		if ( ! Send4_Settings::has_credentials() ) {
			WP_CLI::error( 'This site is not connected to Send4.' );
		}

		if ( ! Send4_Queue::table_exists() ) {
			WP_CLI::error( 'The Send4 queue table is missing. Deactivate and reactivate the plugin.' );
		}

		$moved = Send4_Delivery::requeue();

		WP_CLI::success( sprintf( 'Requeued %d failed submission(s). They are due now; run `wp send4 run_queue` to send them immediately.', $moved ) );
	}

	/**
	 * Print a one-screen summary of the plugin's state.
	 *
	 * @return void
	 */
	public function status() {
		$connection = Send4_Settings::connection();
		$counts     = Send4_Queue::table_exists() ? Send4_Queue::counts() : array();

		WP_CLI::log( 'Send4 URL:     ' . Send4_Settings::base_url() );
		WP_CLI::log( 'Client ID:     ' . ( '' !== $connection['client_id'] ? $connection['client_id'] : '(not set)' ) );
		WP_CLI::log( 'Account:       ' . ( '' !== $connection['client_name'] ? $connection['client_name'] : '(not connected)' ) );
		WP_CLI::log( 'Queue table:   ' . ( Send4_Queue::table_exists() ? Send4_Queue::table() : 'MISSING' ) );

		foreach ( $counts as $status => $total ) {
			WP_CLI::log( sprintf( '  %-16s %d', $status, $total ) );
		}

		$next = wp_next_scheduled( Send4_Cron::HOOK );
		WP_CLI::log( 'Next retry:    ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) );

		foreach ( Send4_Adapters::all() as $adapter ) {
			WP_CLI::log( sprintf( '%-16s %s', $adapter->name() . ':', $adapter->is_available() ? 'active' : 'not installed' ) );
		}
	}
}
