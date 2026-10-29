<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.3.1
 * Author: Gabor Angyal
 * Author URI: https://woodevops.com
 * License: GPL3
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_WEB_VITALS_RETENTION_DAYS', 90);
define('WP_WEB_VITALS_USER_TYPE_GUEST', 'guest');
define('WP_WEB_VITALS_USER_TYPE_LOGGED_IN', 'logged_in');
define('WP_WEB_VITALS_ADMIN_HOOK_SUFFIX', 'tools_page_web-vitals-averages');

register_activation_hook(__FILE__, 'wp_web_vitals_create_table');
register_activation_hook(__FILE__, 'wp_web_vitals_schedule_log_pruning');

function wp_web_vitals_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'web_vitals_logs';
}

function wp_web_vitals_create_table() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        path text NOT NULL,
        ttfb float NOT NULL,
        fcp float NOT NULL,
        measurement_seconds float NOT NULL,
        user_type varchar(255) DEFAULT '' NOT NULL,
        url text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        INDEX idx_created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    dbDelta($sql);
    if ($wpdb->last_error) {
        error_log("Error creating web vitals logs table: " . $wpdb->last_error);
    } else {
        error_log("Web vitals logs table created successfully or already exists.");
    }
}

function wp_web_vitals_delete_table(): void {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);

    if ($wpdb->last_error) {
        error_log("Error deleting web vitals logs table: " . $wpdb->last_error);
    } else {
        error_log("Web vitals logs table deleted successfully.");
    }
}

function wp_web_vitals_delete_expired_logs(): void {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();
    $sql = $wpdb->prepare(
        "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY);",
        WP_WEB_VITALS_RETENTION_DAYS
    );
    $wpdb->query($sql);

    if ($wpdb->last_error) {
        error_log("Error deleting expired web vitals logs: " . $wpdb->last_error);
    }
}

function wp_web_vitals_schedule_log_pruning(): void {
    if (!wp_next_scheduled('wp_web_vitals_prune_logs')) {
        wp_schedule_event(time(), 'daily', 'wp_web_vitals_prune_logs');
    }
}

function wp_web_vitals_unschedule_log_pruning(): void {
    wp_clear_scheduled_hook('wp_web_vitals_prune_logs');
}

register_deactivation_hook(__FILE__, 'wp_web_vitals_unschedule_log_pruning');

add_action('wp_web_vitals_prune_logs', 'wp_web_vitals_delete_expired_logs');

function wp_web_vitals_get_path_prefix() {
    return get_option('wp_web_vitals_path_prefix', '');
}

function wp_web_vitals_should_load_script() {
    $prefix = wp_web_vitals_get_path_prefix();
    
    if (empty($prefix)) {
        return true;
    }
    
    $current_path = wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return strpos($current_path, $prefix) === 0;
}

add_action('wp_enqueue_scripts', 'wp_web_vitals_enqueue_script');

function wp_web_vitals_enqueue_script() {
    if (!wp_web_vitals_should_load_script()) {
        return;
    }
    
    wp_enqueue_script('wp-web-vitals', plugin_dir_url(__FILE__) . 'wp-web-vitals.js', [], '0.3.1', true);
    wp_localize_script('wp-web-vitals', 'wpWebVitals', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp-web-vitals-nonce')
    ]);
}

add_action('wp_ajax_nopriv_log_webvitals', 'wp_web_vitals_log_webvitals');
add_action('wp_ajax_log_webvitals', 'wp_web_vitals_log_webvitals');

function wp_web_vitals_user_role_allowed($role) {
    if ($role === 'customer') {
        return true;
    }
    
    if (strlen($role) >= 6 && substr($role, -6) === '_users') {
        return true;
    }
    
    if (strpos($role, 'markanagykovet') === 0) {
        return true;
    }
    
    return false;
}

function wp_web_vitals_resolve_user_type(): string {
    if (is_user_logged_in()) {
        return WP_WEB_VITALS_USER_TYPE_LOGGED_IN;
    }

    return WP_WEB_VITALS_USER_TYPE_GUEST;
}

function wp_web_vitals_is_same_host(string $url, string $site_host): bool {
    return wp_parse_url($url, PHP_URL_HOST) === $site_host;
}

function wp_web_vitals_log_webvitals() {
    check_ajax_referer('wp-web-vitals-nonce', 'nonce');

    $ttfb = isset($_POST['ttfb']) ? floatval($_POST['ttfb']) : -1;
    $fcp = isset($_POST['fcp']) ? floatval($_POST['fcp']) : -1;
    $measurement_seconds = isset($_POST['measurementSeconds']) ? floatval($_POST['measurementSeconds']) : 0;
    $user_type = wp_web_vitals_resolve_user_type();
    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';

    if (empty($url)) {
        wp_send_json_error('Invalid data received.');
    }

    if (!wp_web_vitals_is_same_host($url, wp_parse_url(home_url(), PHP_URL_HOST))) {
        wp_send_json_error('URL does not belong to this site.');
    }

    $prefix = wp_web_vitals_get_path_prefix();
    if (!empty($prefix)) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (strpos($path, $prefix) !== 0) {
            wp_send_json_error('URL does not match the configured path prefix.');
        }
    }

    if ($ttfb === -1 && $fcp === -1) {
        wp_send_json_error('At least one measurement (TTFB or FCP) must be available.');
    }

    if ($user_type === WP_WEB_VITALS_USER_TYPE_LOGGED_IN) {
        $current_user = wp_get_current_user();

        $user_roles = $current_user->roles;
        $allowed = false;

        foreach ($user_roles as $role) {
            if (wp_web_vitals_user_role_allowed($role)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            wp_send_json_success('User role not allowed for logging.');
        }
    }

    global $wpdb;
    $path = wp_parse_url($url, PHP_URL_PATH);

    $wpdb->insert(wp_web_vitals_table_name(), [
        'path' => $path,
        'ttfb' => $ttfb,
        'fcp' => $fcp,
        'measurement_seconds' => $measurement_seconds,
        'url' => $url,
        'user_type' => $user_type,
        'created_at' => current_time('mysql')
    ]);
    
    if ($wpdb->last_error) {
        wp_send_json_error('Error logging performance data. ' . $wpdb->last_error);
    } else {
        wp_send_json_success('Performance data logged successfully.');
    }
}

add_action('wp_ajax_clean_webvitals_data', 'wp_web_vitals_clean_data');

function wp_web_vitals_clean_data() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions.');
    }

    check_ajax_referer('wp-web-vitals-admin-nonce', 'nonce');

    wp_web_vitals_delete_table();
    wp_web_vitals_create_table();

    wp_send_json_success('All data has been cleaned and tables have been recreated.');
}

add_action('wp_ajax_save_webvitals_settings', 'wp_web_vitals_save_settings');

function wp_web_vitals_save_settings() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions.');
    }

    check_ajax_referer('wp-web-vitals-admin-nonce', 'nonce');

    $prefix = isset($_POST['path_prefix']) ? sanitize_text_field($_POST['path_prefix']) : '';
    
    if (!empty($prefix)) {
        if (substr($prefix, 0, 1) !== '/') {
            wp_send_json_error('Path prefix must start with / (forward slash).');
        }
        if (substr($prefix, -1) === '/') {
            $prefix = rtrim($prefix, '/');
        }
    }
    
    update_option('wp_web_vitals_path_prefix', $prefix);
    wp_send_json_success('Settings saved successfully.');
}

add_action('admin_menu', 'wp_web_vitals_admin_menu');

function wp_web_vitals_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Web Vitals Averages',
        'Web Vitals',
        'manage_options',
        'web-vitals-averages',
        'wp_web_vitals_admin_page'
    );
}

function wp_web_vitals_enqueue_admin_assets(string $hook_suffix): void {
    if ($hook_suffix !== WP_WEB_VITALS_ADMIN_HOOK_SUFFIX) {
        return;
    }

    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
    wp_enqueue_style('wp-web-vitals-admin', plugin_dir_url(__FILE__) . 'admin/web-vitals-admin.css', [], '0.3.1');
    wp_enqueue_script('wp-web-vitals-admin', plugin_dir_url(__FILE__) . 'admin/web-vitals-admin.js', ['chartjs'], '0.3.1', true);
}

add_action('admin_enqueue_scripts', 'wp_web_vitals_enqueue_admin_assets');

function wp_web_vitals_get_pages_with_data() {
    global $wpdb;
    $logs_table = wp_web_vitals_table_name();

    $results = $wpdb->get_results("
        SELECT DISTINCT path
        FROM $logs_table
        ORDER BY path ASC
    ");

    return $results;
}

function wp_web_vitals_admin_page(): void {
    global $wpdb;

    $selected_page = isset($_GET['page_path']) ? sanitize_text_field($_GET['page_path']) : '';

    $pages = wp_web_vitals_get_pages_with_data();

    $chart_data = wp_web_vitals_get_chart_data($selected_page);
    
    $current_prefix = wp_web_vitals_get_path_prefix();

    ?>
    <div class="wrap" id="wp-web-vitals-admin"
         data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
         data-settings-nonce="<?php echo esc_attr(wp_create_nonce('wp-web-vitals-admin-nonce')); ?>"
         data-clean-nonce="<?php echo esc_attr(wp_create_nonce('wp-web-vitals-admin-nonce')); ?>"
         data-chart-data="<?php echo esc_attr(wp_json_encode($chart_data)); ?>">
        <h1 class="wp-heading-inline">Web Vitals Analytics</h1>

        <div class="wp-web-vitals-actions">
            <button id="clean-data-button" class="button button-secondary">Clean All Data</button>
        </div>

        <table class="form-table wp-web-vitals-settings">
            <tr>
                <th scope="row">
                    <label for="path-prefix-input">Path prefix</label>
                </th>
                <td>
                    <input type="text" id="path-prefix-input" class="regular-text" value="<?php echo esc_attr($current_prefix); ?>" placeholder="/blog">
                    <p class="description">Leave empty to track all pages. Add a prefix like /blog to track only pages starting with that path.</p>
                    <p>
                        <button id="save-settings-button" class="button button-primary">Save Settings</button>
                    </p>
                </td>
            </tr>
        </table>

        <div class="wp-web-vitals-page-filter">
            <label for="page-select">Select Page:</label>
            <select id="page-select">
                <option value="">All Pages</option>
                <?php foreach ($pages as $page) : ?>
                    <option value="<?php echo esc_attr($page->path); ?>" <?php selected($selected_page, $page->path); ?>>
                        <?php echo esc_html($page->path); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wp-web-vitals-charts">
            <section class="card wp-web-vitals-chart">
                <h2>First Contentful Paint (FCP) - Last <?php echo WP_WEB_VITALS_RETENTION_DAYS; ?> Days</h2>
                <div class="wp-web-vitals-chart__canvas">
                    <canvas id="fcp-chart"></canvas>
                </div>
            </section>

            <section class="card wp-web-vitals-chart">
                <h2>Time to First Byte (TTFB) - Last <?php echo WP_WEB_VITALS_RETENTION_DAYS; ?> Days</h2>
                <div class="wp-web-vitals-chart__canvas">
                    <canvas id="ttfb-chart"></canvas>
                </div>
            </section>

            <section class="card wp-web-vitals-chart">
                <h2>Request Count - Last <?php echo WP_WEB_VITALS_RETENTION_DAYS; ?> Days</h2>
                <div class="wp-web-vitals-chart__canvas">
                    <canvas id="request-count-chart"></canvas>
                </div>
            </section>
        </div>
    </div>
    <?php
}

function wp_web_vitals_calculate_median(array $values): ?float {
    if (empty($values)) {
        return null;
    }

    $sorted = $values;
    sort($sorted);
    
    $count = count($sorted);
    $mid = intdiv($count - 1, 2);
    
    if ($count % 2) {
        return round($sorted[$mid], 2);
    }
    
    return round(($sorted[$mid] + $sorted[$mid + 1]) / 2, 2);
}

function wp_web_vitals_chart_bucket(string $user_type, string $has_query): ?string {
    // Unknown values are dropped rather than becoming array keys in the chart data.
    if ($has_query !== 'no_query' && $has_query !== 'with_query') {
        return null;
    }

    $with_query = $has_query === 'with_query';

    if ($user_type === WP_WEB_VITALS_USER_TYPE_GUEST) {
        return $with_query ? 'guest_with_query' : 'guest_no_query';
    }

    if ($user_type === WP_WEB_VITALS_USER_TYPE_LOGGED_IN) {
        return $with_query ? 'logged_in_with_query' : 'logged_in_no_query';
    }

    return null;
}

function wp_web_vitals_group_chart_rows(array $rows): array {
    $cells = [];

    foreach ($rows as $row) {
        $bucket = wp_web_vitals_chart_bucket($row['user_type'], $row['has_query']);
        if ($bucket === null) {
            continue;
        }

        $date = $row['date'];
        if (!isset($cells[$date][$bucket])) {
            $cells[$date][$bucket] = ['fcp' => [], 'ttfb' => [], 'count' => 0];
        }

        if ($row['fcp'] >= 0) {
            $cells[$date][$bucket]['fcp'][] = $row['fcp'];
        }
        if ($row['ttfb'] >= 0) {
            $cells[$date][$bucket]['ttfb'][] = $row['ttfb'];
        }
        $cells[$date][$bucket]['count']++;
    }

    return $cells;
}

function wp_web_vitals_series(array $summary, array $dates, string $bucket, string $field): array {
    $series = [];

    foreach ($dates as $date) {
        $series[] = $summary[$date][$bucket][$field];
    }

    return $series;
}

/**
 * @param array<int, array{date: string, user_type: string, has_query: string, fcp: float, ttfb: float}> $rows
 */
function wp_web_vitals_build_chart_data(array $rows): array {
    $cells = wp_web_vitals_group_chart_rows($rows);

    $dates = array_keys($cells);
    rsort($dates);

    $buckets = ['guest_no_query', 'guest_with_query', 'logged_in_no_query', 'logged_in_with_query'];

    $summary = [];
    foreach ($dates as $date) {
        foreach ($buckets as $bucket) {
            $cell = $cells[$date][$bucket] ?? ['fcp' => [], 'ttfb' => [], 'count' => 0];
            $summary[$date][$bucket] = [
                'fcp' => wp_web_vitals_calculate_median($cell['fcp']),
                'ttfb' => wp_web_vitals_calculate_median($cell['ttfb']),
                'count' => $cell['count']
            ];
        }
    }

    return [
        'dates' => $dates,
        'fcp_guest_no_query' => wp_web_vitals_series($summary, $dates, 'guest_no_query', 'fcp'),
        'fcp_guest_with_query' => wp_web_vitals_series($summary, $dates, 'guest_with_query', 'fcp'),
        'fcp_logged_in_no_query' => wp_web_vitals_series($summary, $dates, 'logged_in_no_query', 'fcp'),
        'fcp_logged_in_with_query' => wp_web_vitals_series($summary, $dates, 'logged_in_with_query', 'fcp'),
        'ttfb_guest_no_query' => wp_web_vitals_series($summary, $dates, 'guest_no_query', 'ttfb'),
        'ttfb_guest_with_query' => wp_web_vitals_series($summary, $dates, 'guest_with_query', 'ttfb'),
        'ttfb_logged_in_no_query' => wp_web_vitals_series($summary, $dates, 'logged_in_no_query', 'ttfb'),
        'ttfb_logged_in_with_query' => wp_web_vitals_series($summary, $dates, 'logged_in_with_query', 'ttfb'),
        'ttfb_count_guest_no_query' => wp_web_vitals_series($summary, $dates, 'guest_no_query', 'count'),
        'ttfb_count_guest_with_query' => wp_web_vitals_series($summary, $dates, 'guest_with_query', 'count'),
        'ttfb_count_logged_in_no_query' => wp_web_vitals_series($summary, $dates, 'logged_in_no_query', 'count'),
        'ttfb_count_logged_in_with_query' => wp_web_vitals_series($summary, $dates, 'logged_in_with_query', 'count')
    ];
}

function wp_web_vitals_get_chart_data($page_path = '') {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    if (!empty($page_path)) {
        $sql = $wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                user_type,
                CASE WHEN url LIKE '%?%' THEN 'with_query' ELSE 'no_query' END as has_query,
                fcp,
                ttfb
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND path = %s
        ", WP_WEB_VITALS_RETENTION_DAYS, $page_path);
    } else {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                user_type,
                CASE WHEN url LIKE '%?%' THEN 'with_query' ELSE 'no_query' END as has_query,
                fcp,
                ttfb
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . WP_WEB_VITALS_RETENTION_DAYS . " DAY)
        ";
    }

    $results = $wpdb->get_results($sql);

    $rows = [];
    foreach ($results as $result) {
        $rows[] = [
            'date' => $result->date,
            'user_type' => $result->user_type,
            'has_query' => $result->has_query,
            'fcp' => floatval($result->fcp),
            'ttfb' => floatval($result->ttfb)
        ];
    }

    return wp_web_vitals_build_chart_data($rows);
}