<?php
/**
 * Crossing the Kerch strait: bypass through the isthmus and the two-car train
 * option must price and time the same way as the browser calculator.
 */
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');

class WP_Post
{
    public function __construct(
        public int $ID,
        public string $post_title,
        public string $post_type = 'pt_point',
        public string $post_status = 'publish',
        public string $post_name = ''
    ) {
    }
}

$theme_options = [];
$carbon = [];
$posts = [];
$point_slugs = [];

function carbon_get_theme_option($key)
{
    global $theme_options;
    return $theme_options[$key] ?? '';
}

function carbon_get_post_meta($id, $key)
{
    global $carbon;
    return $carbon[(int) $id][$key] ?? '';
}

function pt_point_city_name($post): string
{
    return '';
}

function pt_point_by_slug(string $slug): ?WP_Post
{
    global $point_slugs, $posts;
    $id = $point_slugs[$slug] ?? 0;
    return $id ? ($posts[$id] ?? null) : null;
}

function add_point(string $slug, string $title, float $lat, float $lon): array
{
    global $posts, $carbon, $point_slugs;
    static $id = 100;
    $id++;
    $posts[$id] = new WP_Post($id, $title, 'pt_point', 'publish', $slug);
    $carbon[$id] = ['pt_lat' => $lat, 'pt_lon' => $lon];
    $point_slugs[$slug] = $id;
    return ['id' => $slug, 'name' => $title, 'lat' => $lat, 'lon' => $lon];
}

require PT_CORE_PATH . 'includes/calc.php';

$sip = add_point('sip-air', 'Аэропорт Симферополь', 45.0522, 33.9751);
$yalta = add_point('yalta', 'Ялта', 44.4952, 34.1663);
$anapa = add_point('anapa', 'Анапа', 44.8949, 37.3169);
add_point('kerch-yuzhnaya', 'Керчь-Южная', 45.318079, 36.429475);
add_point('taman', 'Тамань-Пассажирская', 45.2167, 36.7167);
add_point('dzhankoy', 'Джанкой', 45.717, 34.383);

$tariff = ['id' => 'comfort', 'base' => 200, 'per_km' => 20, 'min_price' => 1000];
$theme_options['pt_route_overrides'] = [
    ['from_slug' => 'sip-air', 'to_slug' => 'kerch-yuzhnaya', 'km' => 200],
    ['from_slug' => 'taman', 'to_slug' => 'anapa', 'km' => 88],
    ['from_slug' => 'sip-air', 'to_slug' => 'dzhankoy', 'km' => 90],
    ['from_slug' => 'dzhankoy', 'to_slug' => 'melitopol', 'km' => 150],
    ['from_slug' => 'melitopol', 'to_slug' => 'berdyansk', 'km' => 120],
    ['from_slug' => 'berdyansk', 'to_slug' => 'mariupol', 'km' => 100],
    ['from_slug' => 'mariupol', 'to_slug' => 'rostov', 'km' => 180],
    ['from_slug' => 'rostov', 'to_slug' => 'anapa', 'km' => 300],
];

function expect_equal($actual, $expected, string $message): void
{
    if ($actual != $expected) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$train = pt_calculate_quote($sip, $anapa, $tariff, ['crossing_mode' => 'train']);
expect_equal($train['mode'], 'train', 'train mode');
expect_equal($train['km'], 288, 'train auto kilometres');
expect_equal(count($train['legs']), 2, 'train has two car legs');
expect_equal($train['legs'][0]['from'], 'sip-air', 'first car leg starts at A');
expect_equal($train['legs'][0]['to'], 'kerch-yuzhnaya', 'first car leg ends at the Crimea station');
expect_equal($train['legs'][1]['from'], 'taman', 'second car leg starts at the mainland station');
expect_equal($train['legs'][1]['to'], 'anapa', 'second car leg ends at B');
expect_equal($train['total'], 6160, 'each car leg pays its own minimum and base');
expect_equal($train['minutes'], 320, 'train time adds rail and transfer');

$bypass = pt_calculate_quote($sip, $anapa, $tariff, ['crossing_mode' => 'bypass', 'distance_km' => 550]);
expect_equal($bypass['mode'], 'bypass', 'bypass mode');
expect_equal($bypass['km'], 550, 'bypass uses the road distance');
expect_equal($bypass['total'], 11200, 'bypass is one car for the whole distance');

$legacy = pt_calculate_quote($sip, $anapa, $tariff, ['distance_km' => 300]);
expect_equal($legacy['mode'], 'bypass', 'a legacy cross-region request is recalculated through the isthmus');
expect_equal($legacy['km'], 940, 'a legacy on-bridge distance is ignored');
expect_equal($legacy['total'], 19000, 'a legacy quote matches the bypass');

$reverse = pt_calculate_quote($anapa, $sip, $tariff, ['crossing_mode' => 'train']);
expect_equal($reverse['legs'][0]['to'], 'taman', 'reverse train starts at the mainland station');
expect_equal($reverse['legs'][1]['from'], 'kerch-yuzhnaya', 'reverse train continues from the Crimea station');

$local = pt_calculate_quote($sip, $yalta, $tariff, ['crossing_mode' => 'train']);
expect_equal($local['mode'], 'direct', 'a route inside Crimea ignores the crossing');
expect_equal(count($local['legs']), 1, 'a direct route has one leg');

$zero = pt_quote_leg($sip, $sip, $tariff, pt_calc_settings([]));
expect_equal($zero['km'], 0, 'a zero-length leg is free');
expect_equal($zero['base_price'], 0, 'a zero-length leg does not add its minimum');

echo "Crossing: 24 checks passed\n";
