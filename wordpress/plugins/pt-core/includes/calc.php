<?php
if (!defined('ABSPATH')) {
    exit;
}

function pt_calc_defaults(): array
{
    return [
        'road_factor' => 1.35,
        'km_round' => 5,
        'speed_short' => 50,
        'speed_mid' => 68,
        'speed_long' => 82,
        'time_buffer' => 10,
        'time_round' => 5,
        'round_trip_factor' => 1.9,
        'extra_stop_price' => 500,
        'price_round' => 10,
        'child_seat_free' => true,
    ];
}

function pt_calc_settings(): array
{
    $defaults = pt_calc_defaults();
    if (!function_exists('carbon_get_theme_option')) {
        return $defaults;
    }

    $get = static function (string $key, $fallback) {
        $value = carbon_get_theme_option($key);
        return ($value === '' || $value === null) ? $fallback : $value;
    };

    return [
        'road_factor' => (float) $get('pt_road_factor', $defaults['road_factor']),
        'km_round' => (int) $get('pt_km_round', $defaults['km_round']),
        'speed_short' => (float) $get('pt_speed_short', $defaults['speed_short']),
        'speed_mid' => (float) $get('pt_speed_mid', $defaults['speed_mid']),
        'speed_long' => (float) $get('pt_speed_long', $defaults['speed_long']),
        'time_buffer' => (int) $get('pt_time_buffer', $defaults['time_buffer']),
        'time_round' => (int) $get('pt_time_round', $defaults['time_round']),
        'round_trip_factor' => (float) $get('pt_round_trip_factor', $defaults['round_trip_factor']),
        'extra_stop_price' => (int) $get('pt_extra_stop_price', $defaults['extra_stop_price']),
        'price_round' => (int) $get('pt_price_round', $defaults['price_round']),
        'child_seat_free' => (bool) $get('pt_child_seat_free', $defaults['child_seat_free']),
    ];
}

function pt_route_overrides_map(): array
{
    $map = [];
    if (!function_exists('carbon_get_theme_option')) {
        return $map;
    }

    $rows = carbon_get_theme_option('pt_route_overrides') ?: [];
    foreach ($rows as $row) {
        $from = (string) ($row['from_slug'] ?? '');
        $to = (string) ($row['to_slug'] ?? '');
        $km = (float) ($row['km'] ?? 0);
        if ($from === '' || $to === '' || $km <= 0) {
            continue;
        }
        $map["{$from}|{$to}"] = $km;
        $map["{$to}|{$from}"] = $km;
    }

    return $map;
}

function pt_haversine_km(array $a, array $b): float
{
    $R = 6371.0;
    $toRad = static fn ($deg) => $deg * M_PI / 180;
    $dLat = $toRad($b['lat'] - $a['lat']);
    $dLon = $toRad($b['lon'] - $a['lon']);
    $lat1 = $toRad($a['lat']);
    $lat2 = $toRad($b['lat']);
    $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    return 2 * $R * asin(sqrt($h));
}

function pt_round_step(float $value, int $step): float
{
    if ($step <= 0) {
        return $value;
    }
    return round($value / $step) * $step;
}

function pt_estimate_minutes(float $km, array $settings): int
{
    if ($km <= 0) {
        return 0;
    }
    $speed = $km > 150 ? $settings['speed_long'] : ($km > 50 ? $settings['speed_mid'] : $settings['speed_short']);
    $minutes = ($km / $speed) * 60 + $settings['time_buffer'];
    return (int) pt_round_step($minutes, (int) $settings['time_round']);
}

/**
 * Server-side price breakdown. Yandex/OSRM are not called from admin debug by default
 * (no server API key for Maps JS); order: override → haversine×factor.
 *
 * @return array<string,mixed>
 */
function pt_calculate_quote(array $from, array $to, array $tariff, array $options = []): array
{
    $settings = pt_calc_settings();
    $overrides = pt_route_overrides_map();
    $round_trip = !empty($options['round_trip']);
    $extra_stop = !empty($options['extra_stop']);

    $from_id = (string) ($from['id'] ?? '');
    $to_id = (string) ($to['id'] ?? '');

    $source = 'haversine';
    $raw_km = null;
    $km = null;

    $exact = $overrides["{$from_id}|{$to_id}"] ?? null;
    if ($exact !== null) {
        $source = 'override';
        $raw_km = (float) $exact;
        $km = (float) $exact;
    } else {
        $raw = pt_haversine_km($from, $to) * $settings['road_factor'];
        $raw_km = $raw;
        $km = max((float) $settings['km_round'], pt_round_step($raw, (int) $settings['km_round']));
        $source = 'haversine';
    }

    $minutes = pt_estimate_minutes((float) $km, $settings);

    $on_request = !empty($tariff['on_request']);
    $base = (float) ($tariff['base'] ?? 0);
    $per_km = (float) ($tariff['per_km'] ?? 0);
    $min_price = (float) ($tariff['min_price'] ?? 0);

    $formula_price = $base + $per_km * (float) $km;
    $before_min = $formula_price;
    $price = max($min_price, $formula_price);
    $used_min = $price === $min_price && $min_price > $formula_price;

    $after_base = $price;
    if ($round_trip) {
        $price *= $settings['round_trip_factor'];
    }
    if ($extra_stop) {
        $price += $settings['extra_stop_price'];
    }
    $total = (int) pt_round_step($price, (int) $settings['price_round']);

    return [
        'from' => $from_id,
        'to' => $to_id,
        'tariff' => $tariff['id'] ?? '',
        'km_source' => $source,
        'raw_km' => $raw_km !== null ? round($raw_km, 2) : null,
        'km' => $km,
        'minutes' => $minutes,
        'on_request' => $on_request,
        'base' => $base,
        'per_km' => $per_km,
        'min_price' => $min_price,
        'formula_price' => round($before_min, 2),
        'used_min_price' => $used_min,
        'after_base_or_min' => round($after_base, 2),
        'round_trip' => $round_trip,
        'round_trip_factor' => $settings['round_trip_factor'],
        'extra_stop' => $extra_stop,
        'extra_stop_price' => $settings['extra_stop_price'],
        'total' => $on_request ? null : $total,
        'total_label' => $on_request ? 'По запросу' : (number_format($total, 0, '', ' ') . ' ₽'),
        'settings' => $settings,
    ];
}

function pt_point_payload(WP_Post $post): array
{
    $type_terms = wp_get_post_terms($post->ID, 'pt_point_type', ['fields' => 'slugs']);
    $aliases = [];
    if (function_exists('carbon_get_post_meta')) {
        $rows = carbon_get_post_meta($post->ID, 'pt_aliases') ?: [];
        foreach ($rows as $row) {
            if (!empty($row['alias'])) {
                $aliases[] = (string) $row['alias'];
            }
        }
    }

    return [
        'id' => $post->post_name,
        'name' => $post->post_title,
        'city' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_city') : '',
        'type' => $type_terms[0] ?? 'city',
        'lat' => function_exists('carbon_get_post_meta') ? (float) carbon_get_post_meta($post->ID, 'pt_lat') : 0.0,
        'lon' => function_exists('carbon_get_post_meta') ? (float) carbon_get_post_meta($post->ID, 'pt_lon') : 0.0,
        'alias' => $aliases,
    ];
}

function pt_tariff_photo_url(WP_Post $post): string
{
    $photo_id = (int) get_post_thumbnail_id($post);
    if (!$photo_id && function_exists('carbon_get_post_meta')) {
        $photo_id = (int) carbon_get_post_meta($post->ID, 'pt_photo');
    }
    if (!$photo_id) {
        return '';
    }
    $src = wp_get_attachment_image_url($photo_id, 'large');
    return $src ?: (string) wp_get_attachment_url($photo_id);
}

function pt_tariff_payload(WP_Post $post): array
{
    return [
        'id' => $post->post_name,
        'name' => $post->post_title,
        'photo' => pt_tariff_photo_url($post),
        'seats' => function_exists('carbon_get_post_meta') ? (int) carbon_get_post_meta($post->ID, 'pt_seats') : 0,
        'bags' => function_exists('carbon_get_post_meta') ? (int) carbon_get_post_meta($post->ID, 'pt_bags') : 0,
        'cars' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_cars') : '',
        'description' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_description') : '',
        'base' => function_exists('carbon_get_post_meta') ? (float) carbon_get_post_meta($post->ID, 'pt_base') : 0,
        'per_km' => function_exists('carbon_get_post_meta') ? (float) carbon_get_post_meta($post->ID, 'pt_per_km') : 0,
        'min_price' => function_exists('carbon_get_post_meta') ? (float) carbon_get_post_meta($post->ID, 'pt_min_price') : 0,
        'tag' => function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_tag') : '',
        'on_request' => function_exists('carbon_get_post_meta') ? (bool) carbon_get_post_meta($post->ID, 'pt_on_request') : false,
    ];
}
