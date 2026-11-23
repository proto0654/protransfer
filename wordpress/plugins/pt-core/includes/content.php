<?php
if (!defined('ABSPATH')) {
    exit;
}

function pt_register_types(): void
{
    $types = [
        'pt_point' => ['Точки', 'Точка', 'dashicons-location'],
        'pt_tariff' => ['Тарифы', 'Тариф', 'dashicons-car'],
        'pt_faq' => ['Вопросы и ответы', 'Вопрос', 'dashicons-editor-help'],
        'pt_review' => ['Отзывы', 'Отзыв', 'dashicons-star-filled'],
        'pt_order' => ['Заявки', 'Заявка', 'dashicons-email-alt'],
        'pt_settings' => ['Общие данные', 'Общие данные', 'dashicons-admin-generic'],
    ];

    foreach ($types as $type => [$plural, $singular, $icon]) {
        $args = [
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
                'add_new_item' => 'Добавить: ' . $singular,
                'edit_item' => 'Редактировать: ' . $singular,
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'has_archive' => false,
            'menu_icon' => $icon,
            'supports' => ['title', 'page-attributes'],
            'capability_type' => 'page',
            'map_meta_cap' => true,
        ];

        if ($type === 'pt_tariff') {
            $args['supports'] = ['title', 'thumbnail', 'page-attributes'];
        }

        if ($type === 'pt_settings') {
            $args['capabilities'] = ['create_posts' => 'do_not_allow'];
            $args['supports'] = ['title'];
        }

        if ($type === 'pt_order') {
            $args['supports'] = ['title'];
        }

        register_post_type($type, $args);
    }

    // The point type is legacy data: it stays registered so stored terms keep
    // resolving, but the editor no longer offers it and the site never sees it.
    register_taxonomy('pt_point_type', 'pt_point', [
        'labels' => [
            'name' => 'Типы точек',
            'singular_name' => 'Тип точки',
        ],
        'public' => false,
        'show_ui' => false,
        'hierarchical' => false,
        'show_admin_column' => false,
        'rewrite' => false,
    ]);
}
add_action('init', 'pt_register_types');

$pt_no_blocks = ['page', 'pt_point', 'pt_tariff', 'pt_faq', 'pt_review', 'pt_order', 'pt_settings'];

add_filter('use_block_editor_for_post_type', function ($use, $type) use ($pt_no_blocks) {
    return in_array($type, $pt_no_blocks, true) ? false : $use;
}, 10, 2);

add_action('init', function () use ($pt_no_blocks) {
    foreach ($pt_no_blocks as $type) {
        remove_post_type_support($type, 'editor');
    }
}, 20);

function pt_ensure_settings_post(): int
{
    $existing = get_posts([
        'post_type' => 'pt_settings',
        'post_status' => 'publish',
        'numberposts' => 1,
        'fields' => 'ids',
    ]);
    if ($existing) {
        update_option('pt_settings_id', (int) $existing[0]);
        return (int) $existing[0];
    }

    $id = wp_insert_post([
        'post_type' => 'pt_settings',
        'post_title' => 'Настройки сайта',
        'post_status' => 'publish',
        'post_name' => 'site-settings',
    ], true);

    if (is_wp_error($id)) {
        return 0;
    }

    update_option('pt_settings_id', (int) $id);
    return (int) $id;
}

add_action('init', function () {
    if (!get_option('pt_settings_id')) {
        pt_ensure_settings_post();
    }
}, 30);

function pt_settings_id(): int
{
    $id = (int) get_option('pt_settings_id');
    return $id > 0 ? $id : pt_ensure_settings_post();
}

function pt_point_by_slug(string $slug): ?WP_Post
{
    $posts = get_posts([
        'post_type' => 'pt_point',
        'name' => $slug,
        'post_status' => 'publish',
        'numberposts' => 1,
    ]);
    return $posts[0] ?? null;
}

function pt_tariff_by_slug(string $slug): ?WP_Post
{
    $posts = get_posts([
        'post_type' => 'pt_tariff',
        'name' => $slug,
        'post_status' => 'publish',
        'numberposts' => 1,
    ]);
    return $posts[0] ?? null;
}
