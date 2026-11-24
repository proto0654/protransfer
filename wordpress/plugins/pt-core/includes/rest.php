<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('pt/v1', '/bootstrap', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'pt_rest_bootstrap',
    ]);

    register_rest_route('pt/v1', '/quote', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'pt_rest_quote',
        'args' => [
            'from' => ['required' => true, 'type' => 'string'],
            'to' => ['required' => true, 'type' => 'string'],
            'tariff' => ['required' => true, 'type' => 'string'],
            'round_trip' => ['required' => false, 'type' => 'boolean', 'default' => false],
            'extra_stop' => ['required' => false, 'type' => 'boolean', 'default' => false],
            'child_seat' => ['required' => false, 'type' => 'boolean', 'default' => false],
        ],
    ]);

    register_rest_route('pt/v1', '/order', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'pt_rest_order',
    ]);
});

function pt_rest_bootstrap(): WP_REST_Response
{
    $points = array_map('pt_point_payload', get_posts([
        'post_type' => 'pt_point',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
    ]));

    $tariffs = array_map('pt_tariff_payload', get_posts([
        'post_type' => 'pt_tariff',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
    ]));

    $faqs = array_map(static function (WP_Post $post) {
        return [
            'q' => $post->post_title,
            'a' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_answer') : '',
        ];
    }, get_posts([
        'post_type' => 'pt_faq',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
    ]));

    $reviews = array_map(static function (WP_Post $post) {
        return [
            'name' => $post->post_title,
            'city' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_city') : '',
            'route' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_route') : '',
            'rating' => function_exists('carbon_get_post_meta') ? (int) carbon_get_post_meta($post->ID, 'pt_rating') : 5,
            'date' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_date_label') : '',
            'text' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_text') : '',
        ];
    }, get_posts([
        'post_type' => 'pt_review',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'menu_order date',
        'order' => 'ASC',
    ]));

    $popular = [];
    $overrides = [];
    if (function_exists('carbon_get_theme_option')) {
        foreach (carbon_get_theme_option('pt_popular_routes') ?: [] as $row) {
            if (!empty($row['from_slug']) && !empty($row['to_slug'])) {
                $popular[] = ['from' => $row['from_slug'], 'to' => $row['to_slug']];
            }
        }
        foreach (carbon_get_theme_option('pt_route_overrides') ?: [] as $row) {
            if (!empty($row['from_slug']) && !empty($row['to_slug']) && !empty($row['km'])) {
                $overrides[] = [
                    'from' => $row['from_slug'],
                    'to' => $row['to_slug'],
                    'km' => (float) $row['km'],
                ];
            }
        }
    }

    $settings_id = pt_settings_id();
    $site = [
        'brand' => '',
        'tagline' => '',
        'phone' => '',
        'phoneHref' => '',
        'telegram' => '',
        'max' => '',
        'email' => '',
        'address' => '',
        'hours' => '',
        'map' => ['apiKey' => ''],
        'rating' => 0,
        'reviewsCount' => 0,
        'stats' => [],
        'advantages' => [],
        'steps' => [],
        'services' => [],
        'payments' => [],
    ];

    if ($settings_id && function_exists('carbon_get_post_meta')) {
        $site['brand'] = (string) carbon_get_post_meta($settings_id, 'pt_brand');
        $site['tagline'] = (string) carbon_get_post_meta($settings_id, 'pt_tagline');
        $site['phone'] = (string) carbon_get_post_meta($settings_id, 'pt_phone');
        $site['phoneHref'] = (string) carbon_get_post_meta($settings_id, 'pt_phone_href');
        $site['telegram'] = (string) carbon_get_post_meta($settings_id, 'pt_telegram');
        $site['max'] = (string) carbon_get_post_meta($settings_id, 'pt_max');
        $site['email'] = (string) carbon_get_post_meta($settings_id, 'pt_email');
        $site['address'] = (string) carbon_get_post_meta($settings_id, 'pt_address');
        $site['hours'] = (string) carbon_get_post_meta($settings_id, 'pt_hours');
        $site['map']['apiKey'] = (string) carbon_get_post_meta($settings_id, 'pt_map_api_key');
        $site['rating'] = (float) carbon_get_post_meta($settings_id, 'pt_rating');
        $site['reviewsCount'] = (int) carbon_get_post_meta($settings_id, 'pt_reviews_count');
        $site['stats'] = array_map(static fn ($r) => [
            'value' => $r['stat_value'] ?? ($r['value'] ?? ''),
            'label' => $r['label'] ?? '',
        ], carbon_get_post_meta($settings_id, 'pt_stats') ?: []);
        $site['advantages'] = array_map(static fn ($r) => [
            'icon' => $r['icon'] ?? '',
            'title' => $r['title'] ?? '',
            'text' => $r['text'] ?? '',
        ], carbon_get_post_meta($settings_id, 'pt_advantages') ?: []);
        $site['steps'] = array_map(static fn ($r) => [
            'title' => $r['title'] ?? '',
            'text' => $r['text'] ?? '',
        ], carbon_get_post_meta($settings_id, 'pt_steps') ?: []);
        $site['services'] = array_map(static fn ($r) => [
            'icon' => $r['icon'] ?? '',
            'title' => $r['title'] ?? '',
            'text' => $r['text'] ?? '',
        ], carbon_get_post_meta($settings_id, 'pt_services') ?: []);
        $site['payments'] = array_values(array_filter(array_map(
            static fn ($r) => (string) ($r['label'] ?? ''),
            carbon_get_post_meta($settings_id, 'pt_payments') ?: []
        )));
    }

    return new WP_REST_Response([
        'site' => array_merge($site, [
            'copy' => pt_landing_copy($settings_id),
            'seo' => pt_landing_seo($settings_id),
            'heroImage' => function_exists('carbon_get_post_meta') ? (wp_get_attachment_image_url((int) carbon_get_post_meta($settings_id, 'pt_hero_image'), 'full') ?: '') : '',
        ]),
        'points' => $points,
        'tariffs' => $tariffs,
        'routes' => [
            'tariff' => function_exists('carbon_get_theme_option') ? (string) carbon_get_theme_option('pt_popular_tariff') : '',
            'popular' => $popular,
            'overrides' => $overrides,
        ],
        'faq' => $faqs,
        'reviews' => $reviews,
        'calc' => pt_calc_settings(),
    ], 200);
}

function pt_rest_quote(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $from_post = pt_point_by_slug((string) $request['from']);
    $to_post = pt_point_by_slug((string) $request['to']);
    $tariff_post = pt_tariff_by_slug((string) $request['tariff']);

    if (!$from_post || !$to_post || !$tariff_post) {
        return new WP_Error('pt_not_found', 'Точка или тариф не найдены', ['status' => 404]);
    }

    $quote = pt_calculate_quote(
        pt_point_payload($from_post),
        pt_point_payload($to_post),
        pt_tariff_payload($tariff_post),
        [
            'round_trip' => (bool) $request['round_trip'],
            'extra_stop' => (bool) $request['extra_stop'],
            'child_seat' => (bool) $request['child_seat'],
        ]
    );

    return new WP_REST_Response($quote, 200);
}

function pt_rest_order(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $input = $request->get_json_params();
    if (!is_array($input)) {
        $input = $request->get_params();
    }
    if (!is_array($input)) {
        return new WP_Error('pt_order_invalid', 'Не удалось прочитать данные заявки', ['status' => 400]);
    }

    $order = pt_create_order($input);
    if (is_wp_error($order)) {
        return $order;
    }

    return new WP_REST_Response([
        'id' => $order['id'],
        'number' => $order['number'],
        'total_label' => $order['total_label'],
    ], 201);
}
