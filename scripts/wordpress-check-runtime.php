<?php
/**
 * Disposable Plugin Check audit, mapped as an MU plugin in wp-env only.
 * Never distribute this file with the plugin.
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ( $_SERVER['argv'][1] ?? '' ) !== 'plugin' || ( $_SERVER['argv'][2] ?? '' ) !== 'check' ) { return; }
$marcpo_check_plugin = WP_PLUGIN_DIR . '/plugin-check/plugin.php';
if ( ! is_file( $marcpo_check_plugin ) ) { return; }
require_once $marcpo_check_plugin;
add_filter( 'pre_option_active_plugins', static fn() => array( 'plugin-check/plugin.php', 'poterie-navarraise-market-manager/marche-potier.php' ) );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_action( 'wp_loaded', static function () {
	$runner = WordPress\Plugin_Check\Utilities\Plugin_Request_Utility::get_runner();
	$checks = array(); $runtime = 0;
	if ( $runner ) {
		foreach ( $runner->get_checks_to_run() as $slug => $check ) {
			$checks[ $slug ] = get_class( $check );
			if ( $check instanceof WordPress\Plugin_Check\Checker\Runtime_Check ) { ++$runtime; }
		}
	}
	global $wp_version;
	file_put_contents( WP_CONTENT_DIR . '/marcpo-check-report/runtime.json', wp_json_encode( array(
		'target_active' => is_plugin_active( 'poterie-navarraise-market-manager/marche-potier.php' ),
		'target_loaded' => class_exists( 'MarchePotier\Plugin' ),
		'debug' => WP_DEBUG, 'wordpress' => $wp_version, 'php' => PHP_VERSION,
		'plugin_check' => WP_PLUGIN_CHECK_VERSION, 'checks' => $checks, 'runtime_checks' => $runtime,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
} );
