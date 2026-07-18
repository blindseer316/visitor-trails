<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Menu ──────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'vt_admin_menu' );
function vt_admin_menu() {
    add_menu_page(
        'Visitor Trails',
        'Visitor Trails',
        'manage_options',
        'visitor-trails',
        'vt_dashboard_page',
        'dashicons-location-alt',
        30
    );
    add_submenu_page( 'visitor-trails', 'Sessions',  'Sessions',  'manage_options', 'visitor-trails',          'vt_dashboard_page' );
    add_submenu_page( 'visitor-trails', 'Settings',  'Settings',  'manage_options', 'visitor-trails-settings', 'vt_settings_page'  );
}

// ── Assets ────────────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'vt_enqueue_admin_assets' );
function vt_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'visitor-trails' ) === false ) return;
    wp_enqueue_style(  'vt-admin', VT_PLUGIN_URL . 'assets/css/admin.css', [],           VT_VERSION );
    wp_enqueue_script( 'vt-admin', VT_PLUGIN_URL . 'assets/js/admin.js',  [ 'jquery' ], VT_VERSION, true );
    wp_localize_script( 'vt-admin', 'VT_Admin', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'vt_admin' ),
    ] );
}

// ── Dashboard ─────────────────────────────────────────────────────────────────

/**
 * Read the three "Hide" settings, transparently falling back to the old
 * pre-2.2.0 option names (vt_skip_bots, vt_exclude_datacenter_ips) as the
 * default if the new options have never been saved yet. Once saved via the
 * settings form, the new option names take over permanently.
 *
 * @return array{1: bool, 2: bool, 3: bool}
 */
function vt_get_bot_hide_settings() {
    return [
        1 => get_option( 'vt_hide_bots_tier1', get_option( 'vt_skip_bots', '1' ) ) === '1',
        2 => get_option( 'vt_hide_bots_tier2', get_option( 'vt_exclude_datacenter_ips', '1' ) ) === '1',
        3 => get_option( 'vt_hide_bots_tier3', '0' ) === '1',
    ];
}

function vt_dashboard_page() {
    global $wpdb;
    $st = $wpdb->prefix . VT_TABLE_SESSIONS;
    $pt = $wpdb->prefix . VT_TABLE_PAGEVIEWS;
    $et = $wpdb->prefix . VT_TABLE_EVENTS;

    // Filters
    $search     = isset( $_GET['vt_search'] )   ? sanitize_text_field( wp_unslash( $_GET['vt_search'] ) )  : '';
    $filter_tag = isset( $_GET['vt_tag'] )      ? sanitize_text_field( wp_unslash( $_GET['vt_tag'] ) )     : '';
    $filter_src = isset( $_GET['vt_utm_src'] )  ? sanitize_text_field( wp_unslash( $_GET['vt_utm_src'] ) ) : '';
    $filter_cc  = isset( $_GET['vt_country'] )  ? sanitize_text_field( wp_unslash( $_GET['vt_country'] ) ) : '';
    $date_from  = isset( $_GET['vt_from'] )     ? sanitize_text_field( wp_unslash( $_GET['vt_from'] ) )    : '';
    $date_to    = isset( $_GET['vt_to'] )       ? sanitize_text_field( wp_unslash( $_GET['vt_to'] ) )      : '';
    $paged      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset     = ( $paged - 1 ) * VT_PER_PAGE;

    // Bot visibility — each tier can be hidden independently via settings
    // (see vt_get_bot_hide_settings), but ?vt_show_bots=1 overrides all of
    // them for the current view so nothing is ever permanently invisible.
    $hide_settings  = vt_get_bot_hide_settings();
    $show_bots      = isset( $_GET['vt_show_bots'] ) && $_GET['vt_show_bots'] === '1';
    $hidden_tiers   = $show_bots ? [] : array_keys( array_filter( $hide_settings ) );

    // CSV export (always includes bots — they're tagged in the CSV instead)
    if ( isset( $_GET['vt_export'] ) && current_user_can( 'manage_options' ) ) {
        vt_export_csv( $st, $search, $filter_tag, $filter_src, $filter_cc, $date_from, $date_to );
        exit;
    }

    // Legacy bot rescan — backfills sessions recorded before is_bot_ip existed
    $rescan_notice = '';
    if ( isset( $_GET['vt_rescan_bots'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'vt_rescan_bots' ) ) {
        $result = vt_rescan_bots_batch( 20 );
        if ( $result['checked'] > 0 ) {
            $rescan_notice = sprintf(
                'Re-scanned %d session(s) for bot traffic. %s',
                $result['checked'],
                $result['remaining'] > 0
                    ? $result['remaining'] . ' still remaining — click "Re-scan for bots" again to continue.'
                    : 'All sessions are now checked.'
            );
        } else {
            $rescan_notice = 'No unscanned sessions found — everything is already up to date.';
        }
    }
    $unscanned_bots = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$st} WHERE bot_checked_at IS NULL" );

    // Build WHERE
    [ $where, $params ] = vt_build_where( $search, $filter_tag, $filter_src, $filter_cc, $date_from, $date_to, $hidden_tiers );

    $count_sql = "SELECT COUNT(*) FROM {$st} {$where}";
    $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

    $data_sql  = "SELECT * FROM {$st} {$where} ORDER BY last_seen_at DESC LIMIT %d OFFSET %d";
    $sessions  = $params
        ? $wpdb->get_results( $wpdb->prepare( $data_sql, ...array_merge( $params, [ VT_PER_PAGE, $offset ] ) ) )
        : $wpdb->get_results( $wpdb->prepare( $data_sql, VT_PER_PAGE, $offset ) );

    // Stats strip (last 30 days) — respects the bot visibility toggle above.
    // $hidden_tiers is always a small array of ints from server-side
    // settings (1/2/3), never raw user input, so direct interpolation here
    // is safe.
    $hidden_list = implode( ',', array_map( 'intval', $hidden_tiers ) );
    $s30 = "WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . ( $hidden_list ? " AND bot_tier NOT IN ({$hidden_list})" : '' );
    $stat_sess = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$st} {$s30}" );
    $stat_uniq = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT ip_address) FROM {$st} {$s30}" );
    $stat_conv = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$st} {$s30} AND is_converted = 1" );
    $stat_tag  = $wpdb->get_var( "SELECT tag FROM {$st} {$s30} AND tag != '' GROUP BY tag ORDER BY COUNT(*) DESC LIMIT 1" );
    $stat_ref  = $wpdb->get_var( "SELECT referrer FROM {$st} {$s30} AND referrer != '' GROUP BY referrer ORDER BY COUNT(*) DESC LIMIT 1" );
    if ( $stat_ref ) {
        $p        = parse_url( $stat_ref );
        $stat_ref = $p['host'] ?? $stat_ref;
    }

    // Filter dropdowns
    $tags      = $wpdb->get_col( "SELECT DISTINCT tag FROM {$st} WHERE tag != '' ORDER BY tag ASC" );
    $sources   = $wpdb->get_col( "SELECT DISTINCT utm_source FROM {$st} WHERE utm_source != '' ORDER BY utm_source ASC" );
    $countries = $wpdb->get_results( "SELECT DISTINCT country_code, country FROM {$st} WHERE country_code != '' ORDER BY country ASC" );

    $total_pages = ceil( $total / VT_PER_PAGE );
    $anonymize   = get_option( 'vt_anonymize_ip', '0' );
    $tag_descriptions = get_option( 'vt_tag_descriptions', [] );
    ?>
    <div class="wrap vt-wrap">

        <h1 class="vt-heading">
            <span class="dashicons dashicons-location-alt"></span> Visitor Trails
            <span class="vt-version">v<?php echo VT_VERSION; ?></span>
        </h1>

        <?php if ( $rescan_notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $rescan_notice ); ?></p></div>
        <?php endif; ?>

        <!-- Stats strip -->
        <div class="vt-stats-strip">
            <?php foreach ( [
                [ $stat_sess, 'Sessions (30d)' ],
                [ $stat_uniq, 'Unique IPs (30d)' ],
                [ $stat_conv, 'Converted (30d)' ],
                [ $stat_tag  ?: '—', 'Top Tag (30d)' ],
                [ $stat_ref  ?: '—', 'Top Referrer (30d)' ],
            ] as [ $val, $label ] ) : ?>
            <div class="vt-stat">
                <span class="vt-stat-value"><?php echo is_numeric( $val ) ? number_format( $val ) : esc_html( $val ); ?></span>
                <span class="vt-stat-label"><?php echo esc_html( $label ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <form method="get" class="vt-filters">
            <input type="hidden" name="page" value="visitor-trails">
            <div class="vt-filter-row">
                <input type="text" name="vt_search" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search IP, tag, UTM, referrer, city…" class="vt-search-input">

                <select name="vt_tag">
                    <option value="">All Tags</option>
                    <?php foreach ( $tags as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $filter_tag, $t ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="vt_utm_src">
                    <option value="">All UTM Sources</option>
                    <?php foreach ( $sources as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_src, $s ); ?>><?php echo esc_html( $s ); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="vt_country">
                    <option value="">All Countries</option>
                    <?php foreach ( $countries as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->country_code ); ?>" <?php selected( $filter_cc, $c->country_code ); ?>>
                            <?php echo esc_html( $c->country ); ?> (<?php echo esc_html( $c->country_code ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="vt_from" value="<?php echo esc_attr( $date_from ); ?>" title="From date">
                <input type="date" name="vt_to"   value="<?php echo esc_attr( $date_to ); ?>"   title="To date">

                <button type="submit" class="button">Filter</button>
                <a href="<?php echo admin_url( 'admin.php?page=visitor-trails' ); ?>" class="button">Reset</a>
            </div>
        </form>

        <!-- Toolbar -->
        <div class="vt-toolbar">
            <span class="vt-count"><?php echo number_format( $total ); ?> sessions found</span>
            <div class="vt-toolbar-actions">
                <?php if ( $unscanned_bots > 0 ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'vt_rescan_bots', '1' ), 'vt_rescan_bots' ) ); ?>"
                       class="button button-secondary"
                       title="Backfill bot detection for sessions recorded before this feature existed">
                        🔍 Re-scan for bots (<?php echo number_format( $unscanned_bots ); ?> unchecked)
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( add_query_arg( 'vt_show_bots', $show_bots ? '0' : '1' ) ); ?>" class="button button-secondary">
                    <?php echo $show_bots ? '↩ Back to filtered view' : '🔍 Show all bots (debug)'; ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'vt_export', '1' ) ); ?>" class="button button-secondary">⬇ Export CSV</a>
                <button type="button" id="vt-toggle-tech" class="button button-secondary" title="Show element id/classes on click events, for debugging">
                    🔧 Show technical details
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="vt-table-wrap">
            <table class="widefat vt-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>IP</th>
                        <th>Location</th>
                        <th>Tag</th>
                        <th>UTM Source</th>
                        <th>UTM Campaign</th>
                        <th>Referrer</th>
                        <th>Entry Page</th>
                        <th>Pages</th>
                        <th>Clicks</th>
                        <th>Device</th>
                        <th>Conv.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $sessions ) ) : ?>
                    <tr><td colspan="13" class="vt-empty">No sessions recorded yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $sessions as $s ) :
                        $pv_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pt} WHERE session_id = %d", $s->id ) );
                        $ev_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$et} WHERE session_id = %d", $s->id ) );
                        $display_ip = $anonymize ? $s->ip_anon : $s->ip_address;
                        $ref_domain = '';
                        if ( $s->referrer ) {
                            $p          = parse_url( $s->referrer );
                            $ref_domain = $p['host'] ?? $s->referrer;
                        }
                        $entry_path = str_replace( home_url(), '', $s->entry_page ) ?: '/';
                    ?>
                    <tr class="vt-session-row <?php echo $s->is_converted ? 'vt-converted' : ''; ?>" data-id="<?php echo (int) $s->id; ?>">
                        <td class="vt-td-date" title="Started <?php echo esc_attr( date( 'M j, Y g:i a', strtotime( $s->started_at ) ) ); ?>">
                            <span class="vt-date"><?php echo esc_html( date( 'M j, Y', strtotime( $s->last_seen_at ) ) ); ?></span>
                            <span class="vt-time"><?php echo esc_html( date( 'g:i a', strtotime( $s->last_seen_at ) ) ); ?></span>
                        </td>
                        <td class="vt-mono">
                            <?php if ( $s->is_returning ) : ?>
                                <span class="vt-badge vt-badge-returning" title="This visitor had a previous session before this one">↺</span>
                            <?php endif; ?>
                            <?php echo esc_html( $display_ip ); ?><?php echo vt_bot_dot_html( $s->bot_tier, $s->bot_reason ); ?>
                        </td>
                        <td>
                            <?php if ( $s->country_code ) : ?>
                                <span class="vt-flag"><?php echo vt_flag_emoji( $s->country_code ); ?></span>
                                <?php echo esc_html( $s->city ? $s->city . ', ' . $s->country_code : $s->country_code ); ?>
                            <?php else : echo '—'; endif; ?>
                        </td>
                        <td>
                            <?php if ( $s->tag ) :
                                $tag_desc = $tag_descriptions[ $s->tag ] ?? '';
                            ?>
                                <span class="vt-badge vt-badge-tag vt-tag-editable"
                                      data-tag="<?php echo esc_attr( $s->tag ); ?>"
                                      data-desc="<?php echo esc_attr( $tag_desc ); ?>"
                                      title="<?php echo esc_attr( $tag_desc ?: 'Click to add a description' ); ?>">
                                    <?php echo esc_html( $s->tag ); ?>
                                </span>
                            <?php else : echo '—'; endif; ?>
                        </td>
                        <td><?php echo $s->utm_source ? '<span class="vt-badge vt-badge-utm">' . esc_html( $s->utm_source ) . '</span>' : '—'; ?></td>
                        <td><?php echo $s->utm_campaign ? esc_html( $s->utm_campaign ) : '—'; ?></td>
                        <td title="<?php echo esc_attr( $s->referrer ); ?>"><?php echo $ref_domain ? esc_html( $ref_domain ) : '—'; ?></td>
                        <td class="vt-entry-page" title="<?php echo esc_attr( $s->entry_page ); ?>"><?php echo esc_html( $entry_path ); ?></td>
                        <td class="vt-center"><?php echo $pv_count; ?></td>
                        <td class="vt-center"><?php echo $ev_count ? '<span class="vt-click-count">' . $ev_count . '</span>' : '—'; ?></td>
                        <td><?php echo esc_html( $s->device_type ?: '—' ); ?></td>
                        <td class="vt-center">
                            <button class="vt-conv-btn <?php echo $s->is_converted ? 'vt-conv-yes' : ''; ?>"
                                    data-id="<?php echo (int) $s->id; ?>"
                                    title="<?php echo $s->is_converted ? 'Mark unconverted' : 'Mark converted'; ?>">
                                <?php echo $s->is_converted ? '★' : '☆'; ?>
                            </button>
                        </td>
                        <td><button class="button button-small vt-trail-toggle" data-id="<?php echo (int) $s->id; ?>">Trail ▾</button></td>
                    </tr>

                    <!-- Trail expand row -->
                    <tr class="vt-trail-row" id="vt-trail-<?php echo (int) $s->id; ?>" style="display:none;">
                        <td colspan="13">
                            <div class="vt-trail-inner">
                                <?php
                                $pageviews = $wpdb->get_results( $wpdb->prepare(
                                    "SELECT * FROM {$pt} WHERE session_id = %d ORDER BY viewed_at ASC",
                                    $s->id
                                ) );
                                if ( $pageviews ) :
                                ?>
                                <ol class="vt-trail-list">
                                <?php foreach ( $pageviews as $i => $pv ) :
                                    $path   = str_replace( home_url(), '', $pv->page_url ) ?: '/';
                                    $events = $wpdb->get_results( $wpdb->prepare(
                                        "SELECT * FROM {$et} WHERE pageview_id = %d ORDER BY fired_at ASC",
                                        $pv->id
                                    ) );
                                ?>
                                    <li class="vt-trail-item">
                                        <div class="vt-trail-page">
                                            <span class="vt-trail-num"><?php echo $i + 1; ?></span>
                                            <span class="vt-trail-path"><?php echo esc_html( $path ); ?></span>
                                            <?php if ( $pv->page_title ) : ?>
                                                <span class="vt-trail-title"><?php echo esc_html( $pv->page_title ); ?></span>
                                            <?php endif; ?>
                                            <span class="vt-trail-time"><?php echo esc_html( date( 'g:i:s a', strtotime( $pv->viewed_at ) ) ); ?></span>
                                            <?php $dur = vt_format_duration( $pv->duration_seconds ); ?>
                                            <?php if ( $dur ) : ?>
                                                <span class="vt-trail-duration" title="Time on page">⏱ <?php echo esc_html( $dur ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ( ! empty( $events ) ) : ?>
                                        <ul class="vt-click-list">
                                            <?php foreach ( $events as $ev ) :
                                                $label = $ev->vp_label ?: $ev->element_text ?: $ev->element_id ?: $ev->element_classes ?: '(unlabeled)';
                                                $tag_title = $ev->element_tag ? '<' . $ev->element_tag . '> element' : 'Element type unknown (recorded before this was tracked)';
                                            ?>
                                            <li class="vt-click-item">
                                                <span class="vt-click-icon" title="<?php echo esc_attr( $tag_title ); ?>"><?php echo vt_click_icon( $ev->element_tag ); ?></span>
                                                <span class="vt-click-label"><?php echo esc_html( $label ); ?></span>
                                                <?php if ( ! empty( $ev->section_label ) ) : ?>
                                                    <span class="vt-badge vt-badge-section" title="Section this click happened in"><?php echo esc_html( $ev->section_label ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( $ev->element_id || $ev->element_classes ) : ?>
                                                    <span class="vt-click-tech">
                                                        <?php if ( $ev->element_id ) : ?>#<?php echo esc_html( $ev->element_id ); ?><?php endif; ?>
                                                        <?php if ( $ev->element_classes ) : ?> .<?php echo esc_html( str_replace( ' ', ' .', $ev->element_classes ) ); ?><?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ( $ev->element_href ) : ?>
                                                    <a class="vt-click-href" href="<?php echo esc_url( $ev->element_href ); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo esc_html( str_replace( home_url(), '', $ev->element_href ) ); ?>
                                                    </a>
                                                <?php endif; ?>
                                                <span class="vt-click-time"><?php echo esc_html( date( 'g:i:s a', strtotime( $ev->fired_at ) ) ); ?></span>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                                </ol>
                                <?php else : ?>
                                    <p class="vt-empty">No pageviews for this session.</p>
                                <?php endif; ?>

                                <!-- Full UTMs + session meta -->
                                <div class="vt-session-meta">
                                    <?php if ( $s->utm_source || $s->utm_medium || $s->utm_campaign ) : ?>
                                    <div class="vt-meta-row">
                                        <strong>UTMs:</strong>
                                        source: <?php echo esc_html( $s->utm_source ?: '—' ); ?> &nbsp;|&nbsp;
                                        medium: <?php echo esc_html( $s->utm_medium ?: '—' ); ?> &nbsp;|&nbsp;
                                        campaign: <?php echo esc_html( $s->utm_campaign ?: '—' ); ?>
                                        <?php if ( $s->utm_term || $s->utm_content ) : ?>
                                            &nbsp;|&nbsp; term: <?php echo esc_html( $s->utm_term ?: '—' ); ?>
                                            &nbsp;|&nbsp; content: <?php echo esc_html( $s->utm_content ?: '—' ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="vt-meta-row">
                                        <strong>OS:</strong> <?php echo esc_html( $s->os ?: '—' ); ?> &nbsp;|&nbsp;
                                        <strong>Browser:</strong> <?php echo esc_html( $s->browser ?: '—' ); ?> &nbsp;|&nbsp;
                                        <strong>Exit page:</strong> <?php echo esc_html( str_replace( home_url(), '', $s->exit_page ) ?: '/' ); ?> &nbsp;|&nbsp;
                                        <strong>Started:</strong> <?php echo esc_html( date( 'M j, g:i a', strtotime( $s->started_at ) ) ); ?> &nbsp;|&nbsp;
                                        <strong>Last active:</strong> <?php echo esc_html( vt_time_ago( $s->last_seen_at ) ); ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
        <div class="vt-pagination">
            <?php
            $base = add_query_arg( array_filter( [
                'page'         => 'visitor-trails',
                'vt_search'    => $search,
                'vt_tag'       => $filter_tag,
                'vt_utm_src'   => $filter_src,
                'vt_country'   => $filter_cc,
                'vt_from'      => $date_from,
                'vt_to'        => $date_to,
                'vt_show_bots' => $show_bots ? '1' : '0',
            ] ) );
            for ( $p = 1; $p <= $total_pages; $p++ ) :
                $cls = $p === $paged ? ' vt-page-active' : '';
            ?>
                <a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base ) ); ?>" class="vt-page-btn<?php echo $cls; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php
}

// ── Settings ──────────────────────────────────────────────────────────────────

function vt_settings_page() {
    if ( isset( $_POST['vt_save_settings'] ) && check_admin_referer( 'vt_settings' ) ) {
        update_option( 'vt_anonymize_ip',     isset( $_POST['vt_anonymize_ip'] )     ? '1' : '0' );
        update_option( 'vt_hide_bots_tier1',  isset( $_POST['vt_hide_bots_tier1'] )  ? '1' : '0' );
        update_option( 'vt_hide_bots_tier2',  isset( $_POST['vt_hide_bots_tier2'] )  ? '1' : '0' );
        update_option( 'vt_hide_bots_tier3',  isset( $_POST['vt_hide_bots_tier3'] )  ? '1' : '0' );
        update_option( 'vt_skip_admins',      isset( $_POST['vt_skip_admins'] )      ? '1' : '0' );
        update_option( 'vt_purge_days',       max( 0, (int) ( $_POST['vt_purge_days'] ?? 90 ) ) );
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    $anonymize     = get_option( 'vt_anonymize_ip', '0' );
    $hide_settings = vt_get_bot_hide_settings();
    $skip_admins   = get_option( 'vt_skip_admins', '1' );
    $purge_days    = get_option( 'vt_purge_days',  90 );
    ?>
    <div class="wrap vt-wrap">
        <h1>Visitor Trails — Settings <span class="vt-version">v<?php echo VT_VERSION; ?></span></h1>
        <form method="post">
            <?php wp_nonce_field( 'vt_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>IP Anonymization</th>
                    <td>
                        <label>
                            <input type="checkbox" name="vt_anonymize_ip" value="1" <?php checked( $anonymize, '1' ); ?>>
                            Mask last IP octet (e.g. 192.168.1.<strong>0</strong>) — recommended for GDPR compliance
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Bot &amp; Crawler Detection</th>
                    <td>
                        <p class="description" style="margin-top:0;">
                            Every session is always checked and tagged with a confidence tier — these settings only
                            control which tiers are hidden from the dashboard <strong>by default</strong>. Nothing is
                            ever discarded: use the "Show hidden bot/VPN traffic" toggle on the dashboard to see
                            everything regardless of these settings, and hover the colored dot next to any IP for
                            the specific reason it was tagged.
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="vt_hide_bots_tier1" value="1" <?php checked( $hide_settings[1], true ); ?>>
                                <strong>Hide known bots/crawlers</strong> — declared themselves as a crawler in their browser identification
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="vt_hide_bots_tier2" value="1" <?php checked( $hide_settings[2], true ); ?>>
                                <strong>Hide known crawler/AI infrastructure</strong> — Google, Bing, OpenAI, Anthropic, Common Crawl, etc.
                                (catches traffic that looks like a normal browser but runs on a known crawler's network)
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="vt_hide_bots_tier3" value="1" <?php checked( $hide_settings[3], true ); ?>>
                                <strong>Hide unsure/VPN traffic</strong> — general-purpose cloud/hosting IPs (AWS, DigitalOcean, OVH, etc.)
                            </label>
                            <br><span class="description">
                                Off by default — this tier is inherently ambiguous. A real visitor on a commercial VPN
                                and a script running on a rented server look identical from IP data alone, so we show
                                these rather than guess. Look for short/no time-on-page as a secondary signal.
                            </span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Skip Admins</th>
                    <td>
                        <label>
                            <input type="checkbox" name="vt_skip_admins" value="1" <?php checked( $skip_admins, '1' ); ?>>
                            Don't record sessions for logged-in administrators
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Auto-Purge After</th>
                    <td>
                        <input type="number" name="vt_purge_days" value="<?php echo (int) $purge_days; ?>" min="0" max="3650" style="width:80px;"> days
                        <p class="description">Sessions older than this are deleted automatically. Set to <strong>0</strong> to disable purging.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="vt_save_settings" class="button button-primary">Save Settings</button>
            </p>
        </form>
    </div>
    <?php
}

// ── WHERE builder (shared by dashboard + export) ──────────────────────────────

function vt_build_where( $search, $tag, $src, $cc, $from, $to, $hidden_tiers = [] ) {
    global $wpdb;
    $where  = 'WHERE 1=1';
    $params = [];

    if ( $search ) {
        $like    = '%' . $wpdb->esc_like( $search ) . '%';
        $where  .= ' AND (ip_address LIKE %s OR ip_anon LIKE %s OR tag LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR referrer LIKE %s OR city LIKE %s OR country LIKE %s OR entry_page LIKE %s)';
        $params  = array_merge( $params, array_fill( 0, 9, $like ) );
    }
    if ( $tag  ) { $where .= ' AND tag = %s';          $params[] = $tag;  }
    if ( $src  ) { $where .= ' AND utm_source = %s';   $params[] = $src;  }
    if ( $cc   ) { $where .= ' AND country_code = %s'; $params[] = $cc;   }
    if ( $from ) { $where .= ' AND DATE(started_at) >= %s'; $params[] = $from; }
    if ( $to   ) { $where .= ' AND DATE(started_at) <= %s'; $params[] = $to;   }

    if ( ! empty( $hidden_tiers ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $hidden_tiers ), '%d' ) );
        $where .= " AND bot_tier NOT IN ({$placeholders})";
        foreach ( $hidden_tiers as $t ) { $params[] = (int) $t; }
    }

    return [ $where, $params ];
}

// ── CSV export ────────────────────────────────────────────────────────────────

function vt_export_csv( $st, $search, $tag, $src, $cc, $from, $to ) {
    global $wpdb;
    [ $where, $params ] = vt_build_where( $search, $tag, $src, $cc, $from, $to );

    $sql      = "SELECT * FROM {$st} {$where} ORDER BY last_seen_at DESC";
    $sessions = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

    $anonymize = get_option( 'vt_anonymize_ip', '0' );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="visitor-trails-' . date( 'Y-m-d' ) . '.csv"' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'Date', 'Last Active', 'IP', 'Returning', 'Country', 'City', 'Tag', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'Referrer', 'Entry Page', 'Exit Page', 'Browser', 'Device', 'OS', 'Converted', 'Bot Tier', 'Bot Reason' ] );

    foreach ( $sessions as $s ) {
        fputcsv( $out, [
            $s->started_at,
            $s->last_seen_at,
            $anonymize ? $s->ip_anon : $s->ip_address,
            $s->is_returning ? 'Yes' : 'No',
            $s->country, $s->city, $s->tag,
            $s->utm_source, $s->utm_medium, $s->utm_campaign, $s->utm_term, $s->utm_content,
            $s->referrer, $s->entry_page, $s->exit_page,
            $s->browser, $s->device_type, $s->os,
            $s->is_converted ? 'Yes' : 'No',
            vt_bot_tier_label( $s->bot_tier ),
            $s->bot_reason,
        ] );
    }
    fclose( $out );
}
