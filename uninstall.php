<?php
/**
 * Uninstall. Data is only removed when the "Remove all data on uninstall"
 * setting is on, so deleting the plugin to reinstall it never loses events.
 *
 * @package Feriae
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/feriae.php';

$feriae_settings = get_option( Feriae\Settings::OPTION, array() );

if ( ! empty( $feriae_settings['remove_data_on_uninstall'] ) ) {
	// Post types must exist for wp_delete_post() to clean up meta and terms.
	( new Feriae\Content\PostTypes( new Feriae\Settings() ) )->register_types();
	Feriae\Installer::uninstall();
}
