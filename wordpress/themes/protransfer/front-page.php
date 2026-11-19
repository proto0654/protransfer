<?php
/**
 * Homepage: serve the Vite landing build from /landing when present.
 */
if (!defined('ABSPATH')) {
    exit;
}

$landing = __DIR__ . '/landing/preview.html';
if (is_readable($landing)) {
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static built HTML
    readfile($landing);
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
