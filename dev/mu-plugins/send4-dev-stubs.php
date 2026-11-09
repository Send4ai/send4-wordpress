<?php
/**
 * Development stubs for the form plugins we cannot install locally.
 *
 * Gravity Forms and Elementor Pro are both paid. Rather than leave their
 * adapters untestable until somebody buys a licence, this mu-plugin declares
 * just enough of their surface for detection to run and the settings screen to
 * render, backed by the same fixtures the test suite uses.
 *
 * Enabled by SEND4_DEV_STUBS=1, which the development compose file sets and
 * nothing else does. Never ships: dev/ is excluded from the plugin zip.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== (string) getenv( 'SEND4_DEV_STUBS' ) ) {
	return;
}

/**
 * Where the fixtures live.
 *
 * Not relative to this file: the development compose file mounts dev/mu-plugins
 * straight into wp-content/mu-plugins, so walking up from __DIR__ lands in the
 * WordPress root rather than in the plugin.
 *
 * @return string
 */
function send4_dev_fixture_dir() {
	return WP_CONTENT_DIR . '/plugins/send4/tests/fixtures';
}

/**
 * Load a fixture, or null when it is not there.
 *
 * A missing fixture must not be fatal: this file runs as a must-use plugin, so
 * throwing here takes the whole site down, WP-CLI included, and that is a very
 * confusing way to find out about a path problem.
 *
 * @param string $relative Path below the fixture directory.
 * @return array|null
 */
function send4_dev_fixture( $relative ) {
	$path = send4_dev_fixture_dir() . '/' . ltrim( $relative, '/' );

	if ( ! is_readable( $path ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[send4-dev-stubs] fixture missing, skipping: ' . $path );

		return null;
	}

	$data = require $path;

	return is_array( $data ) ? $data : null;
}

if ( ! class_exists( 'GFForms' ) ) {
	/**
	 * Marker class: the adapter checks for it before doing anything.
	 */
	class GFForms {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

	/**
	 * The two GFAPI methods the adapter uses.
	 */
	class GFAPI { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/**
		 * Every form.
		 *
		 * @return array<int,array>
		 */
		public static function get_forms() {
			$form = send4_dev_fixture( 'gravityforms/form.php' );

			return null === $form ? array() : array( $form );
		}

		/**
		 * One form.
		 *
		 * @param int|string $form_id Form id.
		 * @return array|null
		 */
		public static function get_form( $form_id ) {
			$form = send4_dev_fixture( 'gravityforms/form.php' );

			if ( null === $form ) {
				return null;
			}

			return (string) $form['id'] === (string) $form_id ? $form : null;
		}
	}
}

if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	define( 'ELEMENTOR_PRO_VERSION', '3.0.0-send4-dev-stub' );

	add_action(
		'init',
		static function () {
			// One page carrying an Elementor form widget, so detection has
			// something to walk. Created once.
			if ( get_page_by_path( 'elementor-stub' ) ) {
				return;
			}

			$record = send4_dev_fixture( 'elementor/record.php' );

			if ( null === $record ) {
				return;
			}

			$fields = array();

			foreach ( $record['fields'] as $field ) {
				$fields[] = array(
					'custom_id'   => $field['id'],
					'field_label' => $field['title'],
					'field_type'  => $field['type'],
				);
			}

			$data = array(
				array(
					'id'       => 'section1',
					'elType'   => 'section',
					'elements' => array(
						array(
							'id'         => 'col1',
							'elType'     => 'column',
							'elements'   => array(
								array(
									'id'         => $record['form_settings']['id'],
									'elType'     => 'widget',
									'widgetType' => 'form',
									'settings'   => array(
										'form_name'   => $record['form_settings']['form_name'],
										'form_fields' => $fields,
									),
								),
							),
						),
					),
				),
			);

			$post_id = wp_insert_post(
				array(
					'post_title'  => 'Elementor stub',
					'post_name'   => 'elementor-stub',
					'post_type'   => 'page',
					'post_status' => 'draft',
				)
			);

			if ( $post_id ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
		}
	);
}
