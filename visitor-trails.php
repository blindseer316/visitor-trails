<?php
/**
 * Plugin Name: Visitor Trails
 * Plugin URI:  https://earthbreakdesigns.com
 * Description: Unified visitor tracking — page trails, click events, UTM params, tags, referrers, GeoIP, and a searchable dashboard.
 * Version:     2.6.6
 * Author:      Earthbreak Designs
 * Author URI:  https://earthbreakdesigns.com
 * License:     GPL2
 * Text Domain: visitor-trails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VT_VERSION',         '2.6.6' );
define( 'VT_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'VT_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'VT_SESSION_TIMEOUT', 30 * MINUTE_IN_SECONDS );
define( 'VT_PER_PAGE',        25 );

// Table name constants (without prefix — use $wpdb->prefix . VT_TABLE_X)
define( 'VT_TABLE_SESSIONS',  'vt_sessions' );
define( 'VT_TABLE_PAGEVIEWS', 'vt_pageviews' );
define( 'VT_TABLE_EVENTS',    'vt_events' );

require_once VT_PLUGIN_DIR . 'includes/db.php';
require_once VT_PLUGIN_DIR . 'includes/geo.php';
require_once VT_PLUGIN_DIR . 'includes/bot-detect.php';
require_once VT_PLUGIN_DIR . 'includes/helpers.php';
require_once VT_PLUGIN_DIR . 'includes/ajax.php';
require_once VT_PLUGIN_DIR . 'includes/frontend.php';
require_once VT_PLUGIN_DIR . 'admin/admin.php';

// ── Activation / upgrade ──────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'vt_activate' );
function vt_activate() {
    vt_create_tables();
    update_option( 'vt_db_version', VT_VERSION );
    wp_schedule_event( time(), 'daily', 'vt_purge_old_data' );
}

register_deactivation_hook( __FILE__, 'vt_deactivate' );
function vt_deactivate() {
    wp_clear_scheduled_hook( 'vt_purge_old_data' );
}

// Run DB migrations on plugin update
add_action( 'plugins_loaded', 'vt_maybe_upgrade', 5 );
function vt_maybe_upgrade() {
    $installed = get_option( 'vt_db_version', '0' );
    if ( version_compare( $installed, VT_VERSION, '<' ) ) {
        vt_create_tables();
        update_option( 'vt_db_version', VT_VERSION );
    }
}

// Belt-and-suspenders: re-verify critical columns on every admin load.
// These short-circuit via a cached option once confirmed, so this is a
// single cheap get_option() call in the common case — but it means a
// failed/partial migration self-heals on the next admin page view instead
// of silently staying broken.
add_action( 'admin_init', 'vt_ensure_bot_columns' );
add_action( 'admin_init', 'vt_ensure_pageview_columns' );
add_action( 'admin_init', 'vt_ensure_duration_column' );
add_action( 'admin_init', 'vt_ensure_element_tag_column' );
add_action( 'admin_init', 'vt_ensure_visitor_columns' );
add_action( 'admin_init', 'vt_ensure_section_label_column' );
add_action( 'admin_init', 'vt_ensure_column_widths' );

// ── Daily purge ───────────────────────────────────────────────────────────────

add_action( 'vt_purge_old_data', 'vt_run_purge' );
function vt_run_purge() {
    global $wpdb;
    $days = (int) get_option( 'vt_purge_days', 90 );
    if ( $days < 1 ) return;

    $st = $wpdb->prefix . VT_TABLE_SESSIONS;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    $old_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$st} WHERE started_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ) );

    if ( empty( $old_ids ) ) return;

    $ids = implode( ',', array_map( 'intval', $old_ids ) );

    // Get pageview IDs so we can delete their events too
    $pv_ids = $wpdb->get_col( "SELECT id FROM {$pt} WHERE session_id IN ({$ids})" );
    if ( ! empty( $pv_ids ) ) {
        $pv_ids_str = implode( ',', array_map( 'intval', $pv_ids ) );
        $wpdb->query( "DELETE FROM {$et} WHERE pageview_id IN ({$pv_ids_str})" );
    }

    $wpdb->query( "DELETE FROM {$pt} WHERE session_id IN ({$ids})" );
    $wpdb->query( "DELETE FROM {$st} WHERE id IN ({$ids})" );
}
