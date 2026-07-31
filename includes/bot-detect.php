<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tiered bot/crawler/datacenter detection.
 *
 * Tier 1 — Declared bot: UA string self-identifies (contains "bot",
 *          "crawler", "spider", etc). Highest confidence, no ambiguity.
 * Tier 2 — Known crawler/AI infrastructure: IP belongs to an org we
 *          specifically recognize as crawler/AI infrastructure (Google,
 *          Microsoft/Bing, OpenAI, Anthropic, Common Crawl), even when the
 *          UA looks like an ordinary browser (e.g. Google's non-Googlebot
 *          backend fetchers).
 * Tier 3 — Unsure/datacenter/VPN: IP is on general-purpose cloud/hosting
 *          infrastructure (AWS, DigitalOcean, OVH, Hetzner, etc) or ip-api
 *          otherwise flags it as a hosting IP. This tier is inherently
 *          ambiguous — a real visitor on a commercial VPN and a script
 *          running on a rented server look identical from IP data alone.
 *          There is no way to split these further with what we have.
 *
 * Each session gets these regardless of any "Hide" setting — the settings
 * only control default dashboard visibility, not whether the data exists.
 */

/**
 * Check geo/org/ISP data against known crawler brands (Tier 2) and general
 * cloud/hosting/VPN infrastructure (Tier 3). Shared by both live detection
 * (vt_detect_bot) and the legacy rescan (vt_detect_bot_from_device_type),
 * since only the former has a raw UA to check for Tier 1.
 *
 * @param array $geo Result of vt_geo_lookup() — needs isp/org/as/hosting.
 * @return array{tier: int, reason: string} tier is 0, 2, or 3.
 */
function vt_bot_hosting_match( $geo ) {
    $haystack = strtolower( ( $geo['org'] ?? '' ) . ' ' . ( $geo['isp'] ?? '' ) . ' ' . ( $geo['as'] ?? '' ) );

    // Tier 2 — specific, identifiable crawler/AI infrastructure brands.
    // Checked first and independent of the 'hosting' flag, since a brand
    // match is more specific/confident than a generic hosting flag.
    $crawler_brands = [
        'googlebot'    => 'Google',
        'google'       => 'Google',
        'bingbot'      => 'Microsoft/Bing',
        'microsoft'    => 'Microsoft/Bing',
        'gptbot'       => 'OpenAI (GPTBot)',
        'openai'       => 'OpenAI',
        'claudebot'    => 'Anthropic (ClaudeBot)',
        'anthropic'    => 'Anthropic',
        'commoncrawl'  => 'Common Crawl',
        'common crawl' => 'Common Crawl',
    ];
    foreach ( $crawler_brands as $needle => $label ) {
        if ( strpos( $haystack, $needle ) !== false ) {
            return [ 'tier' => 2, 'reason' => $label ];
        }
    }

    // Tier 3 — ip-api's own hosting/datacenter flag (covers most cloud
    // providers and VPN exit nodes, which are almost always hosted on
    // cloud/datacenter infrastructure)
    if ( ! empty( $geo['hosting'] ) ) {
        return [ 'tier' => 3, 'reason' => $geo['org'] ?: ( $geo['isp'] ?: 'Datacenter/Hosting IP' ) ];
    }

    // Tier 3 fallback — org/isp/AS text match for known general-purpose
    // cloud/hosting providers, in case ip-api's hosting flag misses a range
    $general_providers = [
        'amazon'       => 'Amazon/AWS',
        'digitalocean' => 'DigitalOcean',
        'ovh'          => 'OVH',
        'hetzner'      => 'Hetzner',
        'linode'       => 'Linode',
        'akamai'       => 'Akamai',
        'oracle cloud' => 'Oracle Cloud',
        'alibaba'      => 'Alibaba Cloud',
        'azure'        => 'Microsoft Azure',
    ];
    foreach ( $general_providers as $needle => $label ) {
        if ( strpos( $haystack, $needle ) !== false ) {
            return [ 'tier' => 3, 'reason' => $label ];
        }
    }

    return [ 'tier' => 0, 'reason' => '' ];
}

/**
 * Live detection — has the raw User-Agent string available.
 *
 * @param array  $geo Result of vt_geo_lookup().
 * @param string $ua  Raw User-Agent string.
 * @return array{tier: int, reason: string, is_bot: bool}
 */
function vt_detect_bot( $geo, $ua ) {
    if ( preg_match( '/bot|crawl|spider|slurp|headless|facebookexternalhit/i', (string) $ua ) ) {
        return [ 'tier' => 1, 'reason' => 'Declared bot UA', 'is_bot' => true ];
    }

    $match = vt_bot_hosting_match( $geo );
    return [ 'tier' => $match['tier'], 'reason' => $match['reason'], 'is_bot' => $match['tier'] > 0 ];
}

/**
 * Legacy rescan — only has the already-parsed device_type ('Bot' /
 * 'Desktop' / 'Mobile' / 'Tablet') from vt_parse_ua() at the time, since
 * the raw UA was never stored on the session row.
 *
 * @param array  $geo         Result of vt_geo_lookup().
 * @param string $device_type Session's stored device_type column.
 * @return array{tier: int, reason: string, is_bot: bool}
 */
function vt_detect_bot_from_device_type( $geo, $device_type ) {
    if ( $device_type === 'Bot' ) {
        return [ 'tier' => 1, 'reason' => 'Declared bot UA', 'is_bot' => true ];
    }

    $match = vt_bot_hosting_match( $geo );
    return [ 'tier' => $match['tier'], 'reason' => $match['reason'], 'is_bot' => $match['tier'] > 0 ];
}

/**
 * Short human label for a bot tier, used in CSV export and settings text.
 */
function vt_bot_tier_label( $tier ) {
    $labels = [
        0 => 'None',
        1 => 'Bot/Crawler (declared)',
        2 => 'Crawler Infrastructure',
        3 => 'Unsure/Datacenter/VPN',
    ];
    return $labels[ (int) $tier ] ?? 'None';
}

/**
 * Small helper — colored-dot + tooltip HTML for a session's bot tier.
 * Returns '' for tier 0 (nothing to show).
 */
function vt_bot_dot_html( $tier, $reason ) {
    $tier = (int) $tier;
    if ( $tier < 1 || $tier > 3 ) return '';

    $labels = [
        1 => 'Bot — declared itself as a crawler in its browser identification',
        2 => 'Crawler infrastructure — ' . ( $reason ?: 'known bot/crawler network' ),
        3 => 'Possible VPN or hosting IP — ' . ( $reason ?: 'unknown provider' ) . '. Could be a real visitor using a VPN, or automated traffic.',
    ];

    return sprintf(
        '<span class="vt-bot-dot vt-bot-tier-%d" data-tip="%s">&#9679;</span>',
        $tier,
        esc_attr( $labels[ $tier ] )
    );
}
