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

add_action('wp_ajax_pt_calc_debug', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав.', 403);
    }
    check_ajax_referer('pt_calc_debug');
    $result = pt_debug_quote($_POST);
    if (isset($result['error'])) {
        wp_send_json_error($result, 400);
    }
    wp_send_json_success($result);
});

function pt_debug_settings($input): array
{
    if (!is_array($input)) {
        return ['error' => 'Проверьте настройки калькулятора.'];
    }
    $defaults = pt_calc_defaults();
    $positive = ['road_factor', 'km_round', 'speed_short', 'speed_mid', 'speed_long', 'time_round', 'price_round'];
    $integers = ['km_round', 'time_round', 'price_round'];
    $settings = [];
    foreach ($defaults as $key => $default) {
        $value = $input[$key] ?? null;
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value)) {
            return ['error' => 'Заполните все настройки корректными числами.'];
        }
        $number = (float) $value;
        if ($number < 0 || (in_array($key, $positive, true) && $number <= 0) || (in_array($key, $integers, true) && (floor($number) !== $number || $number < 1))) {
            return ['error' => 'Проверьте положительные значения скоростей и целые шаги округления.'];
        }
        $settings[$key] = $number;
    }
    if ($settings['speed_mid_threshold'] > $settings['speed_long_threshold']) {
        return ['error' => 'Граница короткого маршрута не может превышать границу длинного.'];
    }
    return $settings;
}

add_action('wp_ajax_pt_save_calc_settings', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав.', 403);
    }
    check_ajax_referer('pt_calc_debug');
    if (!function_exists('carbon_set_theme_option')) {
        wp_send_json_error('Carbon Fields недоступен.', 500);
    }
    $settings = pt_debug_settings(wp_unslash($_POST['calc'] ?? []));
    if (isset($settings['error'])) {
        wp_send_json_error($settings['error'], 400);
    }
    foreach ($settings as $key => $value) {
        carbon_set_theme_option('pt_' . $key, (string) $value);
    }
    wp_send_json_success();
});

function pt_debug_quote(array $input): array
{
    $settings = isset($input['calc']) ? pt_debug_settings(wp_unslash($input['calc'])) : pt_calc_settings();
    if (isset($settings['error'])) {
        return $settings;
    }

    $from = sanitize_title(wp_unslash($input['from'] ?? ''));
    $to = sanitize_title(wp_unslash($input['to'] ?? ''));
    $tariff = sanitize_title(wp_unslash($input['tariff'] ?? ''));
    $distance = sanitize_text_field(wp_unslash($input['distance_km'] ?? ''));
    if ($from === $to) {
        return ['error' => 'Выберите разные точки маршрута.', 'settings' => $settings];
    }
    if ($distance !== '' && (!is_numeric($distance) || !is_finite((float) $distance) || (float) $distance <= 0)) {
        return ['error' => 'Расстояние должно быть положительным числом.', 'settings' => $settings];
    }
    $from_post = pt_point_by_slug($from);
    $to_post = pt_point_by_slug($to);
    $tariff_post = pt_tariff_by_slug($tariff);
    if (!$from_post || !$to_post || !$tariff_post) {
        return ['error' => 'Точка или тариф не найдены.', 'settings' => $settings];
    }
    $options = [
        'round_trip' => !empty($input['round_trip']),
        'extra_stop' => !empty($input['extra_stop']),
        'child_seat' => !empty($input['child_seat']),
        'settings' => $settings,
    ];
    if ($distance !== '') {
        $options['distance_km'] = (float) $distance;
    }
    return pt_calculate_quote(pt_point_payload($from_post), pt_point_payload($to_post), pt_tariff_payload($tariff_post), $options);
}

add_action('admin_enqueue_scripts', function () {
    if (($_GET['page'] ?? '') !== 'pt-calc-debug') {
        return;
    }
    wp_enqueue_script('pt-calc-debug', PT_CORE_URL . 'includes/admin-debug.js', [], PT_CORE_VERSION, true);
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

    $from = isset($_POST['from']) ? sanitize_title(wp_unslash($_POST['from'])) : ($points[0]->post_name ?? '');
    $to = isset($_POST['to']) ? sanitize_title(wp_unslash($_POST['to'])) : ($points[1]->post_name ?? '');
    $tariff = isset($_POST['tariff']) ? sanitize_title(wp_unslash($_POST['tariff'])) : ($tariffs[0]->post_name ?? '');
    $round_trip = !empty($_POST['round_trip']);
    $extra_stop = !empty($_POST['extra_stop']);
    $child_seat = !empty($_POST['child_seat']);
    $distance_input = isset($_POST['distance_km']) ? sanitize_text_field(wp_unslash($_POST['distance_km'])) : '';

    $result = null;
    if (!empty($_POST['pt_debug_run']) && check_admin_referer('pt_calc_debug')) {
        $result = pt_debug_quote($_POST);
    }
    ?>
    <div class="wrap">
      <h1>Отладка калькулятора</h1>
      <h2>Калькулятор сайта</h2>
      <p>Это тот же калькулятор, что на сайте. Выберите маршрут и тариф: ниже синхронизируются параметры серверной проверки. Настройки справа применяются к пробному расчёту до сохранения.</p>
      <iframe id="pt-calc-frontend" title="Калькулятор сайта для проверки" src="<?php echo esc_url(add_query_arg('pt_calc_debug', '1', home_url('/'))); ?>" style="width:100%;height:1150px;border:1px solid #ccd0d4;background:white" loading="lazy"></iframe>
      <h2>Серверная проверка и настройки</h2>

      <p>Серверная проверка использует тарифы и точки. Сайт сначала запрашивает Яндекс или OSRM; чтобы сравнить цену, введите километраж с сайта вручную.</p>
      <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=pt_tariff&page=pt-calculator')); ?>">Настройки расчёта</a> · <a href="<?php echo esc_url(admin_url('edit.php?post_type=pt_point&page=pt-routes')); ?>">Маршруты: км и цены</a> · <a href="<?php echo esc_url(admin_url('edit.php?post_type=pt_tariff')); ?>">Тарифы</a></p>
      <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
      <div style="flex:1 1 420px;min-width:0">
      <form method="post" id="pt-debug-form" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('pt_calc_debug')); ?>">
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
          <tr><th><label for="distance_km">Расстояние с сайта, км</label></th><td><input type="number" id="distance_km" name="distance_km" min="0.01" step="any" value="<?php echo esc_attr($distance_input); ?>"><p class="description">Необязательно. Если пусто, используется ручной маршрут или оценка по координатам.</p></td></tr>
          <tr>
            <th>Опции</th>
            <td>
              <label><input type="checkbox" name="round_trip" value="1" <?php checked($round_trip); ?>> Туда-обратно</label>
              &nbsp;
              <label><input type="checkbox" name="child_seat" value="1" <?php checked($child_seat); ?>> Детское кресло</label>
              &nbsp;
              <label><input type="checkbox" name="extra_stop" value="1" <?php checked($extra_stop); ?>> Доп. остановка</label>
            </td>
          </tr>
        </table>
        <?php submit_button('Обновить расчёт', 'secondary', 'pt_debug_run'); ?>
      </form>

      <div id="pt-live-result" aria-live="polite"></div>
      <noscript>
      <?php if (is_array($result)) : ?>
        <h2>Результат</h2>
        <?php if (isset($result['error'])) : ?>
          <div class="notice notice-error inline"><p><?php echo esc_html($result['error']); ?></p></div>
        <?php else : ?>
          <table class="widefat striped" style="max-width:48rem"><tbody>
            <?php foreach ([
                'Источник расстояния' => ['manual' => 'Введено вручную', 'override' => 'Ручной маршрут', 'haversine' => 'Оценка по координатам'][$result['km_source']],
                'Расстояние' => $result['km'] . ' км',
                'Время (оценка)' => $result['minutes'] . ' мин',
                'База + ставка × км' => $result['formula_price'] . ' ₽',
                'Ставка за км' => $result['per_km'] . ' ₽ (' . (($result['per_km_source'] ?? 'tariff') === 'route' ? 'цена маршрута' : 'тариф') . ')',
                'Минимальная цена' => $result['min_price'] . ' ₽',
                'После минимума' => $result['after_base_or_min'] . ' ₽',
                'Туда-обратно' => $result['round_trip'] ? '× ' . $result['round_trip_factor'] : 'Нет',
                'Остановка' => $result['extra_stop'] ? '+ ' . $result['extra_stop_price'] . ' ₽' : 'Нет',
                'Детское кресло' => $result['child_seat'] ? '+ ' . $result['child_seat_price'] . ' ₽' : 'Нет',
                'Итого' => $result['total_label'],
            ] as $label => $value) : ?>
              <tr><th scope="row"><?php echo esc_html($label); ?></th><td><?php echo esc_html((string) $value); ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <p>Итог округляется до <?php echo esc_html((string) $result['settings']['price_round']); ?> ₽.</p>
        <?php endif; ?>
      <?php endif; ?>
      </noscript>
      </div>
      <div style="flex:1 1 440px;min-width:0">
        <h2>Настройки расчёта</h2>
        <p>Изменения сразу участвуют в проверке. Для сайта нажмите «Сохранить настройки».</p>
        <?php
        $settings = pt_calc_settings();
        $labels = [
            'road_factor' => 'Коэффициент дороги', 'km_round' => 'Округление расстояния, км',
            'speed_short' => 'Скорость короткого маршрута, км/ч', 'speed_mid' => 'Скорость среднего маршрута, км/ч',
            'speed_long' => 'Скорость длинного маршрута, км/ч', 'speed_mid_threshold' => 'Граница короткого маршрута, км',
            'speed_long_threshold' => 'Граница длинного маршрута, км', 'time_buffer' => 'Надбавка времени, мин',
            'time_round' => 'Округление времени, мин', 'round_trip_factor' => 'Коэффициент туда-обратно',
            'extra_stop_price' => 'Дополнительная остановка, ₽', 'child_seat_price' => 'Детское кресло, ₽',
            'price_round' => 'Округление цены, ₽',
        ];
        ?>
        <table class="widefat striped"><tbody>
        <?php foreach ($labels as $key => $label) : ?>
          <tr><th scope="row"><label for="pt-setting-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
          <td><input form="pt-debug-form" id="pt-setting-<?php echo esc_attr($key); ?>" type="number" step="any" min="0" required name="calc[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $settings[$key]); ?>"></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><button type="button" class="button button-primary" id="pt-save-settings">Сохранить настройки</button> <span id="pt-save-status" aria-live="polite"></span></p>
      </div>
      </div>
    </div>
    <?php
}
