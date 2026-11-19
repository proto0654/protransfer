<?php
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($value) { return strip_tags($value, '<p><strong><a>'); }
function metadata_exists($type, $id, $key) { return $key === '_pt_copy_hero_title'; }
function carbon_get_post_meta($id, $key) { return ''; }
require PT_CORE_PATH . 'includes/landing-content.php';
function expect($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$copy = pt_landing_copy(1);
expect($copy['hero_title'] === '', 'Explicitly cleared content must stay empty');
expect($copy['hero_accent'] === 'просто', 'Uninitialized fields must use schema defaults');
$html = '<h1 data-pt-copy="hero_title" x-text="site.copy.hero_title">Old</h1><span data-pt-site="brand">Old brand</span>';
$output = pt_render_landing_copy($html, ['copy' => ['hero_title' => '<script>alert(1)</script> $1'], 'brand' => 'A & B']);
expect(str_contains($output, '&lt;script&gt;alert(1)&lt;/script&gt; $1'), 'Editor text must be escaped, with literal dollar signs');
expect(str_contains($output, 'A &amp; B'), 'Brand must be rendered and escaped');
expect(!str_contains($output, '>Old'), 'Old text must be replaced');
echo "Landing content: 5 checks passed\n";
