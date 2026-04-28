<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.2.0
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

function wp_web_vitals_page_renders_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'web_vitals_page_renders';
}

function wp_web_vitals_create_table() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();
    $page_renders_table_name = wp_web_vitals_page_renders_table_name();

    $charset_collate = $wpdb->get_charset_collate();

    $page_renders_sql = "CREATE TABLE $page_renders_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        uuid varchar(36) NOT NULL UNIQUE,
        path text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        INDEX idx_uuid (uuid)
    ) $charset_collate;";

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        page_render_id mediumint(9) DEFAULT NULL,
        ttfb float NOT NULL,
        fcp float NOT NULL,
        measurement_seconds float NOT NULL,
        user_type varchar(255) DEFAULT '' NOT NULL,
        url text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        INDEX idx_page_render_id (page_render_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    dbDelta($page_renders_sql);
    if ($wpdb->last_error) {
        error_log("Error creating page renders table: " . $wpdb->last_error);
    } else {
        error_log("Page renders table created successfully or already exists.");
    }
    
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
    $page_renders_table_name = wp_web_vitals_page_renders_table_name();

    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);

    if ($wpdb->last_error) {
        error_log("Error deleting web vitals logs table: " . $wpdb->last_error);
    } else {
        error_log("Web vitals logs table deleted successfully.");
    }

    $sql = "DROP TABLE IF EXISTS $page_renders_table_name;";
    $wpdb->query($sql);

    if ($wpdb->last_error) {
        error_log("Error deleting page renders table: " . $wpdb->last_error);
    } else {
        error_log("Page renders table deleted successfully.");
    }
}

register_deactivation_hook(__FILE__, 'wp_web_vitals_delete_table');

function wp_web_vitals_generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function wp_web_vitals_create_page_render() {
    global $wpdb;
    $uuid = wp_web_vitals_generate_uuid();
    $path = $_SERVER['REQUEST_URI'];
    
    $wpdb->insert(wp_web_vitals_page_renders_table_name(), [
        'uuid' => $uuid,
        'path' => $path,
        'created_at' => current_time('mysql')
    ]);
    
    if ($wpdb->last_error) {
        error_log("Error creating page render record: " . $wpdb->last_error);
        return null;
    }
    
    return $uuid;
}

add_action('wp_enqueue_scripts', 'wp_web_vitals_enqueue_script');
add_action('wp_head', 'wp_web_vitals_add_uuid_to_head');

$wp_web_vitals_page_render_uuid = null;

function wp_web_vitals_enqueue_script() {
    global $wp_web_vitals_page_render_uuid;
    
    $wp_web_vitals_page_render_uuid = wp_web_vitals_create_page_render();
    
    wp_enqueue_script('wp-web-vitals', plugin_dir_url(__FILE__) . 'wp-web-vitals.js', [], '1.0', true);
    wp_localize_script('wp-web-vitals', 'wpWebVitals', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp-web-vitals-nonce'),
        'pageRenderUuid' => $wp_web_vitals_page_render_uuid
    ]);
}

function wp_web_vitals_add_uuid_to_head() {
    global $wp_web_vitals_page_render_uuid;
    
    if ($wp_web_vitals_page_render_uuid) {
        echo '<meta name="wp-web-vitals-uuid" content="' . esc_attr($wp_web_vitals_page_render_uuid) . '">' . "\n";
    }
}

add_action('wp_ajax_nopriv_log_webvitals', 'wp_web_vitals_log_webvitals');
add_action('wp_ajax_log_webvitals', 'wp_web_vitals_log_webvitals');

function wp_web_vitals_log_webvitals() {
    check_ajax_referer('wp-web-vitals-nonce', 'nonce');

    $ttfb = isset($_POST['ttfb']) ? floatval($_POST['ttfb']) : null;
    $fcp = isset($_POST['fcp']) ? floatval($_POST['fcp']) : null;
    $measurement_seconds = isset($_POST['measurementSeconds']) ? floatval($_POST['measurementSeconds']) : null;
    $user_type = isset($_POST['userType']) ? sanitize_text_field($_POST['userType']) : '';
    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
    $page_render_uuid = isset($_POST['pageRenderUuid']) ? sanitize_text_field($_POST['pageRenderUuid']) : '';

    if ($ttfb === null || empty($url)) {
        wp_send_json_error('Invalid data received.');
    }

    global $wpdb;
    
    $page_render_id = null;
    if (!empty($page_render_uuid)) {
        $page_render_record = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . wp_web_vitals_page_renders_table_name() . " WHERE uuid = %s",
            $page_render_uuid
        ));
        
        if ($page_render_record) {
            $page_render_id = $page_render_record->id;
        }
    }

    $wpdb->insert(wp_web_vitals_table_name(), [
        'page_render_id' => $page_render_id,
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
    $renders_table = wp_web_vitals_page_renders_table_name();

    $results = $wpdb->get_results("
        SELECT DISTINCT pr.id, pr.path
        FROM $renders_table pr
        INNER JOIN $logs_table wvl ON pr.id = wvl.page_render_id
        ORDER BY pr.path ASC
    ");

    return $results;
}

function wp_web_vitals_admin_page() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    $selected_page_id = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

    $pages = wp_web_vitals_get_pages_with_data();

    $chart_data = wp_web_vitals_get_chart_data($selected_page_id);

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
                        label: 'Guest Users (ms)',
                        data: chartData.fcp_guest,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in Users (ms)',
                        data: chartData.fcp_logged_in,
                        backgroundColor: 'rgba(75, 192, 75, 0.7)',
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
                        label: 'Guest Users (ms)',
                        data: chartData.ttfb_guest,
                        backgroundColor: 'rgba(255, 159, 64, 0.7)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Logged-in Users (ms)',
                        data: chartData.ttfb_logged_in,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
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
    ";
    
    wp_add_inline_script('chartjs', $inline_script);
    
    $chart_margin = WP_WEB_VITALS_CHART_MARGIN_BOTTOM;
    $chart_container_style = "max-width: {$chart_max_width}px; margin-bottom: {$chart_margin}px; margin-left: auto; margin-right: auto;";
    $canvas_style = "max-width: 100%; height: {$chart_height}px;";
    
    ?>
    <div class="wrap">
        <h1>Web Vitals Analytics</h1>

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
                    <option value="<?php echo intval($page->id); ?>" <?php selected($selected_page_id, $page->id); ?>>
                        <?php echo esc_html($page->path); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <script type="text/javascript">
            document.getElementById('page-select').addEventListener('change', function() {
                const pageId = this.value;
                const url = new URL(window.location);
                if (pageId) {
                    url.searchParams.set('page_id', pageId);
                } else {
                    url.searchParams.delete('page_id');
                }
                window.location.href = url.toString();
            });
        </script>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>First Contentful Paint (FCP) - Last 30 Days</h2>
            <canvas id="fcpChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>

        <div style="<?php echo esc_attr($chart_container_style); ?>">
            <h2>Time to First Byte (TTFB) - Last 30 Days</h2>
            <canvas id="ttfbChart" style="<?php echo esc_attr($canvas_style); ?>"></canvas>
        </div>
    </div>
    <?php
}

function wp_web_vitals_get_chart_data($page_render_id = 0) {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    if ($page_render_id > 0) {
        $sql = $wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                user_type,
                AVG(fcp) as avg_fcp,
                AVG(ttfb) as avg_ttfb
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND page_render_id = %d
            GROUP BY DATE(created_at), user_type
            ORDER BY date DESC
        ", $page_render_id);
    } else {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                user_type,
                AVG(fcp) as avg_fcp,
                AVG(ttfb) as avg_ttfb
            FROM $table_name
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at), user_type
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
                'guest' => ['fcp' => null, 'ttfb' => null],
                'logged_in' => ['fcp' => null, 'ttfb' => null]
            ];
        }

        if ($row->user_type === 'guest') {
            $data_by_date[$row->date]['guest']['fcp'] = round(floatval($row->avg_fcp), 2);
            $data_by_date[$row->date]['guest']['ttfb'] = round(floatval($row->avg_ttfb), 2);
        } else {
            $data_by_date[$row->date]['logged_in']['fcp'] = round(floatval($row->avg_fcp), 2);
            $data_by_date[$row->date]['logged_in']['ttfb'] = round(floatval($row->avg_ttfb), 2);
        }
    }

    $dates = array_keys($dates_set);
    rsort($dates);

    $fcp_guest = [];
    $fcp_logged_in = [];
    $ttfb_guest = [];
    $ttfb_logged_in = [];

    foreach ($dates as $date) {
        $fcp_guest[] = $data_by_date[$date]['guest']['fcp'];
        $fcp_logged_in[] = $data_by_date[$date]['logged_in']['fcp'];
        $ttfb_guest[] = $data_by_date[$date]['guest']['ttfb'];
        $ttfb_logged_in[] = $data_by_date[$date]['logged_in']['ttfb'];
    }

    return [
        'dates' => $dates,
        'fcp_guest' => $fcp_guest,
        'fcp_logged_in' => $fcp_logged_in,
        'ttfb_guest' => $ttfb_guest,
        'ttfb_logged_in' => $ttfb_logged_in
    ];
}