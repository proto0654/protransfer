<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=pt_tariff',
        'Отладка калькулятора',
        'Отладка',
        'manage_options',
        'pt-calc-debug',
        'pt_render_calc_debug_page'
    );
});

function pt_render_calc_debug_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $points = get_posts([
        'post_type' => 'pt_point',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    $tariffs = get_posts([
        'post_type' => 'pt_tariff',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
    ]);

    $from = isset($_POST['from']) ? sanitize_title(wp_unslash($_POST['from'])) : 'sip-air';
    $to = isset($_POST['to']) ? sanitize_title(wp_unslash($_POST['to'])) : 'yalta';
    $tariff = isset($_POST['tariff']) ? sanitize_title(wp_unslash($_POST['tariff'])) : 'standard';
    $round_trip = !empty($_POST['round_trip']);
    $extra_stop = !empty($_POST['extra_stop']);

    $result = null;
    if (!empty($_POST['pt_debug_run']) && check_admin_referer('pt_calc_debug')) {
        $from_post = pt_point_by_slug($from);
        $to_post = pt_point_by_slug($to);
        $tariff_post = pt_tariff_by_slug($tariff);
        if ($from_post && $to_post && $tariff_post) {
            $result = pt_calculate_quote(
                pt_point_payload($from_post),
                pt_point_payload($to_post),
                pt_tariff_payload($tariff_post),
                compact('round_trip', 'extra_stop')
            );
        } else {
            $result = ['error' => 'Точка или тариф не найдены — сначала выполните wp pt seed'];
        }
    }
    ?>
    <div class="wrap">
      <h1>Отладка калькулятора</h1>
      <p>Серверный пересчёт (override → haversine×factor). Yandex/OSRM на сервере пока не вызываются.</p>
      <form method="post">
        <?php wp_nonce_field('pt_calc_debug'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th><label for="from">Откуда</label></th>
            <td>
              <select name="from" id="from">
                <?php foreach ($points as $p) : ?>
                  <option value="<?php echo esc_attr($p->post_name); ?>" <?php selected($from, $p->post_name); ?>>
                    <?php echo esc_html($p->post_title . ' (' . $p->post_name . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th><label for="to">Куда</label></th>
            <td>
              <select name="to" id="to">
                <?php foreach ($points as $p) : ?>
                  <option value="<?php echo esc_attr($p->post_name); ?>" <?php selected($to, $p->post_name); ?>>
                    <?php echo esc_html($p->post_title . ' (' . $p->post_name . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th><label for="tariff">Тариф</label></th>
            <td>
              <select name="tariff" id="tariff">
                <?php foreach ($tariffs as $t) : ?>
                  <option value="<?php echo esc_attr($t->post_name); ?>" <?php selected($tariff, $t->post_name); ?>>
                    <?php echo esc_html($t->post_title); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th>Опции</th>
            <td>
              <label><input type="checkbox" name="round_trip" value="1" <?php checked($round_trip); ?>> Туда-обратно</label>
              &nbsp;
              <label><input type="checkbox" name="extra_stop" value="1" <?php checked($extra_stop); ?>> Доп. остановка</label>
            </td>
          </tr>
        </table>
        <?php submit_button('Посчитать', 'primary', 'pt_debug_run'); ?>
      </form>

      <?php if (is_array($result)) : ?>
        <h2>Результат</h2>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:1rem;max-width:48rem;overflow:auto"><?php
            echo esc_html(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        ?></pre>
      <?php endif; ?>
    </div>
    <?php
}
