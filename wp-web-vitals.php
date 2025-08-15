<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.0.1
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

    // Create page renders table first (parent table)
    $page_renders_sql = "CREATE TABLE $page_renders_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        uuid varchar(36) NOT NULL UNIQUE,
        path text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        INDEX idx_uuid (uuid)
    ) $charset_collate;";

    // Create web vitals logs table with foreign key reference
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        page_render_id mediumint(9) DEFAULT NULL,
        lcp float NOT NULL,
        fid float NOT NULL,
        cls float NOT NULL,
        inp float NOT NULL,
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
    
    // Create page renders table
    dbDelta($page_renders_sql);
    if ($wpdb->last_error) {
        error_log("Error creating page renders table: " . $wpdb->last_error);
    } else {
        error_log("Page renders table created successfully or already exists.");
    }
    
    // Create web vitals logs table
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

    // Delete child table first (web_vitals_logs)
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);

    if ($wpdb->last_error) {
        error_log("Error deleting web vitals logs table: " . $wpdb->last_error);
    } else {
        error_log("Web vitals logs table deleted successfully.");
    }

    // Delete parent table (page_renders)
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

// Global variable to store the UUID for this page render
$wp_web_vitals_page_render_uuid = null;

function wp_web_vitals_enqueue_script() {
    global $wp_web_vitals_page_render_uuid;
    
    // Create page render record and get UUID
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
    $cls = isset($_POST['cls']) ? floatval($_POST['cls']) : null;
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
    
    // Get the page render ID from the UUID
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

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

    $wpdb->insert(wp_web_vitals_table_name(), [
        'page_render_id' => $page_render_id,
        'lcp' => $lcp,
        'cls' => $cls,
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
    $svg_file_path = plugin_dir_path(__FILE__) . 'performance.svg';
    $svg_content = file_get_contents($svg_file_path);
    $svg_base64 = 'data:image/svg+xml;base64,' . base64_encode($svg_content);

    add_menu_page(
        'Web Vitals Averages',
        'Web Vitals',
        'manage_options',
        'web-vitals-averages',
        'wp_web_vitals_admin_page',
        $svg_base64,
        6
    );
}

function wp_web_vitals_admin_page() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    $results = $wpdb->get_row("
        SELECT 
            AVG(lcp) as avg_lcp,
            AVG(cls) as avg_cls,
            AVG(ttfb) as avg_ttfb,
            AVG(fcp) as avg_fcp
        FROM $table_name
    ");
?>
    <div class="wrap">
    <h1>Web Vitals Averages</h1>

<?php
    if ($wpdb->last_error) {
        echo "<p>" . $wpdb->last_error . "</p>";
        return;
    } 
?>
<?php
    if ($results) {
?>
        <table class="widefat fixed" cellspacing="0">
        <thead><tr><th>Metric</th><th>Average</th></tr></thead>
        <tbody>
        <tr><td>LCP</td><td><?php echo number_format($results->avg_lcp, 2); ?></td></tr>
        <tr><td>CLS</td><td><?php echo number_format($results->avg_cls, 2); ?></td></tr>
        <tr><td>TTFB</td><td><?php echo number_format($results->avg_ttfb, 2); ?></td></tr>
        <tr><td>FCP</td><td><?php echo number_format($results->avg_fcp, 2); ?></td></tr>
        </tbody>
        </table>
<?php
    } else {
?>
        <p>No data available.</p>
<?php
    }
?>
    <div>Icons made from <a href="https://www.onlinewebfonts.com/icon">svg icons</a>is licensed by CC BY 4.0</div>
    </div>
<?php
}
