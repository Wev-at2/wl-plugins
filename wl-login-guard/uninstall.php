<?php
/**
 * Removes the WL Login Guard settings when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wl_login_guard_slug' );
delete_option( 'wl_login_guard_block_xmlrpc' );
delete_option( 'wl_login_guard_hide_users' );
delete_transient( 'wl_login_guard_activated' );
