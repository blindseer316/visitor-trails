<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueue vt-pulse.js on every front-end page.
 * Passes ajaxurl, nonce, and any URL params we want to carry into JS.
 */
add_action( 'wp_enqueue_scripts', 'vt_enqueue_pulse' );
function vt_enqueue_pulse() {
    // Don't load if user is a logged-in admin (optional — comment out to track admins too)
    if ( current_user_can( 'manage_options' ) && get_option( 'vt_skip_admins', '0' ) === '1' ) return;

    wp_enqueue_script(
        'vt-pulse',
        VT_PLUGIN_URL . 'assets/js/vt-pulse.js',
        [],          // no jQuery dependency — vanilla JS
        VT_VERSION,
        true         // load in footer
    );

    // Pull ?tt= and UTMs from URL if present, pass to JS for carry-forward
    wp_localize_script( 'vt-pulse', 'VT', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'vt_track' ),
        // Pass URL params so JS can include them in the pageview payload
        // (JS also reads them directly from window.location, this is a fallback)
        'tag'     => vt_get_param( 'tt' ),
        'utms'    => [
            'source'   => vt_get_param( 'utm_source' ),
            'medium'   => vt_get_param( 'utm_medium' ),
            'campaign' => vt_get_param( 'utm_campaign' ),
            'term'     => vt_get_param( 'utm_term' ),
            'content'  => vt_get_param( 'utm_content' ),
        ],
    ] );
}
