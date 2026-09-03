<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get the real visitor IP, checking CDN/proxy headers first.
 */
function vt_get_ip() {
    $keys = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare (checked first)
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    foreach ( $keys as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }
    }
    // Fallback — may be local/dev env
    return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Anonymize IP — masks last octet (IPv4) or last 80 bits (IPv6).
 * e.g. 192.168.1.55 → 192.168.1.0
 */
function vt_anonymize_ip( $ip ) {
    if ( strpos( $ip, ':' ) !== false ) {
        // IPv6 — zero out last 5 groups
        $parts = explode( ':', $ip );
        for ( $i = 3; $i < count( $parts ); $i++ ) {
            $parts[ $i ] = '0';
        }
        return implode( ':', $parts );
    }
    return preg_replace( '/\.\d+$/', '.0', $ip );
}

/**
 * Parse a user agent string into browser, OS, and device type.
 */
function vt_parse_ua( $ua ) {
    $ua = (string) $ua;

    // Device type
    $device = 'Desktop';
    if ( preg_match( '/tablet|ipad|playbook|silk/i', $ua ) ) {
        $device = 'Tablet';
    } elseif ( preg_match( '/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua ) ) {
        $device = 'Mobile';
    } elseif ( preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|headless/i', $ua ) ) {
        $device = 'Bot';
    }

    // Browser (order matters — Edge and Opera share Chrome UA tokens)
    $browser = 'Unknown';
    if ( preg_match( '/Edg\//i', $ua ) )            $browser = 'Edge';
    elseif ( preg_match( '/OPR\//i', $ua ) )        $browser = 'Opera';
    elseif ( preg_match( '/Chrome\//i', $ua ) )     $browser = 'Chrome';
    elseif ( preg_match( '/Firefox\//i', $ua ) )    $browser = 'Firefox';
    elseif ( preg_match( '/Safari\//i', $ua ) )     $browser = 'Safari';
    elseif ( preg_match( '/MSIE|Trident/i', $ua ) ) $browser = 'IE';

    // OS
    $os = 'Unknown';
    if ( preg_match( '/Windows NT/i', $ua ) )           $os = 'Windows';
    elseif ( preg_match( '/Mac OS X/i', $ua ) )         $os = 'macOS';
    elseif ( preg_match( '/Android/i', $ua ) )          $os = 'Android';
    elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ) $os = 'iOS';
    elseif ( preg_match( '/Linux/i', $ua ) )            $os = 'Linux';

    return compact( 'browser', 'os', 'device' );
}

/**
 * Generate a stable "visitor identity" key from IP + UA — no date
 * component, so the same visitor can be recognized across any time gap.
 * Used to look up their most recent session; VT_SESSION_TIMEOUT then
 * decides whether to continue that session or start a new one.
 */
function vt_make_visitor_key( $ip, $ua ) {
    return hash( 'sha256', $ip . $ua );
}

/**
 * Safely read a string from $_GET.
 */
function vt_get_param( $key, $default = '' ) {
    return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
}

/**
 * Safely read a string from a decoded JSON payload array.
 */
function vt_get_json_val( $data, $key, $default = '' ) {
    return isset( $data[ $key ] ) ? sanitize_text_field( wp_unslash( $data[ $key ] ) ) : $default;
}

/**
 * Format a datetime as "time ago" relative to WP local time.
 */
function vt_time_ago( $datetime ) {
    $diff = current_time( 'timestamp' ) - strtotime( $datetime );
    if ( $diff < 0 )     return 'just now';
    if ( $diff < 60 )    return $diff . 's ago';
    if ( $diff < 3600 )  return round( $diff / 60 ) . 'm ago';
    if ( $diff < 86400 ) return round( $diff / 3600 ) . 'h ago';
    return date( 'M j', strtotime( $datetime ) );
}

/**
 * Format a pageview duration (seconds) as a short human string.
 * e.g. 8 -> "8s", 95 -> "1m 35s". Returns '' for null/negative.
 */
function vt_format_duration( $seconds ) {
    if ( $seconds === null || $seconds < 0 ) return '';
    $seconds = (int) $seconds;
    if ( $seconds < 60 ) return $seconds . 's';
    $m = intdiv( $seconds, 60 );
    $s = $seconds % 60;
    return $s ? "{$m}m {$s}s" : "{$m}m";
}

/**
 * Icon for a click event based on the actual DOM tag clicked — reflects
 * reality (an <a> is a link, a <button> is a button) even when CSS makes
 * one look like the other. Falls back to a generic arrow for legacy rows
 * recorded before element_tag existed, or unrecognized tags.
 */
function vt_click_icon( $tag ) {
    switch ( $tag ) {
        case 'a':      return '↳';
        case 'button':
        case 'input':  return '🔘';
        default:       return '•'; // legacy rows recorded before element_tag existed
    }
}

/**
 * Country code → flag emoji.
 */
function vt_flag_emoji( $cc ) {
    if ( strlen( $cc ) !== 2 ) return '';
    $offset = 0x1F1E6 - ord( 'A' );
    $chars  = array_map( function( $c ) use ( $offset ) {
        return mb_convert_encoding( '&#' . ( $offset + ord( $c ) ) . ';', 'UTF-8', 'HTML-ENTITIES' );
    }, str_split( strtoupper( $cc ) ) );
    return implode( '', $chars );
}
