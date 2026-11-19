<?php
// Runs the real calculator with controlled WordPress option values.
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');
$options = json_decode(stream_get_contents(STDIN), true);
function carbon_get_theme_option($key) { global $options; return $options[$key] ?? ''; }
require PT_CORE_PATH . 'includes/calc.php';
$from = ['id' => 'a', 'lat' => 45, 'lon' => 34];
$to = ['id' => 'b', 'lat' => 45.5, 'lon' => 34.5];
$tariff = ['id' => 'test', 'base' => 200, 'per_km' => 20, 'min_price' => 1000];
echo json_encode(pt_calculate_quote($from, $to, $tariff, ['round_trip' => true, 'extra_stop' => true, 'child_seat' => true]));
