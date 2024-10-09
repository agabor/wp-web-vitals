<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 0.0.1
 * Author: Gabor Angyal
 * Author URI: https://woodevops.com
 * License: GPL3
 */

if (!defined('ABSPATH')) {
    exit;
}

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
        ttfb float NOT NULL,
        fcp float NOT NULL,
        lcp float NOT NULL,
        inp float NOT NULL,
        cls float NOT NULL,
        measurement_seconds float NOT NULL,
        user_type varchar(255) DEFAULT '' NOT NULL,
        url text NOT NULL,
        user_agent text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    if ($wpdb->last_error) {
        error_log("Error creating table: " . $wpdb->last_error);
    } else {
        error_log("Table created successfully or already exists.");
    }
}

add_action('wp_enqueue_scripts', 'wp_web_vitals_enqueue_script');

function wp_web_vitals_enqueue_script() {
    wp_enqueue_script('wp-web-vitals', plugin_dir_url(__FILE__) . 'wp-web-vitals.js', [], '1.0', true);
    wp_localize_script('wp-web-vitals', 'wpWebVitals', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp-web-vitals-nonce')
    ]);
}

add_action('wp_ajax_nopriv_log_webvitals', 'wp_web_vitals_log_webvitals');
add_action('wp_ajax_log_webvitals', 'wp_web_vitals_log_webvitals');

function wp_web_vitals_log_webvitals() {
    check_ajax_referer('wp-web-vitals-nonce', 'nonce');

    $ttfb = isset($_POST['ttfb']) ? floatval($_POST['ttfb']) : null;
    $fcp = isset($_POST['fcp']) ? floatval($_POST['fcp']) : null;
    $lcp = isset($_POST['lcp']) ? floatval($_POST['lcp']) : null;
    $inp = isset($_POST['inp']) ? floatval($_POST['inp']) : null;
    $cls = isset($_POST['cls']) ? floatval($_POST['cls']) : null;
    $measurement_seconds = isset($_POST['measurementSeconds']) ? floatval($_POST['measurementSeconds']) : null;
    $user_type = isset($_POST['userType']) ? sanitize_text_field($_POST['userType']) : '';
    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';

    if ($ttfb === null || empty($url)) {
        wp_send_json_error('Invalid data received.');
    }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

    global $wpdb;
    $wpdb->insert(wp_web_vitals_table_name(), [
        'ttfb' => $ttfb,
        'fcp' => $fcp,
        'lcp' => $lcp,
        'inp' => $inp,
        'cls' => $cls,
        'measurement_seconds' => $measurement_seconds,
        'url' => $url,
        'user_type' => $user_type,
        'user_agent' => $user_agent,
        'created_at' => current_time('mysql')
    ]);

    wp_send_json_success('Performance data logged successfully.');
}

// Add a new menu item in the WordPress admin
add_action('admin_menu', 'wp_web_vitals_admin_menu');

function wp_web_vitals_admin_menu() {
    add_menu_page(
        'Web Vitals Averages', // Page title
        'Web Vitals',          // Menu title
        'manage_options',      // Capability
        'web-vitals-averages', // Menu slug
        'wp_web_vitals_admin_page', // Callback function
        'dashicons-chart-line', // Icon
        6                      // Position
    );
}

// Display the admin page content
function wp_web_vitals_admin_page() {
    global $wpdb;
    $table_name = wp_web_vitals_table_name();

    // Query to calculate the averages
    $results = $wpdb->get_row("
        SELECT 
            AVG(ttfb) as avg_ttfb,
            AVG(fcp) as avg_fcp,
            AVG(lcp) as avg_lcp,
            AVG(inp) as avg_inp,
            AVG(cls) as avg_cls
        FROM $table_name
    ");

    // Display the results
    echo '<div class="wrap">';
    echo '<h1>Web Vitals Averages</h1>';
    if ($results) {
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr><th>Metric</th><th>Average</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td>TTFB</td><td>' . number_format($results->avg_ttfb, 2) . '</td></tr>';
        echo '<tr><td>FCP</td><td>' . number_format($results->avg_fcp, 2) . '</td></tr>';
        echo '<tr><td>LCP</td><td>' . number_format($results->avg_lcp, 2) . '</td></tr>';
        echo '<tr><td>INP</td><td>' . number_format($results->avg_inp, 2) . '</td></tr>';
        echo '<tr><td>CLS</td><td>' . number_format($results->avg_cls, 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>No data available.</p>';
    }
    echo '</div>';
}