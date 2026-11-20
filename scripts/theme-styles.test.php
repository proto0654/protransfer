<?php
define('ABSPATH', __DIR__);
$actions = [];
$dequeued = [];
$frontPage = false;
function add_action($hook, $callback, $priority = 10) { global $actions; $actions[$hook] = [$callback, $priority]; }
function is_front_page() { global $frontPage; return $frontPage; }
function wp_dequeue_style($handle) { global $dequeued; $dequeued[] = $handle; }
function expect($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }

$fixture = sys_get_temp_dir() . '/pt-theme-test-' . bin2hex(random_bytes(6));
mkdir($fixture);
mkdir($fixture . '/landing');
copy(dirname(__DIR__) . '/wordpress/themes/protransfer/functions.php', $fixture . '/functions.php');
require $fixture . '/functions.php';
$callback = $actions['wp_enqueue_scripts'][0];
expect($actions['wp_enqueue_scripts'][1] > 10, 'Dequeue must run after theme styles are enqueued');
$callback();
expect($dequeued === [], 'Other pages must retain theme styles');
$frontPage = true;
$callback();
expect($dequeued === [], 'Fallback page must retain theme styles');
file_put_contents($fixture . '/landing/preview.html', '');
$callback();
expect($dequeued === ['generate-child', 'generate-style'], 'Landing must not load GeneratePress styles');
$dequeued = [];
$_GET['pt_legal'] = '1';
$callback();
expect($dequeued === [], 'Missing legal build must retain fallback styles');
file_put_contents($fixture . '/landing/legal.html', '');
$callback();
expect($dequeued === ['generate-child', 'generate-style'], 'Legal landing must not load GeneratePress styles');
unlink($fixture . '/landing/preview.html');
unlink($fixture . '/landing/legal.html');
unlink($fixture . '/functions.php');
rmdir($fixture . '/landing');
rmdir($fixture);
echo "Theme styles: 6 checks passed\n";
