<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Look up geo data for a given IP via ip-api.com.
 * Results are cached as WP transients for 1 hour to avoid rate limiting.
 * Returns array: [ country, country_code, city, isp, org, as, hosting ]
 *
 * 'hosting' is ip-api's own boolean flag for datacenter/hosting-provider IPs
 * (covers AWS, GCP, Azure, DigitalOcean, Googlebot's own network, etc.) —
 * used by vt_detect_bot() so we don't have to hand-maintain CIDR lists.
 *
 * Future: swap this function's internals for MaxMind GeoLite2 local DB
 * by replacing the wp_remote_get() call with a Reader::city() lookup.
 * Note: GeoLite2 City/Country DBs don't include the hosting/ASN flag —
 * if that migration happens, pair it with GeoLite2 ASN or keep this
 * ip-api call (or a similar service) around just for bot detection.
 */
function vt_geo_lookup( $ip ) {
    $empty = [
        'country' => '', 'country_code' => '', 'city' => '',
        'isp' => '', 'org' => '', 'as' => '', 'hosting' => false,
    ];

    if ( empty( $ip ) || $ip === '127.0.0.1' || $ip === '::1' ) {
        return $empty;
    }

    // Cache per IP for 1 hour
    $cache_key = 'vt_geo_' . md5( $ip );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $url      = 'http://ip-api.com/json/' . urlencode( $ip ) . '?fields=status,country,countryCode,city,isp,org,as,hosting';
    $response = wp_remote_get( $url, [ 'timeout' => 3 ] );

    if ( is_wp_error( $response ) ) {
        return $empty;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data ) || ( $data['status'] ?? '' ) !== 'success' ) {
        return $empty;
    }

    $result = [
        'country'      => sanitize_text_field( $data['country']     ?? '' ),
        'country_code' => sanitize_text_field( $data['countryCode'] ?? '' ),
        'city'         => sanitize_text_field( $data['city']        ?? '' ),
        'isp'          => sanitize_text_field( $data['isp']         ?? '' ),
        'org'          => sanitize_text_field( $data['org']         ?? '' ),
        'as'           => sanitize_text_field( $data['as']          ?? '' ),
        'hosting'      => ! empty( $data['hosting'] ),
    ];

    set_transient( $cache_key, $result, HOUR_IN_SECONDS );

    return $result;
}
