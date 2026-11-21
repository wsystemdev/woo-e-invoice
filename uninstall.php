<?php
/**
 * Removes the plugin settings. Order meta (generated invoices) is kept, because
 * invoices are business records that may have to be retained.
 *
 * @package WSEI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wsei_settings' );
