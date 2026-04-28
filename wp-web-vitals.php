<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.1.0
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
        lcp float NOT NULL,
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

    $lcp = isset($_POST['lcp']) ? floatval($_POST['lcp']) : null;
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
        'lcp' => $lcp,
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

function wp_web_vitals_admin_page() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();
    $page_renders_table_name = wp_web_vitals_page_renders_table_name();

    $selected_page_id = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

    $pages = $wpdb->get_results("
        SELECT DISTINCT pr.id, pr.path
        FROM $page_renders_table_name pr
        INNER JOIN $table_name wvl ON pr.id = wvl.page_render_id
        ORDER BY pr.path ASC
    ");

    if ($selected_page_id > 0) {
        $results = $wpdb->get_row($wpdb->prepare("
            SELECT 
                AVG(wvl.lcp) as avg_lcp,
                AVG(wvl.ttfb) as avg_ttfb,
                AVG(wvl.fcp) as avg_fcp
            FROM $table_name wvl
            WHERE wvl.page_render_id = %d
        ", $selected_page_id));
    } else {
        $results = null;
    }
?>
    <div class="wrap">
        <h1>Web Vitals Averages</h1>

        <form method="get">
            <input type="hidden" name="page" value="web-vitals-averages" />
            <label for="page_id">Select Page:</label>
            <select name="page_id" id="page_id">
                <option value="0">-- Select a Page --</option>
<?php
    if ($pages) {
        foreach ($pages as $page) {
            $selected = selected($page->id, $selected_page_id, false);
            echo '<option value="' . esc_attr($page->id) . '" ' . $selected . '>' . esc_html($page->path) . '</option>';
        }
    }
?>
            </select>
            <input type="submit" class="button" value="Filter" />
        </form>

<?php
    if ($wpdb->last_error) {
        echo "<p>" . esc_html($wpdb->last_error) . "</p>";
        return;
    }

    if ($selected_page_id > 0 && $results) {
?>
        <table class="widefat fixed" cellspacing="0">
            <thead><tr><th>Metric</th><th>Average</th></tr></thead>
            <tbody>
                <tr><td>LCP</td><td><?php echo esc_html(number_format($results->avg_lcp, 2)); ?></td></tr>
                <tr><td>TTFB</td><td><?php echo esc_html(number_format($results->avg_ttfb, 2)); ?></td></tr>
                <tr><td>FCP</td><td><?php echo esc_html(number_format($results->avg_fcp, 2)); ?></td></tr>
            </tbody>
        </table>
<?php
    } elseif ($selected_page_id > 0) {
?>
        <p>No data available for this page.</p>
<?php
    } else {
?>
        <p>Select a page to view averages.</p>
<?php
    }
?>
    </div>
<?php
}