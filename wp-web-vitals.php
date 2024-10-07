<?php
/**
 * Plugin Name: WP Web Vitals
 * Description: Logs Time to First Byte (TTFB), URL, User Type, and User Agent information.
 * Version: 1.0
 * Author: Gabor Angyal
 * Author URI: https://woodevops.com
 */

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'wp_web_vitals_create_table');

function wp_web_vitals_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_web_vitals_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ttfb float NOT NULL,
        user_type varchar(255) DEFAULT '' NOT NULL,
        url text NOT NULL,
        user_agent text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('wp_enqueue_scripts', 'wp_web_vitals_enqueue_script');

function wp_web_vitals_enqueue_script() {
    wp_enqueue_script('wp-web-vitals', plugin_dir_url(__FILE__) . 'wp-web-vitals.js', [], '1.0', true);
    wp_localize_script('wp-web-vitals', 'wpWebVitals', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp-web-vitals-nonce')
    ]);
}

add_action('wp_ajax_nopriv_log_ttfb', 'wp_web_vitals_log_ttfb');
add_action('wp_ajax_log_ttfb', 'wp_web_vitals_log_ttfb');

function wp_web_vitals_log_ttfb() {
    check_ajax_referer('wp-web-vitals-nonce', 'nonce');

    $ttfb = isset($_POST['ttfb']) ? floatval($_POST['ttfb']) : null;
    $user_type = isset($_POST['userType']) ? sanitize_text_field($_POST['userType']) : '';
    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';

    if ($ttfb === null || empty($url)) {
        wp_send_json_error('Invalid data received.');
    }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_web_vitals_logs';
    $wpdb->insert($table_name, [
        'ttfb' => $ttfb,
        'url' => $url,
        'user_type' => $user_type,
        'user_agent' => $user_agent,
        'created_at' => current_time('mysql')
    ]);

    wp_send_json_success('Performance data logged successfully.');
}
