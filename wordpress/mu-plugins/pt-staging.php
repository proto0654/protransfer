<?php
/**
 * Plugin Name: Protransfer staging safeguards
 * Description: Keep the review environment private and prevent outgoing mail.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (wp_get_environment_type() !== 'staging') {
    return;
}

add_filter('pre_wp_mail', '__return_false');
add_filter('wp_speculation_rules_configuration', '__return_null');

add_filter('wp_robots', function ($robots) {
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    return $robots;
});

add_action('send_headers', function () {
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    nocache_headers();
});

add_filter('robots_txt', fn () => "User-agent: *\nDisallow: /\n");
