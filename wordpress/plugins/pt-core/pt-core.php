<?php
/**
 * Plugin Name: Protransfer Core
 * Description: Точки, тарифы, маршруты, калькулятор, seed и REST для Про Трансфер.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Text Domain: pt-core
 */
if (!defined('ABSPATH')) {
    exit;
}

define('PT_CORE_PATH', plugin_dir_path(__FILE__));
define('PT_CORE_URL', plugin_dir_url(__FILE__));
define('PT_CORE_VERSION', '0.1.0');

add_action('after_setup_theme', function () {
    if (!class_exists('Carbon_Fields\\Carbon_Fields') && file_exists(PT_CORE_PATH . 'vendor/autoload.php')) {
        require_once PT_CORE_PATH . 'vendor/autoload.php';
        \Carbon_Fields\Carbon_Fields::boot();
    }
}, 5);

require_once PT_CORE_PATH . 'includes/content.php';
require_once PT_CORE_PATH . 'includes/calc.php';
require_once PT_CORE_PATH . 'includes/fields.php';
require_once PT_CORE_PATH . 'includes/rest.php';
require_once PT_CORE_PATH . 'includes/admin-debug.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once PT_CORE_PATH . 'includes/cli.php';
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!function_exists('carbon_get_theme_option')) {
        echo '<div class="notice notice-error"><p>Protransfer Core: выполните <code>composer install</code> в <code>wp-content/plugins/pt-core</code>.</p></div>';
    }
});

register_activation_hook(__FILE__, function () {
    pt_register_types();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
