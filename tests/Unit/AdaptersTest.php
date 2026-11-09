<?php
/**
 * The adapter registry: routing, availability and the extension point.
 *
 * @package Send4
 */

/**
 * Send4_Adapters is small, and every other test in the suite goes around it —
 * straight to an adapter, or straight to Send4_Delivery. So the registry's own
 * three jobs are pinned here: turn a form key back into the adapter that owns
 * it, hide adapters whose form plugin is not installed, and let a third-party
 * plugin add one of its own through the send4_form_adapters filter.
 *
 * The filter is only testable at all because the cached list is cleared between
 * tests — Send4_Adapters::all() memoises, so the first test to touch it would
 * otherwise fix the list for the whole process.
 */
class Send4_Adapters_Test extends Send4_Test_Case {

	/**
	 * A form key belongs to the adapter named in its first segment.
	 *
	 * @dataProvider formKeyProvider
	 *
	 * @param string $form_key Form key.
	 * @param string $expected Adapter class.
	 * @return void
	 */
	public function test_it_routes_a_form_key_to_its_adapter( $form_key, $expected ) {
		$adapter = Send4_Adapters::for_form_key( $form_key );

		$this->assertInstanceOf( $expected, $adapter );
		$this->assertSame( explode( ':', $form_key )[0], $adapter->slug() );
	}

	/**
	 * One key per shipped adapter, in the shape the adapters build them.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function formKeyProvider() {
		return array(
			'Contact Form 7' => array( 'contact-form-7:12', 'Send4_Adapter_Cf7' ),
			'WPForms'        => array( 'wpforms:31', 'Send4_Adapter_Wpforms' ),
			'Gravity Forms'  => array( 'gravityforms:7', 'Send4_Adapter_Gravityforms' ),
			'Elementor Pro'  => array( 'elementor:a1b2c3d', 'Send4_Adapter_Elementor' ),
		);
	}

	/**
	 * Anything else is nobody's, and asking must not raise.
	 *
	 * @dataProvider unknownFormKeyProvider
	 *
	 * @param string $form_key Form key.
	 * @return void
	 */
	public function test_an_unknown_form_key_has_no_adapter( $form_key ) {
		$this->assertNull( Send4_Adapters::for_form_key( $form_key ) );
	}

	/**
	 * Keys that belong to no adapter, including the leftovers of a form plugin
	 * that has since been uninstalled.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unknownFormKeyProvider() {
		return array(
			'a form plugin we do not support' => array( 'typeform:9' ),
			'an empty key'                    => array( '' ),
			'a key that is only a separator'  => array( ':' ),
			'a near miss'                     => array( 'contact-form-8:12' ),
		);
	}

	/**
	 * A form key with no id still identifies its adapter: the settings screen
	 * routes on the slug alone in a couple of places.
	 *
	 * @return void
	 */
	public function test_a_bare_slug_is_enough_to_route() {
		$this->assertInstanceOf( 'Send4_Adapter_Wpforms', Send4_Adapters::for_form_key( 'wpforms' ) );
	}

	/**
	 * The four shipped adapters are always in all(), installed or not: the
	 * settings screen lists them so it can say "install this to use it".
	 *
	 * @return void
	 */
	public function test_all_lists_every_shipped_adapter() {
		$this->assertSame(
			array( 'contact-form-7', 'wpforms', 'gravityforms', 'elementor' ),
			array_keys( Send4_Adapters::all() )
		);
	}

	/**
	 * available() is all() minus whatever says it is not installed. Asserted
	 * against each adapter's own answer rather than against a fixed list, so it
	 * keeps holding as the suite's stand-ins for the paid plugins change.
	 *
	 * @return void
	 */
	public function test_available_reflects_is_available() {
		add_filter(
			'send4_form_adapters',
			static function ( $adapters ) {
				$adapters[] = new Send4_Fake_Adapter( 'absent-forms', false );

				return $adapters;
			}
		);

		Send4_Adapters::reset();

		$available = Send4_Adapters::available();

		$this->assertArrayHasKey( 'absent-forms', Send4_Adapters::all() );
		$this->assertArrayNotHasKey( 'absent-forms', $available );

		foreach ( Send4_Adapters::all() as $slug => $adapter ) {
			$this->assertSame(
				$adapter->is_available(),
				isset( $available[ $slug ] ),
				$slug . ' is in available() if and only if its form plugin is installed.'
			);
		}
	}

	/**
	 * The documented extension point: another plugin adds an adapter, and it is
	 * asked to hook itself up like any other.
	 *
	 * @return void
	 */
	public function test_a_filtered_in_adapter_is_asked_to_register_its_hooks() {
		$fake = new Send4_Fake_Adapter();

		add_filter(
			'send4_form_adapters',
			static function ( $adapters ) use ( $fake ) {
				$adapters[] = $fake;

				return $adapters;
			}
		);

		$this->registerAdapterHooks();

		$this->assertSame( $fake, Send4_Adapters::get( 'fake-forms' ) );
		$this->assertSame( 1, $fake->registrations, 'register_hooks() should be called exactly once.' );
		$this->assertIsCallable( $fake->on_submit );
	}

	/**
	 * And what it is handed is the real delivery path, not a no-op.
	 *
	 * @return void
	 */
	public function test_the_callback_a_filtered_in_adapter_receives_delivers() {
		$fake = new Send4_Fake_Adapter();

		add_filter(
			'send4_form_adapters',
			static function ( $adapters ) use ( $fake ) {
				$adapters[] = $fake;

				return $adapters;
			}
		);

		$this->registerAdapterHooks();
		$this->enableForm( 'fake-forms:1', array( 'phone' => 'phone' ), 'Fake form' );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$fake->submit(
			new Send4_Submission(
				'fake-forms:1',
				'Fake form',
				array( Send4_Submission::field( 'phone', 'Phone', '+370 600 12345' ) ),
				'77'
			)
		);

		$submissions = $this->http->requestsTo( '/api/integrations/wordpress/submissions' );

		$this->assertCount( 1, $submissions );
		$this->assertSame( '+37060012345', $submissions[0]['body']['lead']['phone'] );
		$this->assertSame( 'fake-forms:1', $submissions[0]['body']['form']['key'] );
	}

	/**
	 * The filter is other people's code, so anything that is not an adapter is
	 * dropped rather than fatalling on the next method call.
	 *
	 * @return void
	 */
	public function test_the_filter_cannot_add_something_that_is_not_an_adapter() {
		add_filter(
			'send4_form_adapters',
			static function ( $adapters ) {
				$adapters[] = 'not an adapter';
				$adapters[] = new stdClass();

				return $adapters;
			}
		);

		Send4_Adapters::reset();

		$this->assertCount( 4, Send4_Adapters::all() );

		foreach ( Send4_Adapters::all() as $adapter ) {
			$this->assertInstanceOf( 'Send4_Form_Adapter', $adapter );
		}
	}

	/**
	 * The list is memoised, which is what makes reset() necessary in the first
	 * place: without it one test's filter would follow the process to its end.
	 *
	 * @return void
	 */
	public function test_the_list_is_built_once_and_reset_rebuilds_it() {
		$first = Send4_Adapters::get( 'wpforms' );

		$this->assertSame( $first, Send4_Adapters::get( 'wpforms' ) );

		Send4_Adapters::reset();

		$this->assertNotSame( $first, Send4_Adapters::get( 'wpforms' ) );
		$this->assertInstanceOf( 'Send4_Adapter_Wpforms', Send4_Adapters::get( 'wpforms' ) );
	}
}
