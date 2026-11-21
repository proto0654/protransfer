<?php
/**
 * Public order intake: validation, trusted price recalculation, persistence and
 * notification dispatch. The REST route itself stays in rest.php.
 */
if (!defined('ABSPATH')) {
    exit;
}

function pt_order_statuses(): array
{
    return [
        'new' => 'Новая',
        'confirmed' => 'Подтверждена',
        'done' => 'Выполнена',
        'cancelled' => 'Отменена',
    ];
}

function pt_order_number(int $id): string
{
    return 'PT-' . $id;
}

function pt_order_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function pt_order_rate_limit(): int
{
    return max(1, (int) apply_filters('pt_order_rate_limit', 5));
}

function pt_order_rate_key(string $ip): string
{
    return 'pt_order_rate_' . md5($ip);
}

function pt_order_rate_limited(string $ip): bool
{
    $count = (int) get_transient(pt_order_rate_key($ip));
    return $count >= pt_order_rate_limit();
}

function pt_order_rate_register(string $ip): void
{
    $key = pt_order_rate_key($ip);
    set_transient($key, (int) get_transient($key) + 1, 3600);
}

function pt_order_setting(string $key): string
{
    if (!function_exists('carbon_get_post_meta') || !function_exists('pt_settings_id')) {
        return '';
    }
    $settings_id = (int) pt_settings_id();
    return $settings_id > 0 ? trim((string) carbon_get_post_meta($settings_id, $key)) : '';
}

/**
 * The current Moscow time, overridable from tests.
 */
function pt_order_now(): DateTimeImmutable
{
    $timezone = new DateTimeZone('Europe/Moscow');
    $now = apply_filters('pt_order_now', null);
    return $now instanceof DateTimeImmutable ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
}

function pt_order_text($value): string
{
    return sanitize_text_field(wp_unslash((string) $value));
}

function pt_order_bool($value): bool
{
    return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
}

/**
 * Normalises a Russian phone number to 11 digits (7XXXXXXXXXX).
 */
function pt_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    return preg_match('/^7[3-9]\d{9}$/', $digits) ? $digits : '';
}

function pt_format_phone(string $digits): string
{
    if (strlen($digits) !== 11) {
        return $digits;
    }
    return sprintf(
        '+%s (%s) %s-%s-%s',
        substr($digits, 0, 1),
        substr($digits, 1, 3),
        substr($digits, 4, 3),
        substr($digits, 7, 2),
        substr($digits, 9, 2)
    );
}

/**
 * Sanitises the request and resolves the selected records.
 *
 * @return array{data:array<string,mixed>,errors:array<string,string>,from:?WP_Post,to:?WP_Post,tariff:?WP_Post}
 */
function pt_order_prepare(array $input): array
{
    $timezone = new DateTimeZone('Europe/Moscow');
    $now = pt_order_now();

    $date = pt_order_text($input['date'] ?? '');
    $time = pt_order_text($input['time'] ?? '');
    $passengers = filter_var($input['passengers'] ?? null, FILTER_VALIDATE_INT);

    $data = [
        'name' => pt_order_text($input['name'] ?? ''),
        'phone' => pt_normalize_phone(pt_order_text($input['phone'] ?? '')),
        'comment' => trim(sanitize_textarea_field(wp_unslash((string) ($input['comment'] ?? '')))),
        'from' => trim((string) ($input['from'] ?? '')),
        'to' => trim((string) ($input['to'] ?? '')),
        'tariff' => trim((string) ($input['tariff'] ?? '')),
        'date' => $date,
        'time' => $time,
        'passengers' => $passengers === false ? 0 : $passengers,
        'round_trip' => pt_order_bool($input['round_trip'] ?? false),
        'extra_stop' => pt_order_bool($input['extra_stop'] ?? false),
        'child_seat' => pt_order_bool($input['child_seat'] ?? false),
        'distance_km' => null,
        'client_total' => null,
        'company' => pt_order_text($input['company'] ?? ''),
        'consent' => pt_order_bool($input['consent'] ?? false),
    ];

    if (isset($input['distance_km']) && is_numeric($input['distance_km'])) {
        $distance = (float) $input['distance_km'];
        if (is_finite($distance) && $distance > 0 && $distance <= 100000) {
            $data['distance_km'] = $distance;
        }
    }
    if (isset($input['total']) && is_numeric($input['total'])) {
        $client_total = (float) $input['total'];
        if (is_finite($client_total) && $client_total >= 0) {
            $data['client_total'] = (int) round($client_total);
        }
    }

    $from_post = $data['from'] !== '' ? pt_point_by_slug($data['from']) : null;
    $to_post = $data['to'] !== '' ? pt_point_by_slug($data['to']) : null;
    $tariff_post = $data['tariff'] !== '' ? pt_tariff_by_slug($data['tariff']) : null;

    $errors = [];

    if (mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Укажите имя (не меньше 2 символов)';
    }
    if ($data['phone'] === '') {
        $errors['phone'] = 'Укажите корректный телефон в формате +7 (XXX) XXX-XX-XX';
    }
    if (!$from_post) {
        $errors['from'] = 'Выберите пункт отправления из списка';
    }
    if (!$to_post) {
        $errors['to'] = 'Выберите пункт прибытия из списка';
    } elseif ($from_post && $from_post->ID === $to_post->ID) {
        $errors['to'] = 'Пункты отправления и прибытия должны различаться';
    }
    if (!$tariff_post) {
        $errors['tariff'] = 'Выберите тариф из списка';
    }

    $trip = null;
    $date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    if (!$date_object || $date_object->format('Y-m-d') !== $date) {
        $errors['date'] = 'Укажите дату поездки в формате ГГГГ-ММ-ДД';
        $date_object = null;
    } elseif ($date_object < $now->setTime(0, 0)) {
        $errors['date'] = 'Дата поездки уже прошла';
    }

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $errors['time'] = 'Укажите время поездки в формате ЧЧ:ММ';
    } elseif ($date_object) {
        $trip = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
        if (!$trip) {
            $errors['time'] = 'Укажите время поездки в формате ЧЧ:ММ';
        } elseif (!isset($errors['date']) && $date_object->format('Y-m-d') === $now->format('Y-m-d') && $trip <= $now->modify('+30 minutes')) {
            $errors['time'] = 'Выберите время не раньше чем через 30 минут';
        }
    }

    if ($data['passengers'] < 1) {
        $errors['passengers'] = 'Укажите количество пассажиров';
    } elseif ($tariff_post) {
        $seats = (int) (pt_tariff_payload($tariff_post)['seats'] ?? 0);
        if ($seats > 0 && $data['passengers'] > $seats) {
            $errors['passengers'] = 'Для выбранного тарифа максимум ' . $seats . ' пассажиров';
        }
    }

    if (!$data['consent']) {
        $errors['consent'] = 'Нужно согласие на обработку персональных данных';
    }
    if ($data['company'] !== '') {
        $errors['company'] = 'Заявка не отправлена. Попробуйте ещё раз.';
    }

    return [
        'data' => $data,
        'errors' => $errors,
        'from' => $from_post,
        'to' => $to_post,
        'tariff' => $tariff_post,
    ];
}

/**
 * Creates a pt_order record and sends its notification.
 *
 * @return array{id:int,number:string,total:?int,total_label:string}|WP_Error
 */
function pt_create_order(array $input): array|WP_Error
{
    $ip = pt_order_client_ip();
    if (pt_order_rate_limited($ip)) {
        return new WP_Error(
            'pt_order_rate_limited',
            'Слишком много заявок. Попробуйте позже или позвоните нам.',
            ['status' => 429]
        );
    }

    $prepared = pt_order_prepare($input);
    if ($prepared['errors']) {
        return new WP_Error(
            'pt_order_invalid',
            (string) reset($prepared['errors']),
            ['status' => 422, 'errors' => $prepared['errors']]
        );
    }

    /** @var WP_Post $from */
    $from = $prepared['from'];
    /** @var WP_Post $to */
    $to = $prepared['to'];
    /** @var WP_Post $tariff */
    $tariff = $prepared['tariff'];
    $data = $prepared['data'];

    $options = [
        'round_trip' => $data['round_trip'],
        'extra_stop' => $data['extra_stop'],
        'child_seat' => $data['child_seat'],
    ];
    if ($data['distance_km'] !== null) {
        $options['distance_km'] = $data['distance_km'];
    }
    $quote = pt_calculate_quote(pt_point_payload($from), pt_point_payload($to), pt_tariff_payload($tariff), $options);

    $total = is_int($quote['total']) ? $quote['total'] : null;
    $price = $total === null ? 'По запросу' : (string) $total;
    $title = sprintf(
        '%s · %s → %s · %s %s',
        $data['name'],
        $from->post_title,
        $to->post_title,
        $data['date'],
        $data['time']
    );

    $payload = [
        'name' => $data['name'],
        'phone' => $data['phone'],
        'comment' => $data['comment'],
        'from' => $data['from'],
        'to' => $data['to'],
        'tariff' => $data['tariff'],
        'date' => $data['date'],
        'time' => $data['time'],
        'passengers' => $data['passengers'],
        'round_trip' => $data['round_trip'],
        'extra_stop' => $data['extra_stop'],
        'child_seat' => $data['child_seat'],
        'distance_km' => $data['distance_km'],
        'total' => $data['client_total'],
        'consent' => true,
        'company' => '',
        'km_source' => $quote['km_source'],
        'server_total' => $total,
    ];

    $post_id = wp_insert_post([
        'post_type' => 'pt_order',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_content' => '',
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error(
            'pt_order_save_failed',
            'Не удалось сохранить заявку. Позвоните нам, мы поможем.',
            ['status' => 500]
        );
    }
    $post_id = (int) $post_id;

    update_post_meta($post_id, 'pt_phone', $data['phone']);
    update_post_meta($post_id, 'pt_from', $from->post_title);
    update_post_meta($post_id, 'pt_to', $to->post_title);
    update_post_meta($post_id, 'pt_tariff', $tariff->post_title);
    update_post_meta($post_id, 'pt_price', $price);
    update_post_meta($post_id, 'pt_when', $data['date'] . ' ' . $data['time']);
    update_post_meta($post_id, 'pt_payload', (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    update_post_meta($post_id, 'pt_status', 'new');
    update_post_meta($post_id, '_pt_km_source', (string) $quote['km_source']);
    if ($quote['km'] !== null) {
        update_post_meta($post_id, '_pt_distance_km', (string) $quote['km']);
    }
    if ($data['client_total'] !== null && $data['client_total'] !== $total) {
        update_post_meta($post_id, '_pt_client_total', (string) $data['client_total']);
    }

    pt_order_rate_register($ip);

    $order = get_post($post_id);
    if ($order instanceof WP_Post) {
        pt_notify_new_order($order);
    }

    return [
        'id' => $post_id,
        'number' => pt_order_number($post_id),
        'total' => $total,
        'total_label' => (string) $quote['total_label'],
    ];
}

/**
 * Builds the notification text once and dispatches it to the configured channels.
 */
function pt_notify_new_order(WP_Post $order): void
{
    if (!apply_filters('pt_order_notify_enabled', true)) {
        update_post_meta($order->ID, '_pt_notify_skipped', '1');
        return;
    }

    $channels = apply_filters('pt_order_notification_channels', ['telegram']);
    if (!is_array($channels)) {
        $channels = [];
    }

    $message = pt_order_notification_message($order);
    $errors = [];
    $notified = false;

    foreach (array_unique($channels) as $channel) {
        $channel = (string) $channel;
        if ($channel === 'telegram') {
            $error = pt_notify_telegram($order, $message);
            if ($error !== '') {
                $errors[] = $error;
            } else {
                $notified = true;
            }
        } else {
            do_action('pt_order_notify_' . $channel, $order, $message);
            $notified = true;
        }
    }

    if ($errors) {
        update_post_meta($order->ID, '_pt_notify_error', implode('; ', $errors));
    } else {
        delete_post_meta($order->ID, '_pt_notify_error');
        if ($notified) {
            update_post_meta($order->ID, '_pt_notified_at', gmdate('c'));
        }
    }
}

function pt_order_notification_message(WP_Post $order): string
{
    $payload = json_decode((string) get_post_meta($order->ID, 'pt_payload', true), true);
    $payload = is_array($payload) ? $payload : [];

    $price = (string) get_post_meta($order->ID, 'pt_price', true);
    $price_label = is_numeric($price) ? number_format((float) $price, 0, '', ' ') . ' ₽' : $price;

    $options = [];
    if (!empty($payload['round_trip'])) {
        $options[] = 'туда и обратно';
    }
    if (!empty($payload['extra_stop'])) {
        $options[] = 'дополнительная остановка';
    }
    if (!empty($payload['child_seat'])) {
        $options[] = 'детское кресло';
    }

    $lines = [
        '<b>Новая заявка ' . esc_html(pt_order_number($order->ID)) . '</b>',
        'Маршрут: ' . esc_html((string) get_post_meta($order->ID, 'pt_from', true)) . ' → ' . esc_html((string) get_post_meta($order->ID, 'pt_to', true)),
        'Дата и время: ' . esc_html((string) get_post_meta($order->ID, 'pt_when', true)),
        'Тариф: ' . esc_html((string) get_post_meta($order->ID, 'pt_tariff', true)),
        'Пассажиров: ' . esc_html((string) ($payload['passengers'] ?? '')),
        'Стоимость: ' . esc_html($price_label),
        'Имя: ' . esc_html((string) ($payload['name'] ?? '')),
        'Телефон: ' . esc_html(pt_format_phone((string) get_post_meta($order->ID, 'pt_phone', true))),
    ];
    if ($options) {
        $lines[] = 'Опции: ' . esc_html(implode(', ', $options));
    }
    if (trim((string) ($payload['comment'] ?? '')) !== '') {
        $lines[] = 'Комментарий: ' . esc_html((string) $payload['comment']);
    }

    return implode("\n", $lines);
}

/**
 * Sends one Telegram message. Returns an empty string on success or a stored error text.
 */
function pt_notify_telegram(WP_Post $order, string $message): string
{
    $token = pt_order_setting('pt_tg_bot_token');
    $chat_id = pt_order_setting('pt_tg_chat_id');
    if ($token === '' || $chat_id === '') {
        return 'Telegram: не заданы токен бота или chat id';
    }

    $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', [
        'timeout' => 15,
        'body' => [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ],
    ]);

    if (is_wp_error($response)) {
        return 'Telegram: ' . $response->get_error_message();
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || empty($body['ok'])) {
        $description = is_array($body) && !empty($body['description']) ? (string) $body['description'] : 'HTTP ' . $code;
        return 'Telegram: ' . $description;
    }

    return '';
}

/* ---------- admin list ---------- */

add_filter('manage_pt_order_posts_columns', function ($columns) {
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox" />',
        'title' => 'Заявка',
        'pt_when' => 'Дата и время',
        'pt_phone' => 'Телефон',
        'pt_route' => 'Маршрут',
        'pt_price' => 'Цена',
        'pt_status' => 'Статус',
        'pt_notify' => 'Уведомление',
    ];
});

add_action('manage_pt_order_posts_custom_column', function ($column, $post_id) {
    $post_id = (int) $post_id;
    if ($column === 'pt_when') {
        $value = (string) get_post_meta($post_id, 'pt_when', true);
        echo $value !== '' ? esc_html($value) : '—';
    } elseif ($column === 'pt_phone') {
        $phone = (string) get_post_meta($post_id, 'pt_phone', true);
        if ($phone === '') {
            echo '—';
            return;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        echo '<a href="tel:' . esc_attr($digits) . '">' . esc_html(pt_format_phone((string) $digits)) . '</a>';
    } elseif ($column === 'pt_route') {
        echo esc_html((string) get_post_meta($post_id, 'pt_from', true)) . ' → ' . esc_html((string) get_post_meta($post_id, 'pt_to', true));
    } elseif ($column === 'pt_price') {
        $price = (string) get_post_meta($post_id, 'pt_price', true);
        echo esc_html(is_numeric($price) ? number_format((float) $price, 0, '', ' ') . ' ₽' : $price);
    } elseif ($column === 'pt_status') {
        $status = (string) get_post_meta($post_id, 'pt_status', true);
        $statuses = pt_order_statuses();
        echo esc_html($statuses[$status] ?? $status);
    } elseif ($column === 'pt_notify') {
        $error = (string) get_post_meta($post_id, '_pt_notify_error', true);
        $notified = (string) get_post_meta($post_id, '_pt_notified_at', true);
        if ($error !== '') {
            echo '<span style="color:#b32d2e">Ошибка: ' . esc_html($error) . '</span>';
        } elseif ($notified !== '') {
            echo 'Отправлено';
        } else {
            echo '—';
        }
    }
}, 10, 2);

add_filter('manage_edit-pt_order_sortable_columns', function ($columns) {
    $columns['pt_when'] = 'pt_when';
    return $columns;
});

add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'pt_order') {
        return;
    }
    $current = isset($_GET['pt_status']) ? sanitize_key(wp_unslash((string) $_GET['pt_status'])) : '';
    echo '<select name="pt_status">';
    echo '<option value="">Все статусы</option>';
    foreach (pt_order_statuses() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'pt_order') {
        return;
    }

    if ($query->get('orderby') === 'pt_when') {
        $query->set('meta_key', 'pt_when');
        $query->set('orderby', 'meta_value');
    }

    $status = isset($_GET['pt_status']) ? sanitize_key(wp_unslash((string) $_GET['pt_status'])) : '';
    if ($status !== '' && isset(pt_order_statuses()[$status])) {
        $query->set('meta_query', [
            [
                'key' => 'pt_status',
                'value' => $status,
                'compare' => '=',
            ],
        ]);
    }
});
