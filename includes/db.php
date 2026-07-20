<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vt_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $st = $wpdb->prefix . VT_TABLE_SESSIONS;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    // Sessions — one row per visitor per day
    $sql_sessions = "CREATE TABLE IF NOT EXISTS {$st} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_key   VARCHAR(64)  NOT NULL DEFAULT '',
        visitor_key   VARCHAR(64)  NOT NULL DEFAULT '',
        is_returning  TINYINT(1)   NOT NULL DEFAULT 0,
        ip_address    VARCHAR(45)  NOT NULL DEFAULT '',
        ip_anon       VARCHAR(45)  NOT NULL DEFAULT '',
        country       VARCHAR(64)  NOT NULL DEFAULT '',
        country_code  VARCHAR(4)   NOT NULL DEFAULT '',
        city          VARCHAR(64)  NOT NULL DEFAULT '',
        tag           VARCHAR(128) NOT NULL DEFAULT '',
        utm_source    VARCHAR(128) NOT NULL DEFAULT '',
        utm_medium    VARCHAR(128) NOT NULL DEFAULT '',
        utm_campaign  VARCHAR(128) NOT NULL DEFAULT '',
        utm_term      VARCHAR(128) NOT NULL DEFAULT '',
        utm_content   VARCHAR(128) NOT NULL DEFAULT '',
        referrer      VARCHAR(512) NOT NULL DEFAULT '',
        entry_page    VARCHAR(512) NOT NULL DEFAULT '',
        exit_page     VARCHAR(512) NOT NULL DEFAULT '',
        browser       VARCHAR(128) NOT NULL DEFAULT '',
        device_type   VARCHAR(32)  NOT NULL DEFAULT '',
        os            VARCHAR(64)  NOT NULL DEFAULT '',
        is_converted  TINYINT(1)   NOT NULL DEFAULT 0,
        is_bot_ip     TINYINT(1)   NOT NULL DEFAULT 0,
        bot_tier      TINYINT(1)   NOT NULL DEFAULT 0,
        bot_reason    VARCHAR(64)  NOT NULL DEFAULT '',
        bot_checked_at DATETIME    NULL DEFAULT NULL,
        started_at    DATETIME     NOT NULL,
        last_seen_at  DATETIME     NOT NULL,
        PRIMARY KEY  (id),
        KEY session_key (session_key),
        KEY visitor_key (visitor_key),
        KEY started_at (started_at),
        KEY tag (tag),
        KEY utm_source (utm_source),
        KEY country_code (country_code),
        KEY is_bot_ip (is_bot_ip),
        KEY bot_tier (bot_tier),
        KEY bot_checked_at (bot_checked_at)
    ) {$charset};";

    // Pageviews — one row per page load per session
    $sql_pageviews = "CREATE TABLE IF NOT EXISTS {$pt} (
        id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id        BIGINT(20) UNSIGNED NOT NULL,
        page_url          VARCHAR(512) NOT NULL DEFAULT '',
        page_title        VARCHAR(256) NOT NULL DEFAULT '',
        referrer          VARCHAR(512) NOT NULL DEFAULT '',
        duration_seconds  INT UNSIGNED NULL DEFAULT NULL,
        viewed_at         DATETIME     NOT NULL,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY viewed_at (viewed_at)
    ) {$charset};";

    // Events — click/interaction tracking per pageview
    $sql_events = "CREATE TABLE IF NOT EXISTS {$et} (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id      BIGINT(20) UNSIGNED NOT NULL,
        pageview_id     BIGINT(20) UNSIGNED NOT NULL,
        event_type      VARCHAR(32)  NOT NULL DEFAULT 'click',
        element_tag     VARCHAR(16)  NOT NULL DEFAULT '',
        element_text    VARCHAR(255) NOT NULL DEFAULT '',
        element_id      VARCHAR(128) NOT NULL DEFAULT '',
        element_classes VARCHAR(255) NOT NULL DEFAULT '',
        element_href    VARCHAR(512) NOT NULL DEFAULT '',
        vp_label        VARCHAR(128) NOT NULL DEFAULT '',
        section_label   VARCHAR(128) NOT NULL DEFAULT '',
        fired_at        DATETIME     NOT NULL,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY pageview_id (pageview_id),
        KEY fired_at (fired_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_sessions );
    dbDelta( $sql_pageviews );
    dbDelta( $sql_events );

    // ── Safety net ──────────────────────────────────────────────────────
    // dbDelta can silently fail to ALTER an existing table in some
    // environments (formatting quirks, caching, etc). Belt-and-suspenders:
    // explicitly verify critical columns exist and add them directly via
    // ALTER TABLE if dbDelta didn't. This does NOT depend on vt_db_version,
    // so it self-heals even on installs where the version option already
    // got bumped without the columns actually landing.
    vt_ensure_bot_columns();
    vt_ensure_pageview_columns();
    vt_ensure_duration_column();
    vt_ensure_element_tag_column();
    vt_ensure_visitor_columns();
    vt_ensure_section_label_column();
}

/**
 * Verify bot-detection columns exist on the sessions table and add them
 * directly if missing. Cheap after the first successful check — result is
 * cached in an option so we don't run DESCRIBE on every request.
 */
function vt_ensure_bot_columns() {
    // NOTE: bump this option's suffix (_v2, _v3, ...) any time a new column
    // is added to the checklist below, so sites that already cached success
    // under an older suffix are forced to re-verify once instead of skipping
    // the new column forever.
    if ( get_option( 'vt_bot_columns_ok_v3' ) === '1' ) {
        return;
    }

    global $wpdb;
    $st = $wpdb->prefix . VT_TABLE_SESSIONS;

    // Make sure the table itself exists before trying to inspect it
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $st ) ) !== $st ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$st}", 0 );

    if ( ! in_array( 'is_bot_ip', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN is_bot_ip TINYINT(1) NOT NULL DEFAULT 0 AFTER is_converted" );
        $wpdb->query( "ALTER TABLE {$st} ADD INDEX is_bot_ip (is_bot_ip)" );
    }
    if ( ! in_array( 'bot_tier', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN bot_tier TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bot_ip" );
        $wpdb->query( "ALTER TABLE {$st} ADD INDEX bot_tier (bot_tier)" );
    }
    if ( ! in_array( 'bot_reason', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN bot_reason VARCHAR(64) NOT NULL DEFAULT '' AFTER bot_tier" );
    }
    if ( ! in_array( 'bot_checked_at', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN bot_checked_at DATETIME NULL DEFAULT NULL AFTER bot_reason" );
        $wpdb->query( "ALTER TABLE {$st} ADD INDEX bot_checked_at (bot_checked_at)" );
    }

    // Re-check before caching success, in case the ALTERs above also failed
    // (e.g. restrictive DB permissions) — don't mask a real problem
    $columns_after = $wpdb->get_col( "DESCRIBE {$st}", 0 );
    $needed        = [ 'is_bot_ip', 'bot_tier', 'bot_reason', 'bot_checked_at' ];
    if ( ! array_diff( $needed, $columns_after ) ) {
        update_option( 'vt_bot_columns_ok_v3', '1' );
    }
}

/**
 * Same self-healing pattern as vt_ensure_bot_columns(), for the pageviews
 * table's duration_seconds column (time-on-page tracking).
 */
function vt_ensure_duration_column() {
    if ( get_option( 'vt_duration_column_ok' ) === '1' ) {
        return;
    }

    global $wpdb;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) !== $pt ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$pt}", 0 );

    if ( ! in_array( 'duration_seconds', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$pt} ADD COLUMN duration_seconds INT UNSIGNED NULL DEFAULT NULL AFTER referrer" );
    }

    $columns_after = $wpdb->get_col( "DESCRIBE {$pt}", 0 );
    if ( in_array( 'duration_seconds', $columns_after, true ) ) {
        update_option( 'vt_duration_column_ok', '1' );
    }
}

/**
 * Same self-healing pattern, for the events table's element_tag column
 * (used to show an accurate button/link icon regardless of CSS styling —
 * a div styled to look like a button is still a div underneath).
 */
function vt_ensure_element_tag_column() {
    if ( get_option( 'vt_element_tag_column_ok' ) === '1' ) {
        return;
    }

    global $wpdb;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) ) !== $et ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$et}", 0 );

    if ( ! in_array( 'element_tag', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$et} ADD COLUMN element_tag VARCHAR(16) NOT NULL DEFAULT '' AFTER event_type" );
    }

    $columns_after = $wpdb->get_col( "DESCRIBE {$et}", 0 );
    if ( in_array( 'element_tag', $columns_after, true ) ) {
        update_option( 'vt_element_tag_column_ok', '1' );
    }
}

/**
 * Same self-healing pattern, for the sessions table's visitor_key and
 * is_returning columns (real session-timeout support — see
 * vt_make_visitor_key() and its use in includes/ajax.php).
 */
function vt_ensure_visitor_columns() {
    if ( get_option( 'vt_visitor_columns_ok' ) === '1' ) {
        return;
    }

    global $wpdb;
    $st = $wpdb->prefix . VT_TABLE_SESSIONS;

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $st ) ) !== $st ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$st}", 0 );

    if ( ! in_array( 'visitor_key', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN visitor_key VARCHAR(64) NOT NULL DEFAULT '' AFTER session_key" );
        $wpdb->query( "ALTER TABLE {$st} ADD INDEX visitor_key (visitor_key)" );
    }
    if ( ! in_array( 'is_returning', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$st} ADD COLUMN is_returning TINYINT(1) NOT NULL DEFAULT 0 AFTER visitor_key" );
    }

    $columns_after = $wpdb->get_col( "DESCRIBE {$st}", 0 );
    if ( in_array( 'visitor_key', $columns_after, true ) && in_array( 'is_returning', $columns_after, true ) ) {
        update_option( 'vt_visitor_columns_ok', '1' );
    }
}

/**
 * Same self-healing pattern, for the pageviews table's original base
 * columns (page_url, page_title, referrer, viewed_at). These predate every
 * other vt_ensure_* check, on the assumption dbDelta always gets the base
 * schema right on first create — but on at least one older install, the
 * table exists without page_url, causing every pageview insert to fail
 * with "Unknown column 'page_url'". dbDelta's own ALTER-sync is known to
 * be unreliable on existing tables (see comment atop vt_create_tables()),
 * so — same as everywhere else in this file — verify directly and add
 * whatever's missing rather than trust it happened.
 */
function vt_ensure_pageview_columns() {
    if ( get_option( 'vt_pageview_columns_ok' ) === '1' ) {
        return;
    }

    global $wpdb;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) !== $pt ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$pt}", 0 );

    if ( ! in_array( 'page_url', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$pt} ADD COLUMN page_url VARCHAR(512) NOT NULL DEFAULT '' AFTER session_id" );
    }
    if ( ! in_array( 'page_title', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$pt} ADD COLUMN page_title VARCHAR(256) NOT NULL DEFAULT '' AFTER page_url" );
    }
    if ( ! in_array( 'referrer', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$pt} ADD COLUMN referrer VARCHAR(512) NOT NULL DEFAULT '' AFTER page_title" );
    }
    if ( ! in_array( 'viewed_at', $columns, true ) ) {
        // NOT NULL with no real default — existing rows (if any) need
        // *something*; an obviously-fake epoch timestamp is easier to spot
        // as backfilled than silently defaulting to "now".
        $wpdb->query( "ALTER TABLE {$pt} ADD COLUMN viewed_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER referrer" );
        $wpdb->query( "ALTER TABLE {$pt} ADD INDEX viewed_at (viewed_at)" );
    }

    $columns_after = $wpdb->get_col( "DESCRIBE {$pt}", 0 );
    $needed        = [ 'page_url', 'page_title', 'referrer', 'viewed_at' ];
    if ( ! array_diff( $needed, $columns_after ) ) {
        update_option( 'vt_pageview_columns_ok', '1' );
    }
}

/**
 * Same self-healing pattern, for the events table's section_label column
 * (the enclosing <section>/landmark/nearest-id ancestor of a clicked
 * element — see the section-detection fallback chain in vt-pulse.js).
 */
function vt_ensure_section_label_column() {
    if ( get_option( 'vt_section_label_column_ok' ) === '1' ) {
        return;
    }

    global $wpdb;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) ) !== $et ) {
        return;
    }

    $columns = $wpdb->get_col( "DESCRIBE {$et}", 0 );

    if ( ! in_array( 'section_label', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$et} ADD COLUMN section_label VARCHAR(128) NOT NULL DEFAULT '' AFTER vp_label" );
    }

    $columns_after = $wpdb->get_col( "DESCRIBE {$et}", 0 );
    if ( in_array( 'section_label', $columns_after, true ) ) {
        update_option( 'vt_section_label_column_ok', '1' );
    }
}

/**
 * Backfill bot detection for sessions recorded before this feature existed
 * (or any session that otherwise never got evaluated). Processes a small
 * batch per call to stay well under typical shared-hosting execution time
 * limits — each unique IP may trigger a live ip-api.com lookup (~3s worst
 * case), so keep $limit modest and call again ("Continue") for more.
 *
 * @param int $limit Max sessions to process in this call.
 * @return array{checked: int, remaining: int}
 */
function vt_rescan_bots_batch( $limit = 20 ) {
    global $wpdb;
    $st = $wpdb->prefix . VT_TABLE_SESSIONS;

    // Try to buy a little more execution time where the host allows it —
    // silently ignored on hosts that disable this, which is fine.
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 60 );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, ip_address, device_type FROM {$st} WHERE bot_checked_at IS NULL ORDER BY id ASC LIMIT %d",
        $limit
    ) );

    $checked = 0;
    foreach ( $rows as $row ) {
        $geo = vt_geo_lookup( $row->ip_address );
        $bot = vt_detect_bot_from_device_type( $geo, $row->device_type );

        $wpdb->update( $st, [
            'is_bot_ip'      => $bot['is_bot'] ? 1 : 0,
            'bot_tier'       => (int) $bot['tier'],
            'bot_reason'     => substr( $bot['reason'], 0, 64 ),
            'bot_checked_at' => current_time( 'mysql' ),
        ], [ 'id' => $row->id ] );

        $checked++;
    }

    $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$st} WHERE bot_checked_at IS NULL" );

    return [ 'checked' => $checked, 'remaining' => $remaining ];
}
