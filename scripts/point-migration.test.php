<?php
define('ABSPATH', __DIR__);
class WP_Post { public string $post_type = 'pt_point'; public string $post_status = 'publish'; public function __construct(public int $ID, public string $post_title, public string $post_name) {} }
class WP_CLI { public static function add_command(...$args) {} public static function log($s) {} public static function warning($s) {} public static function success($s) {} public static function error($s) { throw new RuntimeException($s); } }
function add_action(...$args) {}
$posts = [new WP_Post(1, 'Ялта', 'yalta'), new WP_Post(2, 'Отель', 'hotel'), new WP_Post(3, 'Неизвестное', 'unknown'), new WP_Post(4, 'Дубль', 'same1'), new WP_Post(5, 'Дубль', 'same2'), new WP_Post(6, 'Станция', 'station')];
$cities = [1 => 'Ялта', 2 => 'Ялта', 3 => 'Нигде', 4 => 'Дубль', 5 => 'Дубль', 6 => 'Дубль'];
$meta = []; $options = []; $failAdd = false; $failDelete = false; $ignoreTermUpdate = false;
$terms = [10 => (object) ['term_id' => 10, 'slug' => 'city', 'name' => 'City'], 11 => (object) ['term_id' => 11, 'slug' => 'resort', 'name' => 'Resort']];
function get_posts($args) { global $posts; return isset($args['tax_query']) ? [$posts[0], $posts[3], $posts[4]] : $posts; }
function carbon_get_post_meta($id, $key) { global $cities; return $cities[$id] ?? ''; }
function metadata_exists($type, $id, $key) { global $meta; return array_key_exists($id, $meta); }
function get_post_meta($id, $key, $single) { global $meta; return $meta[$id] ?? ''; }
function add_post_meta($id, $key, $value, $unique) { global $meta, $failAdd; if ($failAdd || isset($meta[$id])) return false; $meta[$id] = $value; return true; }
function delete_post_meta($id, $key, $value) { global $meta, $failDelete; if ($failDelete || ($meta[$id] ?? null) !== $value) return false; unset($meta[$id]); return true; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$rest) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function update_option($key, $value, ...$rest) { global $options; $options[$key] = $value; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
function get_term_by($field, $slug, $taxonomy) { global $terms; foreach ($terms as $term) if ($term->slug === $slug) return $term; return false; }
function get_term($id, $taxonomy) { global $terms; return $terms[$id] ?? false; }
function wp_update_term($id, $taxonomy, $args) { global $terms, $ignoreTermUpdate; if (!$ignoreTermUpdate) $terms[$id]->name = $args['name']; return ['term_id' => $id]; }
function is_wp_error($value) { return false; }
require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/point-editor.php';
require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/cli.php';
function expect($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$cli = new PT_CLI_Command(); $cli->migrate_points([], []);
expect($meta === [] && $terms[10]->name === 'City', 'Preview must not change data');
$cli->migrate_points([], ['apply' => true]);
expect(($meta[2] ?? null) === 1, 'Unique parent should be linked');
expect(!isset($meta[3]) && !isset($meta[6]), 'Unknown and ambiguous names must not link');
expect($terms[10]->name === 'Город' && $terms[10]->slug === 'city', 'Type names must change without changing slugs');
$cli->migrate_points([], ['apply' => true]);
expect(count($meta) === 1, 'Repeated migration must not duplicate links');
$meta[2] = 4;
try { $cli->migrate_points([], ['rollback' => true]); } catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'partial'), 'Incomplete rollback must fail visibly');
}
expect($meta[2] === 4 && $terms[10]->name === 'City', 'Rollback must preserve subsequent editor changes');
expect(isset($options['pt_point_migration_backup']), 'Unresolved rollback entries must remain recorded');
$meta[2] = 1; $cli->migrate_points([], ['rollback' => true]);
expect(!isset($meta[2]) && !isset($options['pt_point_migration_backup']), 'Rollback must remove unchanged migration links and backup');
$failAdd = true;
try { $cli->migrate_points([], ['apply' => true]); } catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'Could not link'), 'Failed writes must stop the migration');
}
expect(isset($options['pt_point_migration_backup']) && !isset($meta[2]), 'Failed apply must leave recoverable metadata');
$cli->migrate_points([], ['rollback' => true]);
expect($options === [], 'Rollback should discard planned changes that never ran');
$failAdd = false; $cli->migrate_points([], ['apply' => true]);
$failDelete = true;
$ignoreTermUpdate = true;
try { $cli->migrate_points([], ['rollback' => true]); } catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'partial'), 'Failed rollback writes must stop cleanly');
}
expect(isset($options['pt_point_migration_backup']) && isset($meta[2]), 'Failed rollback deletion must keep its backup');
expect($terms[10]->name === 'Город' && isset($options['pt_point_migration_backup']['terms'][10]), 'Rollback must retain a silently failed taxonomy write');
$failDelete = false;
$ignoreTermUpdate = false;
$cli->migrate_points([], ['rollback' => true]);
expect($terms[10]->name === 'City' && $options === [], 'Successful retry should clear the remaining journal');
$ignoreTermUpdate = true;
try { $cli->migrate_points([], ['apply' => true]); } catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'Could not rename'), 'Unchanged term labels must fail apply');
}
expect($terms[10]->name === 'City' && isset($options['pt_point_migration_backup']), 'Failed apply must retain the rollback journal');
$cli->migrate_points([], ['rollback' => true]);
expect($meta === [] && $options === [], 'Rollback should clear links written before a failed term rename');
echo "Point migration rollback and failure cases passed\n";
