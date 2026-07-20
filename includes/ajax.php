<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register AJAX hooks ───────────────────────────────────────────────────────

add_action( 'wp_ajax_vt_pageview',        'vt_ajax_pageview' );
add_action( 'wp_ajax_nopriv_vt_pageview', 'vt_ajax_pageview' );
add_action( 'wp_ajax_vt_event',           'vt_ajax_event' );
add_action( 'wp_ajax_nopriv_vt_event',    'vt_ajax_event' );
add_action( 'wp_ajax_vt_duration',        'vt_ajax_duration' );
add_action( 'wp_ajax_nopriv_vt_duration', 'vt_ajax_duration' );
add_action( 'wp_ajax_vt_convert',         'vt_ajax_convert' );
add_action( 'wp_ajax_vt_save_tag_desc',   'vt_ajax_save_tag_desc' );
add_action( 'wp_ajax_vt_delete_session',  'vt_ajax_delete_session' );

// ── Pageview ──────────────────────────────────────────────────────────────────

function vt_ajax_pageview() {
    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) ) {
        wp_send_json_error( [ 'msg' => 'empty_payload' ] );
    }

    if ( ! wp_verify_nonce( $data['nonce'] ?? '', 'vt_track' ) ) {
        wp_send_json_error( [ 'msg' => 'bad_nonce' ] );
    }

    $ip = vt_get_ip();
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

    // NOTE: there is no longer a hard "skip and don't record" gate here.
    // Every session gets recorded and tagged with a bot tier (see
    // vt_detect_bot() below); the "Hide known bots/crawlers" etc. settings
    // control dashboard visibility only, not whether the data exists. This
    // is deliberate — it keeps the raw data available for review/backfill
    // instead of silently discarding it, and the storage/API cost of
    // recording bot traffic is minor (see includes/bot-detect.php).

    global $wpdb;
    $st  = $wpdb->prefix . VT_TABLE_SESSIONS;
    $pt  = $wpdb->prefix . VT_TABLE_PAGEVIEWS;
    $now = current_time( 'mysql' );
    $now_ts = current_time( 'timestamp' );

    $visitor_key = vt_make_visitor_key( $ip, $ua );
    $anonymize   = get_option( 'vt_anonymize_ip', '0' );
    $stored_ip   = $anonymize ? vt_anonymize_ip( $ip ) : $ip;
    $anon_ip     = vt_anonymize_ip( $ip );

    // URL params — read from JS payload first, fallback to $_GET (shouldn't be needed)
    $tag          = sanitize_text_field( $data['tag']              ?? '' );
    $utm_source   = sanitize_text_field( $data['utm_source']       ?? '' );
    $utm_medium   = sanitize_text_field( $data['utm_medium']       ?? '' );
    $utm_campaign = sanitize_text_field( $data['utm_campaign']     ?? '' );
    $utm_term     = sanitize_text_field( $data['utm_term']         ?? '' );
    $utm_content  = sanitize_text_field( $data['utm_content']      ?? '' );
    $page_url     = esc_url_raw(         $data['url']              ?? '' );
    $page_title   = sanitize_text_field( $data['title']            ?? '' );
    $referrer     = esc_url_raw(         $data['referrer']         ?? '' );

    // Strip self-referrals
    if ( $referrer && strpos( $referrer, home_url() ) === 0 ) {
        $referrer = '';
    }

    // Find this visitor's most recent session, regardless of what day it
    // was — VT_SESSION_TIMEOUT (not the calendar) decides whether we
    // continue it or start a fresh one. A visitor active at 7pm and again
    // at 9pm the same day gets two separate sessions if the gap exceeds
    // the timeout, same as they would if the gap crossed midnight.
    $prior = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$st} WHERE visitor_key = %s ORDER BY last_seen_at DESC LIMIT 1",
        $visitor_key
    ) );

    $continue_existing = false;
    if ( $prior ) {
        $gap = $now_ts - strtotime( $prior->last_seen_at );
        if ( $gap >= 0 && $gap <= VT_SESSION_TIMEOUT ) {
            $continue_existing = true;
        }
    }

    $session     = $continue_existing ? $prior : null;
    $session_key = $continue_existing ? $prior->session_key : hash( 'sha256', $visitor_key . microtime( true ) . wp_rand() );

    if ( ! $session ) {
        // ── New session (first-ever visit, or returning after a gap) ──
        $ua_data      = vt_parse_ua( $ua );
        $geo          = vt_geo_lookup( $ip );
        $bot          = vt_detect_bot( $geo, $ua );
        $is_returning = $prior ? 1 : 0; // a prior session exists, just outside the timeout window

        // Carry tag from transient if not in this request
        if ( empty( $tag ) ) {
            $tag = get_transient( 'vt_tag_' . $session_key ) ?: '';
        }

        $wpdb->insert( $st, [
            'session_key'  => $session_key,
            'visitor_key'  => $visitor_key,
            'is_returning' => $is_returning,
            'ip_address'   => $stored_ip,
            'ip_anon'      => $anon_ip,
            'country'      => $geo['country'],
            'country_code' => $geo['country_code'],
            'city'         => $geo['city'],
            'tag'          => $tag,
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_term'     => $utm_term,
            'utm_content'  => $utm_content,
            'referrer'     => $referrer,
            'entry_page'   => $page_url,
            'exit_page'    => $page_url,
            'browser'      => $ua_data['browser'],
            'device_type'  => $ua_data['device'],
            'os'           => $ua_data['os'],
            'is_converted' => 0,
            'is_bot_ip'    => $bot['is_bot'] ? 1 : 0,
            'bot_tier'     => (int) $bot['tier'],
            'bot_reason'   => substr( $bot['reason'], 0, 64 ),
            'bot_checked_at' => $now,
            'started_at'   => $now,
            'last_seen_at' => $now,
        ] );

        if ( $wpdb->last_error ) {
            update_option( 'vt_last_db_error', $wpdb->last_error, false );
        }

        $session_id = $wpdb->insert_id;

    } else {
        // ── Existing session — update exit page, last seen, and any missing params ──
        $session_id  = $session->id;
        $update_data = [
            'exit_page'    => $page_url,
            'last_seen_at' => $now,
        ];

        // Carry tag if session doesn't have one yet
        if ( empty( $session->tag ) && ! empty( $tag ) ) {
            $update_data['tag'] = $tag;
        }
        if ( empty( $session->tag ) && empty( $tag ) ) {
            $carried = get_transient( 'vt_tag_' . $session_key );
            if ( $carried ) $update_data['tag'] = $carried;
        }

        // Carry UTMs if not yet captured
        foreach ( [
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_term'     => $utm_term,
            'utm_content'  => $utm_content,
        ] as $col => $val ) {
            if ( empty( $session->{ $col } ) && ! empty( $val ) ) {
                $update_data[ $col ] = $val;
            }
        }

        $wpdb->update( $st, $update_data, [ 'id' => $session_id ] );
    }

    // Persist ?tt= tag for a few minutes in case next page doesn't carry it
    if ( ! empty( $tag ) ) {
        set_transient( 'vt_tag_' . $session_key, $tag, 5 * MINUTE_IN_SECONDS );
    }

    // Record the pageview
    $wpdb->insert( $pt, [
        'session_id' => $session_id,
        'page_url'   => substr( $page_url,   0, 512 ),
        'page_title' => substr( $page_title, 0, 256 ),
        'referrer'   => substr( $referrer,   0, 512 ),
        'viewed_at'  => $now,
    ] );

    $pageview_id = $wpdb->insert_id;

    if ( ! $pageview_id ) {
        // Surface the raw DB error on the Settings page (admin-only) instead
        // of in this public, unauthenticated response — so a site where
        // inserts are silently failing (bad table structure, permissions,
        // etc) is diagnosable without needing direct DB access.
        if ( $wpdb->last_error ) {
            update_option( 'vt_last_db_error', $wpdb->last_error, false );
        }
        wp_send_json_error( [ 'msg' => 'pageview_failed' ] );
    }

    // A pageview only gets this far after both the session and pageview
    // inserts succeeded, so any previously recorded failure is stale now —
    // clear it rather than leave the Settings page showing an error that
    // no longer reflects reality.
    if ( get_option( 'vt_last_db_error' ) ) {
        delete_option( 'vt_last_db_error' );
    }

    wp_send_json_success( [
        'session_id'  => $session_id,
        'pageview_id' => $pageview_id,
    ] );
}

// ── Click / interaction event ─────────────────────────────────────────────────

function vt_ajax_event() {
    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! wp_verify_nonce( $data['nonce'] ?? '', 'vt_track' ) ) {
        status_header( 204 );
        exit;
    }

    $session_id  = intval( $data['session_id']  ?? 0 );
    $pageview_id = intval( $data['pageview_id'] ?? 0 );

    if ( $session_id && $pageview_id ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . VT_TABLE_EVENTS, [
            'session_id'      => $session_id,
            'pageview_id'     => $pageview_id,
            'event_type'      => sanitize_text_field( $data['type']    ?? 'click' ),
            'element_tag'     => substr( sanitize_text_field( $data['elTag']   ?? '' ), 0, 16 ),
            'element_text'    => substr( sanitize_text_field( $data['text']    ?? '' ), 0, 255 ),
            'element_id'      => substr( sanitize_text_field( $data['elId']    ?? '' ), 0, 128 ),
            'element_classes' => substr( sanitize_text_field( $data['classes'] ?? '' ), 0, 255 ),
            'element_href'    => substr( esc_url_raw(         $data['href']    ?? '' ), 0, 512 ),
            'vp_label'        => substr( sanitize_text_field( $data['label']   ?? '' ), 0, 128 ),
            'section_label'   => substr( sanitize_text_field( $data['section'] ?? '' ), 0, 128 ),
            'fired_at'        => current_time( 'mysql' ),
        ] );
    }

    status_header( 204 );
    exit;
}

// ── Time on page (sent via sendBeacon on pagehide) ────────────────────────────

function vt_ajax_duration() {
    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! wp_verify_nonce( $data['nonce'] ?? '', 'vt_track' ) ) {
        status_header( 204 );
        exit;
    }

    $pageview_id = intval( $data['pageview_id'] ?? 0 );
    $duration    = intval( $data['duration']    ?? 0 );

    // Sanity bounds — ignore obviously bad values rather than trusting the client
    if ( $pageview_id && $duration > 0 && $duration < ( 12 * HOUR_IN_SECONDS ) ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . VT_TABLE_PAGEVIEWS,
            [ 'duration_seconds' => $duration ],
            [ 'id' => $pageview_id ]
        );
    }

    status_header( 204 );
    exit;
}

// ── Conversion toggle (admin only) ────────────────────────────────────────────

function vt_ajax_convert() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
    check_ajax_referer( 'vt_admin', 'nonce' );

    $session_id = intval( $_POST['session_id'] ?? 0 );
    if ( ! $session_id ) { wp_send_json_error(); }

    global $wpdb;
    $st      = $wpdb->prefix . VT_TABLE_SESSIONS;
    $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_converted FROM {$st} WHERE id = %d", $session_id ) );
    $new     = $current ? 0 : 1;

    $wpdb->update( $st, [ 'is_converted' => $new ], [ 'id' => $session_id ] );
    wp_send_json_success( [ 'converted' => $new ] );
}

// ── Delete a single session (admin only) ──────────────────────────────────────
// Same cascading-delete order as vt_run_purge() in visitor-trails.php
// (events -> pageviews -> session), just scoped to one session instead of
// an age-based batch.

function vt_ajax_delete_session() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
    check_ajax_referer( 'vt_admin', 'nonce' );

    $session_id = intval( $_POST['session_id'] ?? 0 );
    if ( ! $session_id ) { wp_send_json_error(); }

    global $wpdb;
    $st = $wpdb->prefix . VT_TABLE_SESSIONS;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    $pv_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$pt} WHERE session_id = %d", $session_id ) );
    if ( ! empty( $pv_ids ) ) {
        $pv_ids_str = implode( ',', array_map( 'intval', $pv_ids ) );
        $wpdb->query( "DELETE FROM {$et} WHERE pageview_id IN ({$pv_ids_str})" );
    }

    $wpdb->delete( $pt, [ 'session_id' => $session_id ] );
    $deleted = $wpdb->delete( $st, [ 'id' => $session_id ] );

    if ( ! $deleted ) { wp_send_json_error(); }
    wp_send_json_success();
}

// ── Tag descriptions (admin only) ─────────────────────────────────────────────
// Freeform notes attached to a tag string (e.g. "fbgrpreal" -> "the Real
// Estate Investors FB group"), so ad-hoc tags stay meaningful months later.
// Stored as a single option (tag => description) rather than a DB column,
// since a tag isn't a row of its own — it's just a string that happens to
// repeat across sessions.

function vt_ajax_save_tag_desc() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
    check_ajax_referer( 'vt_admin', 'nonce' );

    $tag = sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) );
    if ( ! $tag ) { wp_send_json_error(); }

    $description = substr( sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ), 0, 255 );

    $descriptions = get_option( 'vt_tag_descriptions', [] );
    if ( $description === '' ) {
        unset( $descriptions[ $tag ] );
    } else {
        $descriptions[ $tag ] = $description;
    }
    update_option( 'vt_tag_descriptions', $descriptions );

    wp_send_json_success( [ 'tag' => $tag, 'description' => $description ] );
}
