<?php
/**
 * Removes the WL Updater cache when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'wl_updater_manifest' );
