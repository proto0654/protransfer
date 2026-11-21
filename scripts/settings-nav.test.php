<?php
define('ABSPATH', __DIR__);
define('PT_CORE_URL', 'https://example.test/wp-content/plugins/pt-core/');
define('PT_CORE_VERSION', '0.1.0');

$actions = [];
$styles = [];
$scripts = [];
$inline = [];
$screen = null;

function add_action($hook, $callback, $priority = 10)
{
    global $actions;
    $actions[$hook] = [$callback, $priority];
}

function get_current_screen()
{
    global $screen;
    return $screen;
}

function wp_enqueue_style($handle, $src, $deps = [], $version = false)
{
    global $styles;
    $styles[] = [$handle, $src, $deps, $version];
}

function wp_enqueue_script($handle, $src, $deps = [], $version = false, $footer = false)
{
    global $scripts;
    $scripts[] = [$handle, $src, $deps, $version, $footer];
}

function wp_add_inline_script($handle, $data, $position = 'after')
{
    global $inline;
    $inline[] = [$handle, $data, $position];
}

function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags);
}

function get_current_user_id()
{
    return 42;
}

function expect($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/admin-settings-nav.php';

expect(isset($actions['admin_enqueue_scripts']), 'The navigation must register an admin enqueue callback');
$callback = $actions['admin_enqueue_scripts'][0];

$callback('edit.php');
expect($styles === [] && $scripts === [] && $inline === [], 'Other admin screens must not receive the navigation assets');

$screen = (object) ['post_type' => 'pt_point'];
$callback('post.php');
expect($styles === [] && $scripts === [] && $inline === [], 'Other post types must not receive the navigation assets');

$screen = null;
$callback('post.php');
expect($styles === [] && $scripts === [] && $inline === [], 'A missing screen must not receive the navigation assets');

$screen = (object) ['post_type' => 'pt_settings'];
$callback('post-new.php');
expect(count($styles) === 1, 'The settings screen must receive one stylesheet');
expect($styles[0][0] === 'pt-admin-settings-nav', 'The stylesheet handle must be stable');
expect(str_ends_with($styles[0][1], 'includes/admin-settings-nav.css'), 'The stylesheet must point at the plugin asset');
expect(count($scripts) === 1, 'The settings screen must receive one script');
expect($scripts[0][0] === 'pt-admin-settings-nav', 'The script handle must be stable');
expect(str_ends_with($scripts[0][1], 'includes/admin-settings-nav.js'), 'The script must point at the plugin asset');
expect($scripts[0][4] === true, 'The script must load in the footer after the boxes');
expect(count($inline) === 1, 'The settings screen must receive the per-user configuration');
expect($inline[0][2] === 'before', 'The configuration must be available before the script runs');
expect(str_contains($inline[0][1], 'pt-settings-nav-42'), 'The storage key must be scoped to the current user');

echo "Settings navigation: 14 checks passed\n";
