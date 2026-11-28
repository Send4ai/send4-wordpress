<?php
/**
 * Send4 on the Site Health screens.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two tests under Tools → Site Health, and a Send4 section under its Info tab.
 *
 * Everything is drawn from what the plugin already holds: the stored
 * connection, the last handshake while it is still cached, the minimum version
 * Send4 last asked for, and the queue table. Nothing here asks Send4 anything,
 * so opening Site Health, or WordPress running its weekly check, sends no
 * request.
 *
 * The Info section is `wp send4 status` for a site owner with no shell: the
 * facts a support request needs. The account name and the client id are
 * marked private, which keeps them out of "Copy site info to clipboard". No
 * secret, submission or field value is shown anywhere.
 */
class Send4_Site_Health {

	/**
	 * How late the queue's event may be before it counts as not running.
	 *
	 * The event is due every minute. Ten minutes is far beyond a busy site's
	 * jitter and well inside the first hour, when most retries succeed.
	 */
	const OVERDUE_SECONDS = 600;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	/**
	 * Add the two tests.
	 *
	 * Direct tests, as neither waits on the network.
	 *
	 * @param mixed $tests Site Health tests, by kind.
	 * @return mixed
	 */
	public static function register_tests( $tests ) {
		if ( ! is_array( $tests ) || ( isset( $tests['direct'] ) && ! is_array( $tests['direct'] ) ) ) {
			return $tests;
		}

		$tests['direct']['send4_connection'] = array(
			'label' => __( 'Send4 connection', 'send4' ),
			'test'  => array( __CLASS__, 'test_connection' ),
		);

		$tests['direct']['send4_queue'] = array(
			'label' => __( 'Send4 retry queue', 'send4' ),
			'test'  => array( __CLASS__, 'test_queue' ),
		);

		return $tests;
	}

	/**
	 * Is the site connected, and is anything known to be wrong with that?
	 *
	 * A failed handshake is only cached for a minute, so "refused" and
	 * "unreachable" show here just after a Send4 screen found them. What
	 * always shows is a plugin that is active and connected to nothing, and a
	 * plugin older than Send4 asks for.
	 *
	 * @return array<string,mixed>
	 */
	public static function test_connection(): array {
		$state      = Send4_Admin::connection_state( Send4_Admin::cached_handshake() );
		$connection = Send4_Settings::connection();
		$screen     = self::screen_url( Send4_Admin::CONNECTION_SLUG );

		if ( 'disconnected' === $state ) {
			return self::result(
				'send4_connection',
				'recommended',
				__( 'Send4 is not connected', 'send4' ),
				__( 'The Send4 plugin is active but not connected to a Send4 account, so no form submission is sent to Send4 and nobody is texted.', 'send4' ),
				$screen,
				__( 'Connect to Send4', 'send4' )
			);
		}

		if ( 'rejected' === $state ) {
			return self::result(
				'send4_connection',
				'critical',
				__( 'Send4 refused this site\'s connection', 'send4' ),
				Send4_Admin::outage_message( $state ),
				$screen,
				__( 'Open the Connection screen', 'send4' )
			);
		}

		if ( 'unreachable' === $state ) {
			return self::result(
				'send4_connection',
				'recommended',
				__( 'Send4 could not be reached', 'send4' ),
				Send4_Admin::outage_message( $state ),
				$screen,
				__( 'Open the Connection screen', 'send4' )
			);
		}

		$minimum = Send4_Minimum_Version::unmet();

		if ( null !== $minimum ) {
			$stored = Send4_Minimum_Version::stored();

			return self::result(
				'send4_connection',
				'recommended',
				__( 'Send4 asks for a newer version of its plugin', 'send4' ),
				null !== $stored && '' !== $stored['message']
					? $stored['message']
					: sprintf(
						/* translators: %s: the minimum plugin version Send4 asks for, e.g. 0.3.0. */
						__( 'Send4 needs version %s or newer of this plugin. Update it from Dashboard → Updates. Your forms keep sending in the meantime.', 'send4' ),
						$minimum
					),
				is_multisite() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' ),
				__( 'Go to Updates', 'send4' )
			);
		}

		$description = '' !== $connection['client_name']
			? sprintf(
				/* translators: %s: the name of the Send4 account. */
				__( 'This site is connected to the Send4 account %s.', 'send4' ),
				$connection['client_name']
			)
			: __( 'This site is connected to a Send4 account.', 'send4' );

		// Said plainly, because "connected" here means "holds credentials":
		// this screen never asks Send4 whether they still work.
		$description .= ' ' . __( 'Site Health does not contact Send4. To check that Send4 answers, press Test connection on the Connection screen.', 'send4' );

		return self::result(
			'send4_connection',
			'good',
			__( 'Send4 is connected', 'send4' ),
			$description,
			$screen,
			__( 'Open the Connection screen', 'send4' )
		);
	}

	/**
	 * Is anything stuck between this site and Send4?
	 *
	 * In order of how much is at stake: no table to hold a lead in, leads
	 * waiting with nothing running to send them, leads that were never
	 * delivered, leads on their way.
	 *
	 * @return array<string,mixed>
	 */
	public static function test_queue(): array {
		$dashboard = self::screen_url( Send4_Admin::PAGE_SLUG );

		if ( ! Send4_Queue::table_exists() ) {
			return self::result(
				'send4_queue',
				'critical',
				__( 'The Send4 retry queue table is missing', 'send4' ),
				__( 'Send4 keeps a submission in this table when it cannot be delivered straight away. Without the table, a lead submitted while Send4 is unreachable is lost. Deactivate the Send4 plugin and activate it again to create the table; your connection and form settings are kept.', 'send4' ),
				admin_url( 'plugins.php' ),
				__( 'Go to Plugins', 'send4' )
			);
		}

		$counts   = Send4_Queue::counts();
		$pending  = $counts[ Send4_Queue::STATUS_PENDING ];
		$gave_up  = $counts[ Send4_Queue::STATUS_FAILED ];
		$rejected = $counts[ Send4_Queue::STATUS_PERMANENT ];

		if ( $pending > 0 && self::retries_are_stalled() ) {
			return self::result(
				'send4_queue',
				'critical',
				__( 'Send4 submissions are waiting and nothing is sending them', 'send4' ),
				sprintf(
					/* translators: %s: a number of form submissions. */
					_n(
						'%s form submission is waiting to be sent to Send4, and the scheduled task that sends it is not running. This usually means WP-Cron is disabled on this site, or nothing is triggering it.',
						'%s form submissions are waiting to be sent to Send4, and the scheduled task that sends them is not running. This usually means WP-Cron is disabled on this site, or nothing is triggering it.',
						$pending,
						'send4'
					),
					number_format_i18n( $pending )
				),
				$dashboard,
				__( 'Open the Send4 dashboard', 'send4' )
			);
		}

		if ( $gave_up + $rejected > 0 ) {
			return self::result(
				'send4_queue',
				'recommended',
				__( 'Some form submissions were not delivered to Send4', 'send4' ),
				sprintf(
					/* translators: 1: a number of form submissions. 2: another number of form submissions. */
					__( 'Ran out of retries while Send4 was unreachable: %1$s. Refused by Send4: %2$s. The Send4 dashboard lists them with the reason, and can put them back on the queue.', 'send4' ),
					number_format_i18n( $gave_up ),
					number_format_i18n( $rejected )
				),
				$dashboard,
				__( 'Open the Send4 dashboard', 'send4' )
			);
		}

		if ( $pending > 0 ) {
			return self::result(
				'send4_queue',
				'good',
				__( 'Send4 is retrying form submissions', 'send4' ),
				sprintf(
					/* translators: %s: a number of form submissions. */
					_n(
						'%s form submission could not be delivered straight away and is being retried.',
						'%s form submissions could not be delivered straight away and are being retried.',
						$pending,
						'send4'
					),
					number_format_i18n( $pending )
				),
				$dashboard,
				__( 'Open the Send4 dashboard', 'send4' )
			);
		}

		return self::result(
			'send4_queue',
			'good',
			__( 'No form submission is waiting to be sent to Send4', 'send4' ),
			__( 'A submission Send4 cannot take straight away is kept in a queue on this site and retried for about a day and a half. The queue is empty.', 'send4' )
		);
	}

	/**
	 * Add the Send4 section to Site Health → Info.
	 *
	 * @param mixed $info Debug information, by section.
	 * @return mixed
	 */
	public static function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$connection = Send4_Settings::connection();
		$state      = Send4_Admin::connection_state( Send4_Admin::cached_handshake() );
		$has_table  = Send4_Queue::table_exists();
		$counts     = $has_table ? Send4_Queue::counts() : array();
		$next       = wp_next_scheduled( Send4_Cron::HOOK );
		$minimum    = Send4_Minimum_Version::stored();
		$forms      = array_keys( Send4_Settings::forms() );
		$sending    = count( array_filter( $forms, array( 'Send4_Settings', 'form_is_active' ) ) );

		$fields = array(
			'version'         => array(
				'label' => __( 'Plugin version', 'send4' ),
				'value' => SEND4_VERSION,
			),
			'connection'      => array(
				'label' => __( 'Connection', 'send4' ),
				'value' => self::state_label( $state ),
				'debug' => $state,
			),
			'account'         => array(
				'label'   => __( 'Account', 'send4' ),
				'value'   => '' !== $connection['client_name'] ? $connection['client_name'] : __( 'None', 'send4' ),
				'private' => true,
			),
			'client_id'       => array(
				'label'   => __( 'Client ID', 'send4' ),
				'value'   => '' !== $connection['client_id'] ? $connection['client_id'] : __( 'None', 'send4' ),
				'private' => true,
			),
			'api_url'         => array(
				'label' => __( 'Send4 URL', 'send4' ),
				'value' => Send4_Settings::base_url(),
			),
			'minimum_version' => array(
				'label' => __( 'Plugin version Send4 asks for', 'send4' ),
				'value' => null !== $minimum && '' !== $minimum['minimum_version'] ? $minimum['minimum_version'] : __( 'None', 'send4' ),
				'debug' => null !== $minimum && '' !== $minimum['minimum_version'] ? $minimum['minimum_version'] : 'none',
			),
			'forms'           => array(
				'label' => __( 'Forms sending to Send4', 'send4' ),
				'value' => sprintf(
					/* translators: 1: the number of forms that send to Send4. 2: the number of forms with Send4 settings. */
					__( '%1$s of %2$s set up', 'send4' ),
					number_format_i18n( $sending ),
					number_format_i18n( count( $forms ) )
				),
				'debug' => $sending . ' of ' . count( $forms ),
			),
			'queue_table'     => array(
				'label' => __( 'Queue table', 'send4' ),
				'value' => $has_table ? Send4_Queue::table() : __( 'Missing', 'send4' ),
				'debug' => $has_table ? Send4_Queue::table() : 'missing',
			),
		);

		// One line, not three: WordPress prints a bare 0 as "undefined" in the
		// text "Copy site info to clipboard" produces, and an empty queue is the
		// usual case. The debug text uses the names `wp send4 queue` prints.
		if ( $has_table ) {
			$fields['queue'] = array(
				'label' => __( 'Retry queue', 'send4' ),
				'value' => sprintf(
					/* translators: 1: submissions being retried. 2: submissions that ran out of retries. 3: submissions Send4 refused. */
					__( 'Being retried: %1$s. Ran out of retries: %2$s. Refused by Send4: %3$s.', 'send4' ),
					number_format_i18n( $counts[ Send4_Queue::STATUS_PENDING ] ),
					number_format_i18n( $counts[ Send4_Queue::STATUS_FAILED ] ),
					number_format_i18n( $counts[ Send4_Queue::STATUS_PERMANENT ] )
				),
				'debug' => sprintf(
					'%s %d, %s %d, %s %d',
					Send4_Queue::STATUS_PENDING,
					$counts[ Send4_Queue::STATUS_PENDING ],
					Send4_Queue::STATUS_FAILED,
					$counts[ Send4_Queue::STATUS_FAILED ],
					Send4_Queue::STATUS_PERMANENT,
					$counts[ Send4_Queue::STATUS_PERMANENT ]
				),
			);
		}

		$fields['next_retry'] = array(
			'label' => __( 'Next retry run', 'send4' ),
			'value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : __( 'Not scheduled', 'send4' ),
			'debug' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled',
		);

		foreach ( Send4_Adapters::all() as $adapter ) {
			$fields[ 'adapter_' . sanitize_key( $adapter->slug() ) ] = array(
				'label' => $adapter->name(),
				'value' => $adapter->is_available() ? __( 'Active', 'send4' ) : __( 'Not installed', 'send4' ),
				'debug' => $adapter->is_available() ? 'active' : 'not installed',
			);
		}

		$info['send4'] = array(
			'label'  => __( 'Send4', 'send4' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Are rows waiting with nothing about to send them?
	 *
	 * True when the queue's event is not scheduled at all, or was due long
	 * enough ago that WP-Cron is evidently not firing. Only meaningful while
	 * something is pending: an empty queue schedules nothing.
	 *
	 * @return bool
	 */
	private static function retries_are_stalled(): bool {
		$next = wp_next_scheduled( Send4_Cron::HOOK );

		return false === $next || ( time() - (int) $next ) > self::OVERDUE_SECONDS;
	}

	/**
	 * A connection_state() value in words.
	 *
	 * @param string $state A Send4_Admin::connection_state() value.
	 * @return string
	 */
	private static function state_label( string $state ): string {
		switch ( $state ) {
			case 'disconnected':
				return __( 'Not connected', 'send4' );
			case 'connected':
				return __( 'Connected', 'send4' );
			case 'unreachable':
				return __( 'Send4 could not be reached', 'send4' );
			case 'rejected':
				return __( 'Send4 refused the connection', 'send4' );
			default:
				return __( 'Connected, not checked in the last few minutes', 'send4' );
		}
	}

	/**
	 * One of the plugin's own screens.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private static function screen_url( string $slug ): string {
		return add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
	}

	/**
	 * A test result in the shape Site Health draws.
	 *
	 * Description and action are HTML there, so both are escaped here: the
	 * description can be Send4's own message from the network.
	 *
	 * @param string $test         Test identifier.
	 * @param string $status       good, recommended or critical.
	 * @param string $label        Headline.
	 * @param string $description  One paragraph, plain text.
	 * @param string $action_url   Where the action leads, or '' for none.
	 * @param string $action_label The action's link text.
	 * @return array<string,mixed>
	 */
	private static function result( string $test, string $status, string $label, string $description, string $action_url = '', string $action_label = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Send4', 'send4' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '' !== $action_url
				? '<p><a href="' . esc_url( $action_url ) . '">' . esc_html( $action_label ) . '</a></p>'
				: '',
			'test'        => $test,
		);
	}
}
