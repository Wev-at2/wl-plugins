<?php
/**
 * Removes the WL Cookie Banner settings when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wl_cookie_banner' );
