<?php
define('ABSPATH', __DIR__);
class WP_Post { public function __construct(public int $ID, public string $post_title, public string $post_type = 'pt_point', public string $post_status = 'publish') {} }
function add_action($name, $callback, ...$rest) { global $actions; $actions[$name] = $callback; }
$meta = []; $legacy = []; $posts = []; $actions = []; $allowed = true;
function metadata_exists($type, $id, $key) { global $meta; return array_key_exists($id, $meta); }
function get_post_meta($id, $key, $single) { global $meta; return $meta[$id] ?? ''; }
function get_post($id) { global $posts; return $posts[$id] ?? null; }
function carbon_get_post_meta($id, $key) { global $legacy; return $legacy[$id] ?? ''; }
function get_posts($args) { global $settlements; return $settlements; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function current_user_can($capability, $id) { global $allowed; return $allowed; }
function wp_is_post_revision($id) { return false; }
function update_post_meta($id, $key, $value) { global $meta; $meta[$id] = $value; }

require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/point-editor.php';
function expect($condition, $message) {
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$settlements = [new WP_Post(1, 'Евпатория'), new WP_Post(2, 'Ялта'), new WP_Post(3, 'Дубль'), new WP_Post(4, 'Дубль')];
$posts[1] = $settlements[0];
expect(pt_unique_settlement_id(' Евпатория ', 5, $settlements) === 1, 'Unique city must match');
expect(pt_unique_settlement_id('Дубль', 5, $settlements) === 0, 'Duplicate names must not be linked');
expect(pt_unique_settlement_id('Евпатория', 1, $settlements) === 0, 'Point cannot be its own parent');
expect(pt_unique_settlement_id('Неизвестно', 5, $settlements) === 0, 'Unknown city must remain unlinked');

// Parent candidates no longer come from the point type: a top-level point is one.
expect(pt_settlement_points() === $settlements, 'Every top-level published point must be a parent candidate');
$meta[2] = 1;
expect(pt_settlement_points() === [$settlements[0], $settlements[2], $settlements[3]], 'A point linked to another one must leave the candidate list');
expect(!pt_is_published_settlement(new WP_Post(9, 'Черновик', 'pt_point', 'draft')), 'A draft point must not be a settlement');
$meta[2] = 0;
expect(pt_is_published_settlement($settlements[1]), 'An explicit «no parent» must keep the point top-level');
unset($meta[2]);

$point = new WP_Post(5, 'Заозёрное'); $legacy[5] = 'Евпатория';
expect(pt_point_city_name($point) === 'Евпатория', 'Legacy city must remain visible');
$meta[5] = 2; $posts[2] = new WP_Post(2, 'Новая Ялта');
expect(pt_point_city_name($point) === 'Новая Ялта', 'Parent rename must update output');
$posts[2]->post_status = 'draft';
expect(pt_point_city_name($point) === 'Евпатория', 'Unavailable parent must fall back to legacy text');
$posts[2] = new WP_Post(2, 'Ялта', 'post');
expect(pt_point_city_name($point) === 'Евпатория', 'A parent of the wrong post type must fall back to legacy text');
expect(pt_parent_would_cycle(5, 5), 'Self parent must be rejected');
$meta[2] = 5;
expect(pt_parent_would_cycle(5, 2), 'Cycles must be rejected');
expect(pt_parent_would_cycle(5, 2, [2 => 5]), 'Planned migration cycles must be rejected');
$meta[5] = 0;
expect(pt_point_city_name($point) === '', 'Explicit clear must not restore legacy text');
$_POST = ['pt_point_location_nonce' => 'valid', 'pt_parent_point' => '1'];
$allowed = false; $actions['save_post_pt_point'](5);
expect($meta[5] === 0, 'Unauthorized edit must not save a parent');
$allowed = true; $_POST['pt_point_location_nonce'] = 'invalid'; $actions['save_post_pt_point'](5);
expect($meta[5] === 0, 'Invalid nonce must not save a parent');
unset($meta[2]);
$_POST['pt_point_location_nonce'] = 'valid'; $actions['save_post_pt_point'](5);
expect($meta[5] === 1, 'Valid parent must be saved');
$_POST['pt_parent_point'] = '999'; $actions['save_post_pt_point'](5);
expect($meta[5] === 1, 'Unknown parent must not overwrite a valid relationship');
$meta[5] = 0; $_POST['pt_parent_point'] = '5'; $actions['save_post_pt_point'](5);
expect($meta[5] === 0, 'A point cannot be its own parent');
$meta[1] = 5; $_POST['pt_parent_point'] = '1'; $actions['save_post_pt_point'](5);
expect($meta[5] === 0, 'A parent that belongs to another point must not be saved');
echo 'Point relationships: ' . $GLOBALS['checks'] . " checks passed\n";
