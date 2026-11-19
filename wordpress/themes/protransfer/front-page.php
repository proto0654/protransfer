<?php
/**
 * Homepage: serve the Vite landing build from /landing when present.
 */
if (!defined('ABSPATH')) {
    exit;
}

$is_legal = isset($_GET['pt_legal']);
$landing = __DIR__ . ($is_legal ? '/landing/legal.html' : '/landing/preview.html');
if (is_readable($landing)) {
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    $html = (string) file_get_contents($landing);
    $site = [];
    if (function_exists('pt_rest_bootstrap')) {
        $data = pt_rest_bootstrap()->get_data();
        $site = $data['site'];
        $html = pt_render_landing_copy($html, $site);
        $title = $is_legal ? ($site['copy']['legal_title'] ?? 'Документы') . ' — ' . $site['brand'] : $site['seo']['title'];
        $html = preg_replace_callback('~<title>.*?</title>~s', static fn () => '<title>' . esc_html($title) . '</title>', $html);
        $description = esc_attr($site['seo']['description']);
        $html = preg_replace_callback('~<meta\s+name="description"[^>]*>~s', static fn () => '<meta name="description" content="' . $description . '">', $html);
        $html = preg_replace_callback('~<meta\s+property="og:title"[^>]*>~s', static fn () => '<meta property="og:title" content="' . esc_attr($title) . '">', $html);
        $html = preg_replace_callback('~<meta\s+property="og:description"[^>]*>~s', static fn () => '<meta property="og:description" content="' . $description . '">', $html);
        $html = preg_replace('~<script type="application/ld\+json">.*?</script>~s', '', $html);
        $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $bootstrap = '<script id="pt-bootstrap" type="application/json">' . wp_json_encode($data, $json_flags) . '</script>';
        if (!$is_legal) {
            $structured = ['@context' => 'https://schema.org', '@type' => 'TaxiService', 'name' => $site['brand'],
                'description' => $site['seo']['description'], 'telephone' => $site['phone'], 'url' => home_url('/')];
            $bootstrap .= '<script type="application/ld+json">' . wp_json_encode($structured, $json_flags) . '</script>';
        }
        $html = str_replace('</head>', $bootstrap . '</head>', $html);
    }
    $html = str_replace('href="legal.html', 'href="' . esc_url(add_query_arg('pt_legal', '1', home_url('/'))), $html);
    $html = str_replace(['href="index.html"', 'href="preview.html"'], 'href="' . esc_url(home_url('/')) . '"', $html);
    // The landing owns the document title; retain other WordPress and plugin hooks.
    remove_action('wp_head', '_wp_render_title_tag', 1);
    ob_start();
    wp_head();
    $head = ob_get_clean();
    ob_start();
    wp_body_open();
    $body_open = ob_get_clean();
    ob_start();
    wp_footer();
    $footer = ob_get_clean();
    $html = str_replace('</head>', $head . '</head>', $html);
    $html = preg_replace_callback('~<body\b[^>]*>~', static fn ($match) => $match[0] . $body_open, $html, 1);
    $html = str_replace('</body>', $footer . '</body>', $html);
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template and escaped field output
    echo $html;
    exit;
}

get_header();
?>
<main class="site-content">
  <p style="letter-spacing:.12em;text-transform:uppercase;font-size:.75rem;opacity:.6;margin-bottom:.5rem">Про Трансфер</p>
  <h1 style="font-size:2rem;line-height:1.2;margin:0 0 1rem">Стейджинг WordPress</h1>
  <p style="margin:0 0 1.5rem;opacity:.8">Лендинг ещё не задеплоен. Запустите <code>npm run deploy:weblaba</code>.</p>
  <p>
    <a href="<?php echo esc_url(admin_url()); ?>">Админка</a>
    ·
    <a href="<?php echo esc_url(rest_url('pt/v1/bootstrap')); ?>">API bootstrap</a>
  </p>
</main>
<?php
get_footer();
