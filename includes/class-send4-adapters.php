<?php
/**
 * The adapter registry.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knows which form plugins are present and routes their submissions to delivery.
 */
class Send4_Adapters {

	/** Forms an adapter reads per query while it lists a site's forms. */
	const DETECTION_PAGE = 100;

	/**
	 * Forms one adapter lists before it stops. See detection_limit().
	 */
	const DETECTION_LIMIT = 500;

	/** Transient, per adapter, that keeps the "not every form is listed" warning to one a day. */
	const CAPPED_NOTICE = 'send4_detection_capped_';

	/**
	 * Instantiated adapters, keyed by slug.
	 *
	 * @var Send4_Form_Adapter[]|null
	 */
	private static $adapters = null;

	/**
	 * What detect_forms() found, kept for the rest of this request.
	 *
	 * @var array<int,array>|null
	 */
	private static $forms = null;

	/**
	 * Slugs of the adapters whose list stopped at the ceiling in that answer.
	 *
	 * @var string[]
	 */
	private static $capped = array();

	/**
	 * What changes a form, and so empties that answer. Contact Form 7 and
	 * WPForms keep forms as posts, Elementor keeps them in post meta, and
	 * Gravity Forms in tables of its own with hooks of its own.
	 *
	 * @var string[]
	 */
	private static $form_changes = array(
		'save_post',
		'deleted_post',
		'added_post_meta',
		'updated_post_meta',
		'deleted_post_meta',
		'gform_after_save_form',
		'gform_after_delete_form',
		'gform_forms_post_import',
	);

	/**
	 * Every adapter, whether or not its form plugin is active.
	 *
	 * @return Send4_Form_Adapter[]
	 */
	public static function all() {
		if ( null !== self::$adapters ) {
			return self::$adapters;
		}

		$adapters = array(
			new Send4_Adapter_Cf7(),
			new Send4_Adapter_Wpforms(),
			new Send4_Adapter_Gravityforms(),
			new Send4_Adapter_Elementor(),
		);

		/**
		 * Filter the adapter list.
		 *
		 * @param Send4_Form_Adapter[] $adapters Adapters.
		 */
		$adapters = apply_filters( 'send4_form_adapters', $adapters );

		self::$adapters = array();

		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof Send4_Form_Adapter ) {
				self::$adapters[ $adapter->slug() ] = $adapter;
			}
		}

		return self::$adapters;
	}

	/**
	 * Only the adapters whose form plugin is active.
	 *
	 * @return Send4_Form_Adapter[]
	 */
	public static function available() {
		return array_filter(
			self::all(),
			static function ( Send4_Form_Adapter $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * One adapter by slug.
	 *
	 * @param string $slug Adapter slug.
	 * @return Send4_Form_Adapter|null
	 */
	public static function get( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * The adapter that owns a form key.
	 *
	 * @param string $form_key Form key.
	 * @return Send4_Form_Adapter|null
	 */
	public static function for_form_key( $form_key ) {
		$parts = explode( ':', (string) $form_key, 2 );

		return self::get( $parts[0] );
	}

	/**
	 * Forms detected across every active form plugin.
	 *
	 * Worked out once per request (#0697). Each adapter queries its plugin,
	 * and Elementor's pattern-matches every page's builder data and decodes
	 * it; the forms screen asked two or three times per load and saving one
	 * mapping three times. Any write that could change a form (see
	 * $form_changes) empties the answer, so a request that creates a form and
	 * then lists them still sees it.
	 *
	 * @return array<int,array>
	 */
	public static function detect_forms() {
		if ( null !== self::$forms ) {
			return self::$forms;
		}

		$forms  = array();
		$capped = array();

		foreach ( self::available() as $adapter ) {
			$found = $adapter->detect_forms();

			foreach ( $found as $form ) {
				$forms[] = self::describe( $form, $adapter );
			}

			if ( self::stopped_early( $adapter, $found ) ) {
				$capped[ $adapter->slug() ] = $adapter;
			}
		}

		// An adapter that stopped at the ceiling may have left out a form that
		// is set up here, and the forms list, the dashboard and what Send4 is
		// told are all built from this answer: none of them may say a site's
		// live form has vanished because a query had a limit. So those are
		// looked up one by one. Only for an adapter that did stop, which keeps
		// an ordinary site from paying a query for every form it once deleted.
		if ( ! empty( $capped ) ) {
			$listed = array();

			foreach ( $forms as $form ) {
				$listed[ (string) $form['key'] ] = true;
			}

			foreach ( array_keys( Send4_Settings::forms() ) as $form_key ) {
				$form_key = (string) $form_key;
				$slug     = explode( ':', $form_key, 2 )[0];

				if ( isset( $listed[ $form_key ] ) || ! isset( $capped[ $slug ] ) ) {
					continue;
				}

				$form = self::look_up( $form_key );

				if ( null !== $form ) {
					$forms[] = $form;
				}
			}

			self::report_capped( $capped );
		}

		self::$forms  = $forms;
		self::$capped = array_map( 'strval', array_keys( $capped ) );

		foreach ( self::$form_changes as $hook ) {
			if ( false === has_action( $hook, array( __CLASS__, 'forget_forms' ) ) ) {
				add_action( $hook, array( __CLASS__, 'forget_forms' ), 10, 0 );
			}
		}

		return $forms;
	}

	/**
	 * One detected form by key, or null when it does not exist.
	 *
	 * From detect_forms() when it holds the form. When it does not, an adapter
	 * that can look one form up (Send4_Findable_Adapter) is asked directly:
	 * detection stops at a page of results, and a form past it is not a
	 * deleted form.
	 *
	 * @param string $form_key Form key.
	 * @return array|null
	 */
	public static function find_form( $form_key ) {
		$form_key = (string) $form_key;

		foreach ( self::detect_forms() as $form ) {
			if ( isset( $form['key'] ) && $form['key'] === $form_key ) {
				return $form;
			}
		}

		return self::look_up( $form_key );
	}

	/**
	 * Ask a form's own adapter for it, without listing anything.
	 *
	 * @param string $form_key Form key.
	 * @return array|null Null when the adapter cannot look forms up, or has no such form.
	 */
	private static function look_up( $form_key ) {
		$adapter = self::for_form_key( $form_key );
		$parts   = explode( ':', $form_key, 2 );

		if ( ! $adapter instanceof Send4_Findable_Adapter || ! isset( $parts[1] ) || ! $adapter->is_available() ) {
			return null;
		}

		$form = $adapter->find_form( $parts[1] );

		if ( ! is_array( $form ) || ! isset( $form['key'] ) || $form['key'] !== $form_key ) {
			return null;
		}

		return self::describe( $form, $adapter );
	}

	/**
	 * How many forms one form plugin's adapter lists before it stops.
	 *
	 * Listing is paged (DETECTION_PAGE at a time) so that it is not a single
	 * query with a limit, which is how the 101st Contact Form 7 form came to
	 * be missing from every screen. It still has to stop somewhere: the list
	 * is built on every Send4 admin screen and by the daily sync, and a site
	 * with thousands of forms must not load them all each time. Send4 itself
	 * takes 200. A form past the ceiling is looked up by key instead.
	 *
	 * @return int
	 */
	public static function detection_limit() {
		/**
		 * Filter how many forms one form plugin's adapter lists.
		 *
		 * @param int $limit Default Send4_Adapters::DETECTION_LIMIT.
		 */
		return max( 1, (int) apply_filters( 'send4_detection_limit', self::DETECTION_LIMIT ) );
	}

	/**
	 * Did an adapter's listing leave forms out?
	 *
	 * An adapter that says so itself is believed. For any other, a list as
	 * long as the ceiling is taken to have stopped at it.
	 *
	 * @param Send4_Form_Adapter $adapter The adapter.
	 * @param array              $found   What its detect_forms() returned.
	 * @return bool
	 */
	private static function stopped_early( Send4_Form_Adapter $adapter, array $found ) {
		if ( $adapter instanceof Send4_Truncating_Adapter && $adapter->detection_truncated() ) {
			return true;
		}

		return count( $found ) >= self::detection_limit();
	}

	/**
	 * Say, once a day per form plugin, that its list stopped at the ceiling.
	 *
	 * @param Send4_Form_Adapter[] $capped The adapters that stopped, keyed by slug.
	 * @return void
	 */
	private static function report_capped( array $capped ) {
		foreach ( $capped as $slug => $adapter ) {
			$flag = self::CAPPED_NOTICE . md5( (string) $slug );

			if ( get_transient( $flag ) ) {
				continue;
			}

			set_transient( $flag, 1, DAY_IN_SECONDS );

			Send4_Logger::log(
				'warning',
				'This site has more forms than Send4 lists for one form plugin. Forms already set up are still listed; any other can be set up with `wp send4 enable <form key>`.',
				array(
					'plugin' => $adapter->name(),
					'listed' => self::detection_limit(),
				)
			);
		}
	}

	/**
	 * A form has just been set up. When this request's answer does not hold
	 * it, the answer is dropped, so that what is built from it next (the forms
	 * report that follows every save) includes the form.
	 *
	 * @param string $form_key Form key.
	 * @return void
	 */
	public static function form_was_configured( $form_key ) {
		$slug = explode( ':', (string) $form_key, 2 )[0];

		// Only a list that stopped at the ceiling can be missing a real form.
		if ( null === self::$forms || ! in_array( $slug, self::$capped, true ) ) {
			return;
		}

		foreach ( self::$forms as $form ) {
			if ( isset( $form['key'] ) && $form['key'] === (string) $form_key ) {
				return;
			}
		}

		self::$forms = null;
	}

	/**
	 * Label a form with the adapter it came from.
	 *
	 * @param array              $form    A form from an adapter.
	 * @param Send4_Form_Adapter $adapter That adapter.
	 * @return array
	 */
	private static function describe( array $form, Send4_Form_Adapter $adapter ) {
		$form['adapter']      = $adapter->slug();
		$form['adapter_name'] = $adapter->name();

		return $form;
	}

	/**
	 * Drop this request's detected forms, so the next detect_forms() asks the
	 * form plugins again.
	 *
	 * @return void
	 */
	public static function forget_forms() {
		self::$forms  = null;
		self::$capped = array();
	}

	/**
	 * Hook every active adapter up to delivery.
	 *
	 * Adapters are only asked to hook when their plugin is active, which keeps
	 * a site with one form plugin from paying for four.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		$on_submit = static function ( Send4_Submission $submission ) {
			Send4_Delivery::capture( $submission );
		};

		foreach ( self::available() as $adapter ) {
			$adapter->register_hooks( $on_submit );
		}
	}

	/**
	 * Hook every active adapter that can refuse a submission up to the phone
	 * check (#0688).
	 *
	 * Its own method rather than part of register_hooks(): the suite re-runs
	 * register_hooks() after clearing only the submission hooks, and a second
	 * registration hidden in there is exactly how validators end up attached
	 * twice. Every binding still does nothing for a form that has not opted in.
	 *
	 * @return void
	 */
	public static function register_validation() {
		$guard = new Send4_Phone_Guard();

		foreach ( self::available() as $adapter ) {
			if ( $adapter instanceof Send4_Validating_Adapter ) {
				$adapter->register_validation( $guard );
			}
		}
	}

	/**
	 * Reset the cached adapter list and detected forms. Tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$adapters = null;
		self::$forms    = null;
		self::$capped   = array();
	}
}
