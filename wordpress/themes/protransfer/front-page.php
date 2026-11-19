<?php
/**
 * Staging front page placeholder — landing lives in Vite until ported.
 */
get_header();
?>
<main class="site-content">
  <p style="letter-spacing:.12em;text-transform:uppercase;font-size:.75rem;opacity:.6;margin-bottom:.5rem">Про Трансфер</p>
  <h1 style="font-size:2rem;line-height:1.2;margin:0 0 1rem">Стейджинг WordPress</h1>
  <p style="margin:0 0 1.5rem;opacity:.8">Админка калькулятора и данные API. Публичный лендинг пока собирается отдельно.</p>
  <p>
    <a href="<?php echo esc_url(admin_url()); ?>">Админка</a>
    ·
    <a href="<?php echo esc_url(rest_url('pt/v1/bootstrap')); ?>">API bootstrap</a>
  </p>
</main>
<?php
get_footer();
