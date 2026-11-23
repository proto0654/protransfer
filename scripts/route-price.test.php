<?php
// Route price («Маршруты» → ₽/км per tariff) must win over the tariff rate in
// the server-side quote, in both directions, and must fall back when unset.
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');

$GLOBALS['theme_options'] = [];
function carbon_get_theme_option($key)
{
    return $GLOBALS['theme_options'][$key] ?? '';
}

require PT_CORE_PATH . 'includes/calc.php';

function expect($condition, string $message): void
{
    static $checks = 0;
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    $GLOBALS['pt_checks'] = ++$checks;
}

function route_row(string $from, string $to, $km, array $prices = []): array
{
    $row = ['from_slug' => $from, 'to_slug' => $to, 'km' => $km];
    foreach ($prices as $tariff_slug => $price) {
        $row[pt_route_price_field($tariff_slug)] = $price;
    }
    return $row;
}

$airport = ['id' => 'sip-air', 'lat' => 45.05, 'lon' => 33.98];
$yalta = ['id' => 'yalta', 'lat' => 44.49, 'lon' => 34.17];
$comfort = ['id' => 'comfort', 'base' => 1000, 'per_km' => 30, 'min_price' => 1000];
$standard = ['id' => 'standard', 'base' => 500, 'per_km' => 28, 'min_price' => 0];

expect(pt_route_price_field('comfort') === 'price_comfort', 'A plain slug keeps its own field key');
expect(pt_route_price_field('comfort-pl') === 'price_comfort_pl', 'A hyphenated slug must be normalised for a Carbon field name');
expect(pt_route_price_field(' Comfort ') === 'price_comfort', 'The key must tolerate whitespace and case');

$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('sip-air', 'yalta', 50, ['comfort' => 40]),
];

// 1. Own route price beats the tariff rate.
$quote = pt_calculate_quote($airport, $yalta, $comfort, ['distance_km' => 50]);
expect($quote['per_km'] === 40.0, 'The route price must replace the tariff ₽/км');
expect($quote['tariff_per_km'] === 30.0, 'The tariff rate must stay readable in the quote');
expect($quote['per_km_source'] === 'route', 'The quote must record where the ₽/км came from');
expect($quote['formula_price'] === 3000.0, '1000 + 50 km × 40 ₽/km must be 3000 ₽');
expect($quote['total'] === 3000, 'The total must use the route price');

// 2. The same row does not affect another tariff.
$other = pt_calculate_quote($airport, $yalta, $standard, ['distance_km' => 50]);
expect($other['per_km'] === 28.0 && $other['per_km_source'] === 'tariff', 'A price set for one tariff must not leak into another');
expect($other['total'] === 1900, '500 + 50 km × 28 ₽/km must be 1900 ₽');

// 3. Both directions of the pair share the price.
$reverse = pt_calculate_quote($yalta, $airport, $comfort, ['distance_km' => 50]);
expect($reverse['per_km'] === 40.0 && $reverse['total'] === 3000, 'The route price must apply in both directions');

// 4. No own price — the tariff rate is used, as before.
$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('sip-air', 'yalta', 50),
];
$plain = pt_calculate_quote($airport, $yalta, $comfort, ['distance_km' => 50]);
expect($plain['per_km'] === 30.0 && $plain['per_km_source'] === 'tariff', 'A route without its own price keeps the tariff rate');
expect($plain['total'] === 2500, '1000 + 50 km × 30 ₽/km must stay 2500 ₽');

// 5. An empty or zero price means «not set».
$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('sip-air', 'yalta', 50, ['comfort' => '']),
    route_row('sip-air', 'sevastopol', 90, ['comfort' => 0]),
];
$blank = pt_calculate_quote($airport, $yalta, $comfort, ['distance_km' => 50]);
expect($blank['per_km_source'] === 'tariff', 'An empty price field must fall back to the tariff');
$sevastopol = ['id' => 'sevastopol', 'lat' => 44.6, 'lon' => 33.52];
$zero = pt_calculate_quote($airport, $sevastopol, $comfort, ['distance_km' => 90]);
expect($zero['per_km_source'] === 'tariff', 'A zero price must fall back to the tariff');

// 6. A price can be set without manual kilometres: the distance still comes
//    from the coordinate estimate.
$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('sip-air', 'yalta', '', ['comfort' => 40]),
];
$priced_only = pt_calculate_quote($airport, $yalta, $comfort);
expect($priced_only['per_km'] === 40.0 && $priced_only['per_km_source'] === 'route', 'A price-only row must still apply its price');
expect($priced_only['km_source'] === 'haversine', 'A price-only row must not fake a manual distance');
expect($priced_only['formula_price'] === round(1000 + 40 * $priced_only['km'], 2), 'The formula must use the estimated kilometres');

// 7. Minimum price still applies on top of the route rate.
$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('yalta', 'sevastopol', 5, ['comfort' => 40]),
];
$minimum_tariff = ['id' => 'comfort', 'base' => 1000, 'per_km' => 30, 'min_price' => 2000];
$short = pt_calculate_quote($yalta, $sevastopol, $minimum_tariff, ['distance_km' => 5]);
expect($short['per_km'] === 40.0, 'The route price must still be applied on a short route');
expect($short['used_min_price'] === true, 'The tariff minimum must still win on a very short route');
expect($short['total'] === 2000, 'The minimum price must be the total');

// 8. The public bootstrap carries the price keyed by tariff slug, so the site
//    calculator can apply it without knowing the admin field names.
$GLOBALS['theme_options']['pt_route_overrides'] = [
    route_row('sip-air', 'yalta', 50, ['comfort' => 40, 'comfort-pl' => 55]),
    route_row('sip-air', 'sevastopol', '', ['comfort' => 45]),
    route_row('', 'yalta', 30, ['comfort' => 99]),
    route_row('sip-air', 'kerch', '', []),
];
$payload = pt_route_override_payload(['comfort', 'comfort-pl', 'standard']);
expect(count($payload) === 2, 'Rows without both points or without any value must stay out of the bootstrap');
expect($payload[0] === ['from' => 'sip-air', 'to' => 'yalta', 'km' => 50.0, 'prices' => ['comfort' => 40.0, 'comfort-pl' => 55.0]], 'The bootstrap row must carry km and prices keyed by tariff slug');
expect($payload[1] === ['from' => 'sip-air', 'to' => 'sevastopol', 'prices' => ['comfort' => 45.0]], 'A price-only row must not gain a fake distance');

echo "Route prices: {$GLOBALS['pt_checks']} checks passed\n";
