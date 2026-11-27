<?php
define('ABSPATH', __DIR__);
define('PT_CORE_PATH', dirname(__DIR__) . '/wordpress/plugins/pt-core/');

class WP_Post
{
    public function __construct(
        public int $ID,
        public string $post_title,
        public string $post_type = 'pt_point',
        public string $post_status = 'publish',
        public string $post_name = ''
    ) {
    }
}

class WP_Error
{
    public function __construct(
        private string $code = '',
        private string $message = '',
        private mixed $data = null
    ) {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function add_action($name, $callback, ...$rest): void
{
    global $actions;
    $actions[$name] = $callback;
}

function add_filter($name, $callback, ...$rest): void
{
    global $filters;
    $filters[$name] = $callback;
}

function apply_filters($name, $value, ...$args): mixed
{
    global $filters;
    return isset($filters[$name]) ? $filters[$name]($value, ...$args) : $value;
}

function do_action($name, ...$args): void
{
    global $actions;
    if (isset($actions[$name])) {
        $actions[$name](...$args);
    }
}

function esc_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_attr($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sanitize_text_field($value): string
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value): string
{
    return trim(strip_tags((string) $value));
}

function sanitize_key($value): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}

function wp_unslash($value)
{
    return $value;
}

function wp_json_encode($value, $flags = 0): string
{
    return (string) json_encode($value, $flags);
}

function carbon_get_post_meta($id, $key)
{
    global $carbon;
    return $carbon[(int) $id][$key] ?? '';
}

function carbon_get_theme_option($key)
{
    global $theme_options;
    return $theme_options[$key] ?? '';
}

function pt_settings_id(): int
{
    return 900;
}

function pt_point_city_name($post): string
{
    return '';
}

function get_transient($key)
{
    global $transients;
    return $transients[$key] ?? false;
}

function set_transient($key, $value, $ttl = 0): bool
{
    global $transients;
    $transients[$key] = $value;
    return true;
}

function delete_transient($key): bool
{
    global $transients;
    unset($transients[$key]);
    return true;
}

function get_post_meta($id, $key, $single = false)
{
    global $meta;
    return $meta[(int) $id][$key] ?? '';
}

function update_post_meta($id, $key, $value): bool
{
    global $meta;
    $meta[(int) $id][$key] = $value;
    return true;
}

function delete_post_meta($id, $key): bool
{
    global $meta;
    unset($meta[(int) $id][$key]);
    return true;
}

function wp_insert_post($args, $wp_error = false)
{
    global $posts, $next_id;
    $id = $next_id++;
    $posts[$id] = new WP_Post($id, (string) $args['post_title'], (string) $args['post_type'], (string) $args['post_status']);
    return $id;
}

function get_post($id)
{
    global $posts;
    return $posts[(int) $id] ?? null;
}

function wp_get_post_terms($id, $taxonomy, $args = [])
{
    return [];
}

function get_post_thumbnail_id($post): int
{
    return 0;
}

function wp_get_attachment_image_url($id, $size): string
{
    return '';
}

function wp_get_attachment_url($id): string
{
    return '';
}

function wp_remote_post($url, $args = [])
{
    global $http_calls, $http_response;
    $http_calls[] = ['url' => $url, 'args' => $args];
    return $http_response;
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
}

$actions = [];
$filters = [];
$transients = [];
$posts = [];
$meta = [];
$carbon = [];
$theme_options = [];
$http_calls = [];
$http_response = ['response' => ['code' => 200], 'body' => '{"ok":true}'];
$next_id = 1;

$carbon[101] = ['pt_lat' => 45.0, 'pt_lon' => 34.0];
$carbon[102] = ['pt_lat' => 45.5, 'pt_lon' => 34.5];
$carbon[201] = ['pt_seats' => 4, 'pt_base' => 1000, 'pt_per_km' => 30, 'pt_min_price' => 1000];
$posts[101] = new WP_Post(101, 'Аэропорт', 'pt_point', 'publish', 'airport');
$posts[102] = new WP_Post(102, 'Ялта', 'pt_point', 'publish', 'yalta');
$posts[201] = new WP_Post(201, 'Комфорт', 'pt_tariff', 'publish', 'comfort');
$point_slugs = ['airport' => 101, 'yalta' => 102];

function pt_point_by_slug(string $slug): ?WP_Post
{
    global $posts, $point_slugs;
    $id = $point_slugs[$slug] ?? 0;
    return $id ? ($posts[$id] ?? null) : null;
}

function pt_tariff_by_slug(string $slug): ?WP_Post
{
    global $posts;
    return $slug === 'comfort' ? ($posts[201] ?? null) : null;
}

$theme_options['pt_route_overrides'] = [
    ['from_slug' => 'airport', 'to_slug' => 'yalta', 'km' => 50],
];

$filters['pt_order_now'] = static fn () => new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('Europe/Moscow'));

require PT_CORE_PATH . 'includes/calc.php';
require PT_CORE_PATH . 'includes/order.php';

function expect($condition, string $message): void
{
    static $checks = 0;
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    $GLOBALS['pt_checks'] = ++$checks;
}

function order_input(array $overrides = []): array
{
    return array_merge([
        'name' => 'Иван',
        'phone' => '+7 (978) 123-45-67',
        'comment' => 'Рейс SU 123',
        'from' => 'airport',
        'to' => 'yalta',
        'tariff' => 'comfort',
        'date' => '2026-06-16',
        'time' => '10:00',
        'passengers' => 2,
        'round_trip' => false,
        'extra_stop' => false,
        'child_seat' => false,
        'consent' => true,
        'company' => '',
    ], $overrides);
}

function reset_order_state(string $ip = '203.0.113.10'): void
{
    global $posts, $meta, $http_calls, $http_response, $transients;
    $_SERVER['REMOTE_ADDR'] = $ip;
    $transients = [];
    $http_calls = [];
    $http_response = ['response' => ['code' => 200], 'body' => '{"ok":true}'];
    foreach (array_keys($posts) as $id) {
        if ($id > 300) {
            unset($posts[$id]);
        }
    }
    foreach (array_keys($meta) as $id) {
        if ($id > 300) {
            unset($meta[$id]);
        }
    }
}

function errors_for(array $input): array
{
    $prepared = pt_order_prepare($input);
    return $prepared['errors'];
}

/* Accepted minimum and stored record */
reset_order_state();
$result = pt_create_order(order_input());
expect(is_array($result), 'A valid order must be accepted');
expect($result['number'] === 'PT-' . $result['id'], 'The order number must include the post ID');
$order = get_post($result['id']);
expect($order instanceof WP_Post, 'The order post must exist');
expect($order->post_title === 'Иван · Аэропорт → Ялта · 2026-06-16 10:00', 'The order title has the documented format');
expect($order->post_status === 'publish', 'Orders are stored as published records');
expect(get_post_meta($result['id'], 'pt_phone') === '79781234567', 'The phone must be stored as 11 digits');
expect(get_post_meta($result['id'], 'pt_status') === 'new', 'New orders start in the New status');
expect(get_post_meta($result['id'], 'pt_when') === '2026-06-16 10:00', 'Date and time must be combined');
expect(get_post_meta($result['id'], 'pt_from') === 'Аэропорт', 'The departure name must be stored');
expect(get_post_meta($result['id'], 'pt_price') === '2500', 'The override distance must be used without a client distance');
expect(get_post_meta($result['id'], '_pt_km_source') === 'override', 'The distance source must be recorded');
$payload = json_decode((string) get_post_meta($result['id'], 'pt_payload'), true);
expect(($payload['phone'] ?? '') === '79781234567', 'The payload must contain the normalised phone');
expect(($payload['from'] ?? '') === 'airport' && ($payload['to'] ?? '') === 'yalta', 'The payload must contain point slugs');
expect(($payload['comment'] ?? '') === 'Рейс SU 123', 'The payload must contain the comment');
expect(($payload['company'] ?? null) === '', 'The payload must keep an empty honeypot');

/* Validation messages */
reset_order_state();
expect((errors_for(order_input(['name' => 'Я']))['name'] ?? '') === 'Укажите имя (не меньше 2 символов)', 'Short names must be rejected');
expect(str_contains(errors_for(order_input(['phone' => '123']))['phone'] ?? '', 'телефон'), 'Bad phones must be rejected');
expect((errors_for(order_input(['date' => '2026-06-14']))['date'] ?? '') === 'Дата поездки уже прошла', 'Past dates must be rejected');
expect((errors_for(order_input(['date' => '2026-06-15', 'time' => '12:20']))['time'] ?? '') === 'Выберите время не раньше чем через 30 минут', 'Today times within 30 minutes must be rejected');
expect((errors_for(order_input(['date' => '2026-06-15', 'time' => '12:31']))['time'] ?? '') === '', 'Today times after the buffer must be accepted');
expect((errors_for(order_input(['to' => 'airport']))['to'] ?? '') === 'Пункты отправления и прибытия должны различаться', 'Equal points must be rejected');
expect((errors_for(order_input(['tariff' => 'missing']))['tariff'] ?? '') === 'Выберите тариф из списка', 'Unknown tariffs must be rejected');
expect((errors_for(order_input(['consent' => false]))['consent'] ?? '') === 'Нужно согласие на обработку персональных данных', 'Consent is required');
expect((errors_for(order_input(['company' => 'bot']))['company'] ?? '') !== '', 'A filled honeypot must be rejected');
expect((errors_for(order_input(['passengers' => 5]))['passengers'] ?? '') === 'Для выбранного тарифа максимум 4 пассажиров', 'Passengers above the tariff seats must be rejected');

/* Server price with and without the browser distance */
reset_order_state('203.0.113.11');
$priced = pt_create_order(order_input(['distance_km' => 100, 'total' => 999]));
expect(is_array($priced), 'An order with a client distance must be accepted');
expect(get_post_meta($priced['id'], 'pt_price') === '4000', 'The price must be recomputed from the supplied distance');
expect(get_post_meta($priced['id'], '_pt_km_source') === 'manual', 'A supplied distance must be recorded as manual');
expect(get_post_meta($priced['id'], '_pt_client_total') === '999', 'A differing client total must be stored');
$priced_payload = json_decode((string) get_post_meta($priced['id'], 'pt_payload'), true);
expect(($priced_payload['total'] ?? null) === 999, 'The client total must remain visible in the snapshot');

reset_order_state('203.0.113.18');
$timed = pt_create_order(order_input(['distance_km' => 100, 'duration_minutes' => 155]));
expect(is_array($timed), 'An order with a client duration must be accepted');
$timed_quote = json_decode((string) get_post_meta($timed['id'], '_pt_quote'), true);
expect(($timed_quote['minutes'] ?? 0) === 155, 'The client duration must be stored in the quote snapshot');
$timed_payload = json_decode((string) get_post_meta($timed['id'], 'pt_payload'), true);
expect(($timed_payload['duration_minutes'] ?? 0) === 155, 'The client duration must remain visible in the payload');

reset_order_state('203.0.113.12');
$priced = pt_create_order(order_input(['total' => 2500]));
expect(get_post_meta($priced['id'], 'pt_price') === '2500', 'Without a client distance the configured override must be used');
expect(get_post_meta($priced['id'], '_pt_client_total') === '', 'A matching client total must not be duplicated');

/* Per-IP rate limit */
reset_order_state('203.0.113.13');
for ($i = 0; $i < 5; $i++) {
    $created = pt_create_order(order_input());
    expect(is_array($created), 'The first five orders must be accepted');
}
$limited = pt_create_order(order_input());
expect(is_wp_error($limited), 'The sixth order must be rate limited');
expect($limited->get_error_data()['status'] === 429, 'Rate limiting must return HTTP 429');
expect(str_contains($limited->get_error_message(), 'Слишком много заявок'), 'Rate limiting must use a Russian message');

/* Telegram payload and escaping */
reset_order_state('203.0.113.14');
$carbon[900]['pt_tg_bot_token'] = 'token123';
$carbon[900]['pt_tg_chat_id'] = '-100500';
$posts[500] = new WP_Post(500, '<b>Иван</b>', 'pt_order', 'publish');
$meta[500] = [
    'pt_phone' => '79781234567',
    'pt_from' => 'Аэропорт',
    'pt_to' => '<i>Ялта</i>',
    'pt_tariff' => 'Комфорт',
    'pt_price' => '2500',
    'pt_when' => '2026-06-16 10:00',
    'pt_payload' => json_encode(['name' => '<b>Иван</b>', 'passengers' => 2, 'comment' => '<script>alert(1)</script>'], JSON_UNESCAPED_UNICODE),
];
pt_notify_new_order($posts[500]);
expect(count($http_calls) === 1, 'One Telegram request must be sent');
expect($http_calls[0]['url'] === 'https://api.telegram.org/bottoken123/sendMessage', 'The Telegram URL must contain the token');
expect(($http_calls[0]['args']['body']['parse_mode'] ?? '') === 'HTML', 'Telegram must use HTML parse mode');
$text = (string) ($http_calls[0]['args']['body']['text'] ?? '');
expect(str_contains($text, '&lt;b&gt;Иван&lt;/b&gt;'), 'The customer name must be escaped in the Telegram message');
expect(str_contains($text, '&lt;i&gt;Ялта&lt;/i&gt;'), 'Route names must be escaped in the Telegram message');
expect(str_contains($text, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Comments must be escaped in the Telegram message');
expect(str_contains($text, '2 500 ₽'), 'The stored price must be formatted in the message');
expect(get_post_meta(500, '_pt_notified_at') !== '', 'A delivered notification must be recorded');
expect(get_post_meta(500, '_pt_notify_error') === '', 'A delivered notification must not store an error');

/* MAX channel: the whole order as markdown, phone only, no admin link and no buttons */
$http_calls = [];
$carbon[900]['pt_max_bot_token'] = 'maxtoken';
$carbon[900]['pt_max_chat_id'] = '-19000000000001';
$posts[501] = new WP_Post(501, 'Новая заявка', 'pt_order', 'publish');
$meta[501] = [
    'pt_phone' => '79781234567',
    'pt_from' => 'Аэропорт',
    'pt_to' => 'Ялта',
    'pt_tariff' => 'Комфорт',
    'pt_price' => '2500',
    'pt_when' => '2026-06-16 10:00',
    'pt_payload' => json_encode(['name' => 'Иван', 'passengers' => 2, 'comment' => 'Рейс SU 123', 'child_seat' => true], JSON_UNESCAPED_UNICODE),
];
$filters['pt_order_notification_channels'] = static fn () => ['max'];
$http_response = ['response' => ['code' => 200], 'body' => '{"message":{"body":{"mid":"mid.1","seq":1,"text":"ok"},"timestamp":1795474270238}}'];
pt_notify_new_order($posts[501]);
expect(count($http_calls) === 1, 'The MAX channel must send exactly one request');
expect($http_calls[0]['url'] === 'https://botapi.max.ru/messages?chat_id=-19000000000001', 'The MAX URL must carry the group id');
expect(($http_calls[0]['args']['headers']['Authorization'] ?? '') === 'maxtoken', 'MAX must authenticate with the bot token header');
$max_body = json_decode((string) $http_calls[0]['args']['body'], true);
expect(($max_body['format'] ?? '') === 'markdown', 'MAX must use markdown formatting');
$max_text = (string) ($max_body['text'] ?? '');
expect(str_starts_with($max_text, '**Новая заявка'), 'The MAX title must be bold');
expect(str_contains($max_text, 'Маршрут: Аэропорт → Ялта'), 'MAX must carry the route');
expect(str_contains($max_text, 'Тариф: Комфорт'), 'MAX must carry the tariff');
expect(str_contains($max_text, 'Пассажиров: 2'), 'MAX must carry the passenger count');
expect(str_contains($max_text, 'Итого: 2 500 ₽'), 'MAX must carry the price');
expect(str_contains($max_text, 'Имя: Иван'), 'MAX must carry the customer name');
expect(str_contains($max_text, 'Телефон: +7 (978) 123-45-67'), 'MAX must carry the phone for a call');
expect(str_contains($max_text, 'Опции: детское кресло'), 'MAX must carry the options');
expect(str_contains($max_text, 'Комментарий: Рейс SU 123'), 'MAX must carry the comment');
expect(!str_contains($max_text, '&lt;'), 'MAX text must not be HTML-escaped');
expect(!str_contains($max_text, 'wp-admin'), 'The MAX message must not link to the admin');
expect(($max_body['attachments'][0]['type'] ?? '') === 'contact', 'MAX must attach the client phone as a contact card');
expect(str_contains((string) ($max_body['attachments'][0]['payload']['vcf_info'] ?? ''), 'TEL;TYPE=cell:79781234567'), 'The contact card must carry the phone in VCF');
expect(str_contains((string) ($max_body['attachments'][0]['payload']['vcf_info'] ?? ''), 'FN:Иван'), 'The contact card must carry the customer name');
expect(($max_body['attachments'][0]['payload']['buttons'] ?? null) === null, 'The contact card must not fake a keyboard button');
expect(get_post_meta(501, '_pt_notified_at') !== '', 'A delivered MAX notification must be recorded');

/* An unconfigured MAX channel stays silent and stores no error */
unset($carbon[900]['pt_max_bot_token'], $carbon[900]['pt_max_chat_id']);
$http_calls = [];
pt_notify_new_order($posts[501]);
expect(count($http_calls) === 0, 'An unconfigured MAX channel must not send anything');
expect(get_post_meta(501, '_pt_notify_error') === '', 'An unconfigured MAX channel must not store an error');

/* MAX API failures are stored like Telegram ones */
$carbon[900]['pt_max_bot_token'] = 'maxtoken';
$carbon[900]['pt_max_chat_id'] = '-19000000000001';
$http_response = ['response' => ['code' => 401], 'body' => '{"code":"verify.token","message":"Invalid access_token"}'];
pt_notify_new_order($posts[501]);
expect(str_contains((string) get_post_meta(501, '_pt_notify_error'), 'Invalid access_token'), 'MAX API errors must be stored');

unset($carbon[900]['pt_max_bot_token'], $carbon[900]['pt_max_chat_id'], $filters['pt_order_notification_channels']);
$http_response = ['response' => ['code' => 200], 'body' => '{"ok":true}'];

/* Notification failures keep the order */
$http_response = new WP_Error('http_request_failed', 'Таймаут соединения');
pt_notify_new_order($posts[500]);
expect(str_contains((string) get_post_meta(500, '_pt_notify_error'), 'Таймаут соединения'), 'Transport errors must be stored');
expect(get_post(500) instanceof WP_Post, 'A failed notification must keep the order');

$http_response = ['response' => ['code' => 400], 'body' => '{"ok":false,"description":"Bad Request: chat not found"}'];
pt_notify_new_order($posts[500]);
expect(str_contains((string) get_post_meta(500, '_pt_notify_error'), 'chat not found'), 'API errors must be stored');

$filters['pt_order_notify_enabled'] = static fn ($enabled) => false;
$http_calls = [];
$http_response = ['response' => ['code' => 200], 'body' => '{"ok":true}'];
pt_notify_new_order($posts[500]);
expect(count($http_calls) === 0, 'Disabled notifications must not call Telegram');
expect(get_post_meta(500, '_pt_notify_skipped') === '1', 'Disabled notifications must be recorded');
unset($filters['pt_order_notify_enabled']);

/* A real order carries the whole calculator input and its calculation */
reset_order_state('203.0.113.16');
$carbon[900]['pt_max_bot_token'] = 'maxtoken';
$carbon[900]['pt_max_chat_id'] = '-19000000000001';
$http_response = ['response' => ['code' => 200], 'body' => '{"message":{"body":{"mid":"mid.2"}}}'];
$filters['pt_order_notification_channels'] = static fn () => ['max'];
$created = pt_create_order(order_input(['child_seat' => true]));
expect(is_array($created), 'The order must be created before the notification check');
$max_body = json_decode((string) $http_calls[0]['args']['body'], true);
$max_text = (string) ($max_body['text'] ?? '');
expect(str_contains($max_text, 'Тариф: Комфорт — 1 000 ₽ база + 30 ₽/км'), 'MAX must carry the tariff with its price row');
expect(str_contains($max_text, 'Километраж: 50 км (ручные км маршрута)'), 'MAX must carry the distance and where it came from');
expect(str_contains($max_text, 'Расчёт по тарифу: 1 000 ₽ + 50 км × 30 ₽/км = 2 500 ₽'), 'MAX must carry the price formula');
expect(str_contains($max_text, 'Итого: 2 500 ₽'), 'MAX must carry the calculated total');
expect(str_contains($max_text, 'Пассажиров: 2'), 'MAX must carry the passenger count of the real order');
expect(str_contains($max_text, 'детское кресло'), 'MAX must carry the chosen options');
expect(str_contains($max_text, 'Дата и время: 16.06.2026 10:00'), 'The trip time must be rendered in Russian format');
expect(!str_contains($max_text, '(0 ₽)'), 'Options without a surcharge must not show a price');
unset($carbon[900]['pt_max_bot_token'], $carbon[900]['pt_max_chat_id'], $filters['pt_order_notification_channels']);

/* A route price overrides the tariff rate in the stored order and its notification */
$theme_options['pt_route_overrides'] = [
    ['from_slug' => 'airport', 'to_slug' => 'yalta', 'km' => 50, 'price_comfort' => 40],
];
reset_order_state('203.0.113.17');
$routed = pt_create_order(order_input());
expect(is_array($routed), 'An order on a priced route must be accepted');
expect(get_post_meta($routed['id'], 'pt_price') === '3000', 'The stored price must use the route price: 1 000 + 50 × 40');
$routed_quote = json_decode((string) get_post_meta($routed['id'], '_pt_quote'), true);
expect(($routed_quote['per_km'] ?? null) === 40 && ($routed_quote['tariff_per_km'] ?? null) === 30, 'The quote snapshot must keep both rates');
expect(($routed_quote['per_km_source'] ?? '') === 'route', 'The quote snapshot must record the route price');
$routed_text = pt_order_notification_markdown(get_post($routed['id']));
expect(str_contains($routed_text, 'Тариф: Комфорт — 1 000 ₽ база + 40 ₽/км (цена маршрута)'), 'The notification must show the route rate on the tariff row');
expect(str_contains($routed_text, 'Расчёт по маршруту: 1 000 ₽ + 50 км × 40 ₽/км = 3 000 ₽'), 'The notification formula must use the route rate');
expect(str_contains($routed_text, 'Итого: 3 000 ₽'), 'The notification total must match the stored price');
$theme_options['pt_route_overrides'] = [
    ['from_slug' => 'airport', 'to_slug' => 'yalta', 'km' => 50],
];

echo 'Order intake: ' . (int) ($GLOBALS['pt_checks'] ?? 0) . " checks passed\n";
