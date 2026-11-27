<?php
if (!defined('ABSPATH')) {
    exit;
}

function pt_calc_defaults(): array
{
    $data = json_decode((string) file_get_contents(PT_CORE_PATH . 'data/calc.json'), true);
    return is_array($data) ? $data : [];
}

function pt_calc_settings(array $overrides = []): array
{
    $settings = pt_calc_defaults();
    if (!function_exists('carbon_get_theme_option')) {
        return $settings;
    }
    $positive = ['road_factor', 'km_round', 'speed_short', 'speed_mid', 'speed_long', 'time_round', 'price_round'];
    $integer_steps = ['km_round', 'time_round', 'price_round'];
    foreach ($settings as $key => $fallback) {
        $value = array_key_exists($key, $overrides) ? $overrides[$key] : carbon_get_theme_option('pt_' . $key);
        if ($value === '' || $value === null || !is_numeric($value)) {
            continue;
        }
        $number = (float) $value;
        if (is_finite($number) && (in_array($key, $positive, true) ? $number > 0 : $number >= 0) && (!in_array($key, $integer_steps, true) || floor($number) === $number)) {
            $settings[$key] = $number;
        }
    }
    return $settings;
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

/**
 * Field key that holds a route's own price for one tariff. Tariff slugs are
 * normalised because a Carbon child field name must not carry hyphens.
 */
function pt_route_price_field(string $tariff_slug): string
{
    return 'price_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($tariff_slug)));
}

/**
 * Per-route prices read from «Маршруты»: «from|to|price_field» => ₽/км, both
 * directions. A row may set a price without manual kilometres, so this is read
 * separately from pt_route_overrides_map().
 *
 * @return array<string,float>
 */
function pt_route_price_map(): array
{
    $map = [];
    if (!function_exists('carbon_get_theme_option')) {
        return $map;
    }

    $rows = carbon_get_theme_option('pt_route_overrides') ?: [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $from = (string) ($row['from_slug'] ?? '');
        $to = (string) ($row['to_slug'] ?? '');
        if ($from === '' || $to === '' || $from === $to) {
            continue;
        }
        foreach ($row as $key => $value) {
            $key = (string) $key;
            if (strpos($key, 'price_') !== 0 || !is_numeric($value)) {
                continue;
            }
            $price = (float) $value;
            if (!is_finite($price) || $price <= 0) {
                continue;
            }
            $map["{$from}|{$to}|{$key}"] = $price;
            $map["{$to}|{$from}|{$key}"] = $price;
        }
    }

    return $map;
}

/**
 * Route rows for the public bootstrap: manual kilometres plus own prices keyed
 * by tariff slug. A row is kept when it carries either value, because a fixed
 * route price does not need a manual distance.
 *
 * @param array<int,string> $tariff_ids Published tariff slugs.
 * @return array<int,array<string,mixed>>
 */
function pt_route_override_payload(array $tariff_ids = []): array
{
    if (!function_exists('carbon_get_theme_option')) {
        return [];
    }

    $price_ids = [];
    foreach ($tariff_ids as $tariff_id) {
        $tariff_id = (string) $tariff_id;
        if ($tariff_id !== '') {
            $price_ids[pt_route_price_field($tariff_id)] = $tariff_id;
        }
    }

    $rows = [];
    foreach (carbon_get_theme_option('pt_route_overrides') ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $from = (string) ($row['from_slug'] ?? '');
        $to = (string) ($row['to_slug'] ?? '');
        if ($from === '' || $to === '') {
            continue;
        }
        $entry = ['from' => $from, 'to' => $to];
        $km = (float) ($row['km'] ?? 0);
        if ($km > 0) {
            $entry['km'] = $km;
        }
        $prices = [];
        foreach ($row as $key => $value) {
            $key = (string) $key;
            if (!isset($price_ids[$key]) || !is_numeric($value)) {
                continue;
            }
            $price = (float) $value;
            if (!is_finite($price) || $price <= 0) {
                continue;
            }
            $prices[$price_ids[$key]] = $price;
        }
        if ($prices) {
            $entry['prices'] = $prices;
        }
        if (isset($entry['km']) || $prices) {
            $rows[] = $entry;
        }
    }

    return $rows;
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
    $speed = $km > $settings['speed_long_threshold'] ? $settings['speed_long'] : ($km > $settings['speed_mid_threshold'] ? $settings['speed_mid'] : $settings['speed_short']);
    $minutes = ($km / $speed) * 60 + $settings['time_buffer'];
    return (int) pt_round_step($minutes, (int) $settings['time_round']);
}

function pt_crossing_defaults(): array
{
    static $defaults;
    if (is_array($defaults)) {
        return $defaults;
    }
    $data = json_decode((string) file_get_contents(PT_CORE_PATH . 'data/routes.json'), true);
    $crossing = is_array($data) && isset($data['crossing']) && is_array($data['crossing']) ? $data['crossing'] : [];
    $defaults = array_merge([
        'enabled' => true,
        'railFrom' => 'kerch-yuzhnaya',
        'railTo' => 'taman',
        'via' => 'dzhankoy',
        'corridor' => [],
        'railMinutes' => 45,
        'transferMinutes' => 30,
    ], $crossing);
    return $defaults;
}

function pt_crossing_settings(): array
{
    $settings = pt_crossing_defaults();
    if (!function_exists('carbon_get_theme_option')) {
        return $settings;
    }

    $enabled = (string) carbon_get_theme_option('pt_crossing_enabled');
    if ($enabled === '0') {
        $settings['enabled'] = false;
    } elseif ($enabled === '1') {
        $settings['enabled'] = true;
    }
    foreach (['rail_from' => 'railFrom', 'rail_to' => 'railTo'] as $option => $key) {
        $value = carbon_get_theme_option('pt_crossing_' . $option);
        if (is_string($value) && trim($value) !== '') {
            $settings[$key] = trim($value);
        }
    }
    foreach (['rail_minutes' => 'railMinutes', 'transfer_minutes' => 'transferMinutes'] as $option => $key) {
        $value = carbon_get_theme_option('pt_crossing_' . $option);
        if (is_numeric($value) && (int) $value >= 0) {
            $settings[$key] = (int) $value;
        }
    }

    return $settings;
}

/** Crimea is everything west of the Kerch strait; the mainland starts east of it. */
function pt_point_is_mainland(array $point): bool
{
    return (float) ($point['lon'] ?? 0) > 36.6;
}

function pt_is_cross_region(array $from, array $to): bool
{
    if ((string) ($from['id'] ?? '') === (string) ($to['id'] ?? '')) {
        return false;
    }
    return pt_point_is_mainland($from) !== pt_point_is_mainland($to);
}

/** Resolve a crossing point from the request payload or from the published record. */
function pt_crossing_point(string $id, array $provided = []): ?array
{
    foreach ($provided as $point) {
        if (is_array($point) && (string) ($point['id'] ?? '') === $id) {
            return $point;
        }
    }
    if (!function_exists('pt_point_by_slug')) {
        return null;
    }
    $post = pt_point_by_slug($id);
    if (!$post instanceof WP_Post) {
        return null;
    }
    return pt_point_payload($post);
}

/**
 * Stations and the bypass waypoint for a cross-region pair. The direction is
 * normalised: «rail_from» is always the station on the passenger's starting side.
 *
 * @return array<string,mixed>|null
 */
function pt_crossing_legs(array $from, array $to, array $settings): ?array
{
    if (empty($settings['enabled']) || !pt_is_cross_region($from, $to)) {
        return null;
    }
    $from_mainland = pt_point_is_mainland($from);
    $rail_from_id = (string) ($from_mainland ? ($settings['railTo'] ?? '') : ($settings['railFrom'] ?? ''));
    $rail_to_id = (string) ($from_mainland ? ($settings['railFrom'] ?? '') : ($settings['railTo'] ?? ''));
    $via_id = (string) ($settings['via'] ?? '');
    if ($rail_from_id === '' || $rail_to_id === '' || $rail_from_id === $rail_to_id) {
        return null;
    }

    $rail_from = pt_crossing_point($rail_from_id, [$from, $to]);
    $rail_to = pt_crossing_point($rail_to_id, [$from, $to]);
    $via = $via_id !== '' ? pt_crossing_point($via_id, [$from, $to]) : null;
    if (!$rail_from || !$rail_to) {
        return null;
    }

    // The land corridor around the Azov Sea: a single waypoint is not enough,
    // because a router otherwise returns from the isthmus to the bridge.
    $corridor = [];
    foreach ((array) ($settings['corridor'] ?? []) as $index => $point) {
        if (!is_array($point) || !isset($point['lat'], $point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])) {
            continue;
        }
        $corridor[] = [
            'id' => (string) ($point['id'] ?? ('corridor-' . $index)),
            'name' => (string) ($point['name'] ?? ''),
            'lat' => (float) $point['lat'],
            'lon' => (float) $point['lon'],
        ];
    }
    if (!$corridor && $via) {
        $corridor = [$via];
    }
    if (!$corridor) {
        return null;
    }

    $ordered = $from_mainland ? array_reverse($corridor) : $corridor;
    $bypass_legs = [];
    $previous = $from;
    foreach ($ordered as $point) {
        $bypass_legs[] = [$previous, $point];
        $previous = $point;
    }
    $bypass_legs[] = [$previous, $to];

    return [
        'rail_from' => $rail_from,
        'rail_to' => $rail_to,
        'via' => $via ?: $corridor[0],
        // Two cars around the train, and a chain of cars around the peninsula.
        'train_legs' => [[$from, $rail_from], [$rail_to, $to]],
        'bypass_legs' => $bypass_legs,
    ];
}

/**
 * One car trip: distance, time and the base price before trip options.
 *
 * @return array<string,mixed>
 */
function pt_quote_leg(array $from, array $to, array $tariff, array $settings, ?float $distance_km = null): array
{
    $overrides = pt_route_overrides_map();
    $from_id = (string) ($from['id'] ?? '');
    $to_id = (string) ($to['id'] ?? '');

    if ($from_id !== '' && $from_id === $to_id) {
        return [
            'from' => $from_id,
            'to' => $to_id,
            'km_source' => 'zero',
            'raw_km' => 0,
            'km' => 0,
            'minutes' => 0,
            'base' => 0,
            'per_km' => 0,
            'tariff_per_km' => (float) ($tariff['per_km'] ?? 0),
            'per_km_source' => 'tariff',
            'min_price' => 0,
            'formula_price' => 0,
            'used_min_price' => false,
            'base_price' => 0,
        ];
    }

    $source = 'haversine';
    $raw_km = null;
    $km = null;

    if ($distance_km !== null && $distance_km > 0) {
        $source = 'manual';
        $raw_km = $km = $distance_km;
    } elseif (isset($overrides["{$from_id}|{$to_id}"])) {
        $source = 'override';
        $raw_km = $km = (float) $overrides["{$from_id}|{$to_id}"];
    } else {
        $raw = pt_haversine_km($from, $to) * $settings['road_factor'];
        $raw_km = $raw;
        $km = max((float) $settings['km_round'], pt_round_step($raw, (int) $settings['km_round']));
    }

    $minutes = pt_estimate_minutes((float) $km, $settings);
    $tariff_id = (string) ($tariff['id'] ?? '');
    $tariff_per_km = (float) ($tariff['per_km'] ?? 0);
    $route_per_km = pt_route_price_map()["{$from_id}|{$to_id}|" . pt_route_price_field($tariff_id)] ?? null;
    $per_km = $route_per_km ?? $tariff_per_km;
    $base = (float) ($tariff['base'] ?? 0);
    $min_price = (float) ($tariff['min_price'] ?? 0);
    $formula = $base + $per_km * (float) $km;
    $base_price = max($min_price, $formula);
    $used_min = $base_price === $min_price && $min_price > $formula;

    return [
        'from' => $from_id,
        'to' => $to_id,
        'km_source' => $source,
        'raw_km' => $raw_km !== null ? round($raw_km, 2) : null,
        'km' => $km,
        'minutes' => $minutes,
        'base' => $base,
        'per_km' => $per_km,
        'tariff_per_km' => $tariff_per_km,
        'per_km_source' => $route_per_km !== null ? 'route' : 'tariff',
        'min_price' => $min_price,
        'formula_price' => round($formula, 2),
        'used_min_price' => $used_min,
        'base_price' => round($base_price, 2),
    ];
}

/**
 * Server-side price breakdown. Yandex/OSRM are not called from admin debug by default
 * (no server API key for Maps JS); order: override → haversine×factor.
 *
 * For a cross-region pair the default is the bypass through the isthmus. The
 * «train» mode prices two separate cars and adds the railway crossing time.
 *
 * @return array<string,mixed>
 */
function pt_calculate_quote(array $from, array $to, array $tariff, array $options = []): array
{
    $settings = pt_calc_settings($options['settings'] ?? []);
    $round_trip = !empty($options['round_trip']);
    $extra_stop = !empty($options['extra_stop']);
    $child_seat = !empty($options['child_seat']);
    $crossing = pt_crossing_settings();
    $mode = (string) ($options['crossing_mode'] ?? '');
    $cross_legs = pt_crossing_legs($from, $to, $crossing);

    $legs = [];
    $km = null;
    $raw_km = null;
    $source = 'haversine';
    $minutes = 0;
    $base_price = 0;
    $formula = 0;
    $base = 0;
    $per_km = 0;
    $tariff_per_km = (float) ($tariff['per_km'] ?? 0);
    $per_km_source = 'tariff';
    $min_price = 0;
    $used_min = false;
    $applied_mode = 'direct';

    if ($mode === 'train' && $cross_legs) {
        $legs_km = isset($options['legs_km']) && is_array($options['legs_km']) ? array_values($options['legs_km']) : [];
        foreach ($cross_legs['train_legs'] as $index => $pair) {
            $distance = isset($legs_km[$index]) && is_numeric($legs_km[$index]) && (float) $legs_km[$index] > 0
                ? (float) $legs_km[$index]
                : null;
            $leg = pt_quote_leg($pair[0], $pair[1], $tariff, $settings, $distance);
            $legs[] = $leg;
            $km += $leg['km'];
            $raw_km += $leg['raw_km'] ?? 0;
            $minutes += $leg['minutes'];
            $base_price += $leg['base_price'];
            $formula += $leg['formula_price'];
            $base += $leg['base'];
            $per_km += $leg['per_km'];
            $min_price += $leg['min_price'];
            $used_min = $used_min || $leg['used_min_price'];
        }
        $minutes += (int) ($crossing['railMinutes'] ?? 0) + (int) ($crossing['transferMinutes'] ?? 0);
        $minutes = (int) pt_round_step($minutes, (int) $settings['time_round']);
        $source = 'train';
        $applied_mode = 'train';
    } elseif ($cross_legs && ($mode === 'bypass' || $mode === '')) {
        // A client on the bypass sends the real road distance; a legacy request
        // without a mode is recalculated through the isthmus and its own
        // on-the-bridge distance is deliberately ignored.
        $distance = $mode === 'bypass' && isset($options['distance_km']) && is_numeric($options['distance_km']) && (float) $options['distance_km'] > 0
            ? (float) $options['distance_km']
            : null;
        if ($distance === null) {
            $distance = 0;
            $raw_km = 0;
            $minutes = 0;
            foreach ($cross_legs['bypass_legs'] as $pair) {
                $part = pt_quote_leg($pair[0], $pair[1], $tariff, $settings);
                $distance += $part['km'];
                $raw_km += $part['raw_km'] ?? 0;
                $minutes += $part['minutes'];
            }
        } else {
            $raw_km = $distance;
            $minutes = pt_estimate_minutes($distance, $settings);
        }
        $leg = pt_quote_leg($from, $to, $tariff, $settings, $distance);
        $km = $distance;
        $legs = [$leg];
        $base_price = $leg['base_price'];
        $formula = $leg['formula_price'];
        $base = $leg['base'];
        $per_km = $leg['per_km'];
        $tariff_per_km = $leg['tariff_per_km'];
        $per_km_source = $leg['per_km_source'];
        $min_price = $leg['min_price'];
        $used_min = $leg['used_min_price'];
        $source = 'bypass';
        $applied_mode = 'bypass';
    } else {
        $distance = isset($options['distance_km']) && is_numeric($options['distance_km']) && (float) $options['distance_km'] > 0
            ? (float) $options['distance_km']
            : null;
        $leg = pt_quote_leg($from, $to, $tariff, $settings, $distance);
        $legs = [$leg];
        $km = $leg['km'];
        $raw_km = $leg['raw_km'];
        $minutes = $leg['minutes'];
        $base_price = $leg['base_price'];
        $formula = $leg['formula_price'];
        $base = $leg['base'];
        $per_km = $leg['per_km'];
        $tariff_per_km = $leg['tariff_per_km'];
        $per_km_source = $leg['per_km_source'];
        $min_price = $leg['min_price'];
        $used_min = $leg['used_min_price'];
        $source = $leg['km_source'];
    }

    $price = $base_price;
    if ($round_trip) {
        $price *= $settings['round_trip_factor'];
    }
    if ($extra_stop) {
        $price += $settings['extra_stop_price'];
    }
    if ($child_seat) {
        $price += $settings['child_seat_price'];
    }
    $total = (int) pt_round_step($price, (int) $settings['price_round']);

    return [
        'from' => (string) ($from['id'] ?? ''),
        'to' => (string) ($to['id'] ?? ''),
        'tariff' => $tariff['id'] ?? '',
        'mode' => $applied_mode,
        'legs' => array_map(static fn (array $leg): array => [
            'from' => $leg['from'],
            'to' => $leg['to'],
            'km' => $leg['km'],
            'minutes' => $leg['minutes'],
            'source' => $leg['km_source'],
        ], $legs),
        'km_source' => $source,
        'raw_km' => $raw_km !== null ? round($raw_km, 2) : null,
        'km' => $km,
        'minutes' => $minutes,
        'on_request' => !empty($tariff['on_request']),
        'base' => $base,
        'per_km' => $per_km,
        'tariff_per_km' => $tariff_per_km,
        'per_km_source' => $per_km_source,
        'min_price' => $min_price,
        'formula_price' => round($formula, 2),
        'used_min_price' => $used_min,
        'after_base_or_min' => round($base_price, 2),
        'round_trip' => $round_trip,
        'round_trip_factor' => $settings['round_trip_factor'],
        'extra_stop' => $extra_stop,
        'extra_stop_price' => $settings['extra_stop_price'],
        'child_seat' => $child_seat,
        'child_seat_price' => $settings['child_seat_price'],
        'total' => !empty($tariff['on_request']) ? null : $total,
        'total_label' => !empty($tariff['on_request']) ? 'По запросу' : (number_format($total, 0, '', ' ') . ' ₽'),
        'settings' => $settings,
    ];
}

function pt_point_payload(WP_Post $post): array
{
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
        'city' => pt_point_city_name($post),
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
