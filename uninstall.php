<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'web_vitals_logs';
$page_renders_table_name = $wpdb->prefix . 'web_vitals_page_renders';

$wpdb->query("DROP TABLE IF EXISTS $table_name");
$wpdb->query("DROP TABLE IF EXISTS $page_renders_table_name");

delete_option('wp_web_vitals_version');