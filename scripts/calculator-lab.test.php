<?php
define('ABSPATH', __DIR__);
function current_user_can($capability) { return false; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message, $args['response'] ?? 0); }
$_GET['pt_calc_debug'] = '1';
try {
    require dirname(__DIR__) . '/wordpress/themes/protransfer/front-page.php';
    fwrite(STDERR, "Unauthenticated lab request was not blocked\n"); exit(1);
} catch (RuntimeException $error) {
    if ($error->getCode() !== 403) { fwrite(STDERR, "Lab returned the wrong error\n"); exit(1); }
}
echo "Calculator lab access: 1 check passed\n";
