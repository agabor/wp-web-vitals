<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.2.3
 * Author: Gabor Angyal
 * Author URI: https://woodevops.com
 * License: GPL3
 */

 /*
    WP Web Vitals
    Copyright (C) 2024  Code Sharp Kft.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

if (!defined('ABSPATH')) {
    exit;
}

define('WP_WEB_VITALS_CHART_HEIGHT', 400);
define('WP_WEB_VITALS_CHART_MAX_WIDTH', 1200);
define('WP_WEB_VITALS_CHART_MARGIN_BOTTOM', 40);

register_activation_hook(__FILE__, 'wp_web_vitals_create_table');

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

function wp_web_vitals_delete_table() {
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

register_deactivation_hook(__FILE__, 'wp_web_vitals_delete_table');

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
    
    wp_enqueue_script('wp-web-vitals', plugin_dir_url(__FILE__) . 'wp-web-vitals.js', [], '0.2.3', true);
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

function wp_web_vitals_log_webvitals() {
    check_ajax_referer('wp-web-vitals-nonce', 'nonce');

    $ttfb = isset($_POST['ttfb']) ? floatval($_POST['ttfb']) : -1;
    $fcp = isset($_POST['fcp']) ? floatval($_POST['fcp']) : -1;
    $measurement_seconds = isset($_POST['measurementSeconds']) ? floatval($_POST['measurementSeconds']) : 0;
    $user_type = isset($_POST['userType']) ? sanitize_text_field($_POST['userType']) : '';
    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';

    if (empty($url)) {
        wp_send_json_error('Invalid data received.');
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

    if ($user_type === 'logged_in') {
        $current_user = wp_get_current_user();
        if ($current_user->ID === 0) {
            wp_send_json_success('User not authenticated.');
        }

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

function wp_web_vitals_admin_page() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    $selected_page = isset($_GET['page_path']) ? sanitize_text_field($_GET['page_path']) : '';

    $pages = wp_web_vitals_get_pages_with_data();

    $chart_data = wp_web_vitals_get_chart_data($selected_page);
    
    $current_prefix = wp_web_vitals_get_path_prefix();

    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
    
    $chart_height = WP_WEB_VITALS_CHART_HEIGHT;
    $chart_max_width = WP_WEB_VITALS_CHART_MAX_WIDTH;

    $inline_script = "
        const chartData = " . wp_json_encode($chart_data) . ";

        const fcpCtx = document.getElementById('fcpChart').getContext('2d');
        new Chart(fcpCtx, {
            type: 'bar',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Guest (no query)',
                        data: chartData.fcp_guest_no_query,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Guest (with query)',
                        data: chartData.fcp_guest_with_query,
                        backgroundColor: 'rgba(54, 162, 235, 0.4)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (no query)',
                        data: chartData.fcp_logged_in_no_query,
                        backgroundColor: 'rgba(75, 192, 75, 0.7)',
                        borderColor: 'rgba(75, 192, 75, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (with query)',
                        data: chartData.fcp_logged_in_with_query,
                        backgroundColor: 'rgba(75, 192, 75, 0.4)',
                        borderColor: 'rgba(75, 192, 75, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Milliseconds'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });

        const ttfbCtx = document.getElementById('ttfbChart').getContext('2d');
        new Chart(ttfbCtx, {
            type: 'bar',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Guest (no query)',
                        data: chartData.ttfb_guest_no_query,
                        backgroundColor: 'rgba(255, 159, 64, 0.7)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Guest (with query)',
                        data: chartData.ttfb_guest_with_query,
                        backgroundColor: 'rgba(255, 159, 64, 0.4)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (no query)',
                        data: chartData.ttfb_logged_in_no_query,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (with query)',
                        data: chartData.ttfb_logged_in_with_query,
                        backgroundColor: 'rgba(255, 99, 132, 0.4)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Milliseconds'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });

        const fcpCountCtx = document.getElementById('fcpCountChart').getContext('2d');
        new Chart(fcpCountCtx, {
            type: 'bar',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Guest (no query)',
                        data: chartData.fcp_count_guest_no_query,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Guest (with query)',
                        data: chartData.fcp_count_guest_with_query,
                        backgroundColor: 'rgba(54, 162, 235, 0.4)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (no query)',
                        data: chartData.fcp_count_logged_in_no_query,
                        backgroundColor: 'rgba(75, 192, 75, 0.7)',
                        borderColor: 'rgba(75, 192, 75, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (with query)',
                        data: chartData.fcp_count_logged_in_with_query,
                        backgroundColor: 'rgba(75, 192, 75, 0.4)',
                        borderColor: 'rgba(75, 192, 75, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        title: {
                            display: true,
                            text: 'Request Count'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });

        const ttfbCountCtx = document.getElementById('ttfbCountChart').getContext('2d');
        new Chart(ttfbCountCtx, {
            type: 'bar',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Guest (no query)',
                        data: chartData.ttfb_count_guest_no_query,
                        backgroundColor: 'rgba(255, 159, 64, 0.7)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Guest (with query)',
                        data: chartData.ttfb_count_guest_with_query,
                        backgroundColor: 'rgba(255, 159, 64, 0.4)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (no query)',
                        data: chartData.ttfb_count_logged_in_no_query,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in (with query)',
                        data: chartData.ttfb_count_logged_in_with_query,
                        backgroundColor: 'rgba(255, 99, 132, 0.4)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        title: {
                            display: true,
                            text: 'Request Count'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    ";
    
    wp_add_inline_script('chartjs', $inline_script);
    
    $chart_margin = WP_WEB_VITALS_CHART_MARGIN_BOTTOM;
    $chart_container_style = "max-width: {$chart_max_width}px; margin-bottom: {$chart_margin}px; margin-left: auto; margin-right: auto; position: relative; height: {$chart_height}px;";
    $canvas_style = "max-width: 100%;";
    
    ?>
    <div class="wrap">
        <h1>Web Vitals Analytics</h1>

        <div style="margin-bottom: 30px; padding: 15px; background-color: #f5f5f5; border-left: 4px solid #0073aa;">
            <h2 style="margin-top: 0;">Settings</h2>
            <label for="path-prefix-input" style="display: block; margin-bottom: 10px; font-weight: bold;">Path Prefix (optional):</label>
            <input type="text" id="path-prefix-input" value="<?php echo esc_attr($current_prefix); ?>" placeholder="/blog" style="padding: 8px 12px; font-size: 14px; width: 300px; max-width: 100%; margin-right: 10px;">
            <button id="save-settings-button" class="button button-primary" style="margin-bottom: 5px;">Save Settings</button>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">Leave empty to track all pages. Add a prefix like /blog to track only pages starting with that path.</p>
        </div>

        <script type="text/javascript">
            document.getElementById('save-settings-button').addEventListener('click', function() {
                var pathPrefix = document.getElementById('path-prefix-input').value.trim();
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_js(admin_url('admin-ajax.php')); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }
                };
                xhr.send('action=save_webvitals_settings&path_prefix=' + encodeURIComponent(pathPrefix) + '&nonce=<?php echo esc_js(wp_create_nonce('wp-web-vitals-admin-nonce')); ?>');
            });
        </script>

        <button id="clean-data-button" class="button button-secondary" style="margin-bottom: 20px;">Clean All Data</button>

        <script type="text/javascript">
            document.getElementById('clean-data-button').addEventListener('click', function() {
                if (confirm('Are you sure you want to delete all data? This action cannot be undone.')) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js(admin_url('admin-ajax.php')); ?>', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                alert(response.data);
                                location.reload();
                            } else {
                                alert('Error: ' + response.data);
                            }
                        }
                    };
                    xhr.send('action=clean_webvitals_data&nonce=<?php echo esc_js(wp_create_nonce('wp-web-vitals-admin-nonce')); ?>');
                }
            });
        </script>

        <div style="margin-bottom: 30px;">
            <label for="page-select" style="font-weight: bold; margin-right: 10px;">Select Page:</label>
            <select id="page-select" style="padding: 5px 10px; font-size: 14px;">
                <option value="">All Pages</option>
                <?php foreach ($pages as $page) : ?>
                    <option value="<?php echo esc_attr($page->path); ?>" <?php selected($selected_page, $page->path); ?>>
                        <?php echo esc_html($page->path); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <script type="text/javascript">
            document.getElementById('page-select').addEventListener('change', function() {
                const pagePath = this.value;
                const url = new URL(window.location);
                if (pagePath) {
                    url.searchParams.set('page_path', pagePath);
                } else {
                    url.searchParams.delete('page_path');
                }
                window.location.href = url.toString();
            });
        </script>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>First Contentful Paint (FCP) - Last 30 Days</h2>
            <canvas id="fcpChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>FCP Request Count - Last 30 Days</h2>
            <canvas id="fcpCountChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>Time to First Byte (TTFB) - Last 30 Days</h2>
            <canvas id="ttfbChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>TTFB Request Count - Last 30 Days</h2>
            <canvas id="ttfbCountChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>
    </div>
    <?php
}

function wp_web_vitals_calculate_median($values_string) {
    if (empty($values_string)) {
        return null;
    }
    
    $values = array_map('floatval', explode(',', $values_string));
    sort($values);
    
    $count = count($values);
    $mid = floor(($count - 1) / 2);
    
    if ($count % 2) {
        return round($values[$mid], 2);
    }
    
    return round(($values[$mid] + $values[$mid + 1]) / 2, 2);
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
                GROUP_CONCAT(CASE WHEN fcp >= 0 THEN fcp END ORDER BY CASE WHEN fcp >= 0 THEN fcp END SEPARATOR ',') as fcp_values,
                GROUP_CONCAT(CASE WHEN ttfb >= 0 THEN ttfb END ORDER BY CASE WHEN ttfb >= 0 THEN ttfb END SEPARATOR ',') as ttfb_values,
                COUNT(*) as request_count
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND path = %s
            GROUP BY DATE(created_at), user_type, has_query
            ORDER BY date DESC
        ", $page_path);
    } else {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                user_type,
                CASE WHEN url LIKE '%?%' THEN 'with_query' ELSE 'no_query' END as has_query,
                GROUP_CONCAT(CASE WHEN fcp >= 0 THEN fcp END ORDER BY CASE WHEN fcp >= 0 THEN fcp END SEPARATOR ',') as fcp_values,
                GROUP_CONCAT(CASE WHEN ttfb >= 0 THEN ttfb END ORDER BY CASE WHEN ttfb >= 0 THEN ttfb END SEPARATOR ',') as ttfb_values,
                COUNT(*) as request_count
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at), user_type, has_query
            ORDER BY date DESC
        ";
    }

    $results = $wpdb->get_results($sql);

    $dates_set = [];
    $data_by_date = [];

    foreach ($results as $row) {
        $dates_set[$row->date] = true;
        
        if (!isset($data_by_date[$row->date])) {
            $data_by_date[$row->date] = [
                'guest_no_query' => ['fcp' => null, 'ttfb' => null, 'count' => 0],
                'guest_with_query' => ['fcp' => null, 'ttfb' => null, 'count' => 0],
                'logged_in_no_query' => ['fcp' => null, 'ttfb' => null, 'count' => 0],
                'logged_in_with_query' => ['fcp' => null, 'ttfb' => null, 'count' => 0]
            ];
        }

        $key = $row->user_type . '_' . $row->has_query;
        
        $data_by_date[$row->date][$key]['fcp'] = wp_web_vitals_calculate_median($row->fcp_values);
        $data_by_date[$row->date][$key]['ttfb'] = wp_web_vitals_calculate_median($row->ttfb_values);
        $data_by_date[$row->date][$key]['count'] = intval($row->request_count);
    }

    $dates = array_keys($dates_set);
    rsort($dates);

    $fcp_guest_no_query = [];
    $fcp_guest_with_query = [];
    $fcp_logged_in_no_query = [];
    $fcp_logged_in_with_query = [];
    $ttfb_guest_no_query = [];
    $ttfb_guest_with_query = [];
    $ttfb_logged_in_no_query = [];
    $ttfb_logged_in_with_query = [];
    $fcp_count_guest_no_query = [];
    $fcp_count_guest_with_query = [];
    $fcp_count_logged_in_no_query = [];
    $fcp_count_logged_in_with_query = [];
    $ttfb_count_guest_no_query = [];
    $ttfb_count_guest_with_query = [];
    $ttfb_count_logged_in_no_query = [];
    $ttfb_count_logged_in_with_query = [];

    foreach ($dates as $date) {
        $fcp_guest_no_query[] = $data_by_date[$date]['guest_no_query']['fcp'];
        $fcp_guest_with_query[] = $data_by_date[$date]['guest_with_query']['fcp'];
        $fcp_logged_in_no_query[] = $data_by_date[$date]['logged_in_no_query']['fcp'];
        $fcp_logged_in_with_query[] = $data_by_date[$date]['logged_in_with_query']['fcp'];
        $ttfb_guest_no_query[] = $data_by_date[$date]['guest_no_query']['ttfb'];
        $ttfb_guest_with_query[] = $data_by_date[$date]['guest_with_query']['ttfb'];
        $ttfb_logged_in_no_query[] = $data_by_date[$date]['logged_in_no_query']['ttfb'];
        $ttfb_logged_in_with_query[] = $data_by_date[$date]['logged_in_with_query']['ttfb'];
        $fcp_count_guest_no_query[] = $data_by_date[$date]['guest_no_query']['count'];
        $fcp_count_guest_with_query[] = $data_by_date[$date]['guest_with_query']['count'];
        $fcp_count_logged_in_no_query[] = $data_by_date[$date]['logged_in_no_query']['count'];
        $fcp_count_logged_in_with_query[] = $data_by_date[$date]['logged_in_with_query']['count'];
        $ttfb_count_guest_no_query[] = $data_by_date[$date]['guest_no_query']['count'];
        $ttfb_count_guest_with_query[] = $data_by_date[$date]['guest_with_query']['count'];
        $ttfb_count_logged_in_no_query[] = $data_by_date[$date]['logged_in_no_query']['count'];
        $ttfb_count_logged_in_with_query[] = $data_by_date[$date]['logged_in_with_query']['count'];
    }

    return [
        'dates' => $dates,
        'fcp_guest_no_query' => $fcp_guest_no_query,
        'fcp_guest_with_query' => $fcp_guest_with_query,
        'fcp_logged_in_no_query' => $fcp_logged_in_no_query,
        'fcp_logged_in_with_query' => $fcp_logged_in_with_query,
        'ttfb_guest_no_query' => $ttfb_guest_no_query,
        'ttfb_guest_with_query' => $ttfb_guest_with_query,
        'ttfb_logged_in_no_query' => $ttfb_logged_in_no_query,
        'ttfb_logged_in_with_query' => $ttfb_logged_in_with_query,
        'fcp_count_guest_no_query' => $fcp_count_guest_no_query,
        'fcp_count_guest_with_query' => $fcp_count_guest_with_query,
        'fcp_count_logged_in_no_query' => $fcp_count_logged_in_no_query,
        'fcp_count_logged_in_with_query' => $fcp_count_logged_in_with_query,
        'ttfb_count_guest_no_query' => $ttfb_count_guest_no_query,
        'ttfb_count_guest_with_query' => $ttfb_count_guest_with_query,
        'ttfb_count_logged_in_no_query' => $ttfb_count_logged_in_no_query,
        'ttfb_count_logged_in_with_query' => $ttfb_count_logged_in_with_query
    ];
}