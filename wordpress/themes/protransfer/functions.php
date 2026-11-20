<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
});

add_action('wp_enqueue_scripts', function () {
    $page = isset($_GET['pt_legal']) ? 'legal.html' : 'preview.html';
    if (!is_front_page() || !is_readable(__DIR__ . '/landing/' . $page)) {
        return;
    }

    wp_dequeue_style('generate-child');
    wp_dequeue_style('generate-style');
}, 100);
