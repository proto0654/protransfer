<?php
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');
function add_action(...$args) {}
function wp_unslash($value) { return $value; }
function sanitize_title($value) { return $value; }
function sanitize_text_field($value) { return $value; }
require PT_CORE_PATH . 'includes/calc.php';
require PT_CORE_PATH . 'includes/admin-debug.php';
function expect($condition, string $message): void { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$valid = pt_calc_defaults();
expect(!isset(pt_debug_settings($valid)['error']), 'Default settings must be accepted');
$valid['extra_stop_price'] = 0;
expect(!isset(pt_debug_settings($valid)['error']), 'Zero surcharge must be accepted');
$invalid = $valid; $invalid['speed_short'] = 0;
expect(isset(pt_debug_settings($invalid)['error']), 'Zero speed must be rejected');
$invalid = $valid; $invalid['price_round'] = 0.5;
expect(isset(pt_debug_settings($invalid)['error']), 'Fractional rounding step must be rejected');
$invalid = $valid; $invalid['speed_mid_threshold'] = 300;
expect(isset(pt_debug_settings($invalid)['error']), 'Reversed distance thresholds must be rejected');
$invalid = $valid; unset($invalid['road_factor']);
expect(isset(pt_debug_settings($invalid)['error']), 'Missing settings must be rejected');
$valid['extra_stop_price'] = 777;
$empty_route = pt_debug_quote(['calc' => $valid]);
expect(isset($empty_route['error']) && ($empty_route['settings']['extra_stop_price'] ?? null) === 777.0, 'Preview settings must pass through even when the route is incomplete');
echo "Calculator admin settings: 7 checks passed\n";
