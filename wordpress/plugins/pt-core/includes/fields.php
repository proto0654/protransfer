<?php
if (!defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

/**
 * Published points as route choices: slug => «Title — type».
 * Ordered by the canonical point-type order, then by title.
 *
 * @return array<string,string>
 */
function pt_route_point_choices(): array
{
    static $choices = null;
    if ($choices !== null) {
        return $choices;
    }

    $posts = get_posts([
        'post_type' => 'pt_point',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $type_labels = pt_point_type_labels();
    $type_order = array_flip(array_keys($type_labels));
    $unknown_type_order = count($type_order);
    $lower = static function (string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };

    $items = [];
    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'pt_point' || $post->post_status !== 'publish') {
            continue;
        }

        $slug = (string) $post->post_name;
        $title = trim((string) $post->post_title);
        if ($slug === '' || $title === '') {
            continue;
        }

        $type_slug = '';
        $type_label = '';
        $terms = wp_get_post_terms((int) $post->ID, 'pt_point_type');
        if (is_array($terms) && $terms && is_object($terms[0])) {
            $type_slug = isset($terms[0]->slug) ? (string) $terms[0]->slug : '';
            $type_label = isset($terms[0]->name) ? trim((string) $terms[0]->name) : '';
        }
        if ($type_label === '' && isset($type_labels[$type_slug])) {
            $type_label = $type_labels[$type_slug];
        }

        $type_label = $lower($type_label);
        $items[] = [
            'slug' => $slug,
            'label' => $type_label === '' ? $title : $title . ' — ' . $type_label,
            'sort' => [
                $type_order[$type_slug] ?? $unknown_type_order,
                $type_label,
                $lower($title),
                $title,
            ],
        ];
    }

    usort($items, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

    $options = [];
    foreach ($items as $item) {
        $options[$item['slug']] = $item['label'];
    }

    return $choices = $options;
}

/**
 * Select options for route rows. Keeps a stored slug that no published point
 * matches so opening and saving the screen does not silently erase a route.
 *
 * @param array<int,array<string,mixed>> $rows Stored complex rows.
 * @return array<string,string>
 */
function pt_route_point_options(array $rows = []): array
{
    $options = ['' => '— выберите точку —'];
    foreach (pt_route_point_choices() as $slug => $label) {
        $options[$slug] = $label;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (['from_slug', 'to_slug'] as $key) {
            $slug = trim((string) ($row[$key] ?? ''));
            if ($slug === '' || isset($options[$slug])) {
                continue;
            }
            $options[$slug] = $slug . ' — точка не найдена';
        }
    }

    return $options;
}

/**
 * Read stored complex rows without formatting child values: formatted values
 * would drop a slug that is not among the current select options.
 *
 * Carbon's value tree wraps every child value as a list of `['value' => …]`
 * nodes, so unwrap it here and also tolerate a plain string.
 *
 * When the tree is unavailable (for example after a Carbon upgrade changes its
 * internal API), fall back to the plain theme option so normal rows keep their
 * saved selections. Only orphaned codes that have no published point are lost
 * in that path. Results are cached per option because the select callbacks run
 * on every field render.
 *
 * @return array<int,array<string,string>>
 */
function pt_route_saved_rows(string $option): array
{
    static $cache = [];
    if (array_key_exists($option, $cache)) {
        return $cache[$option];
    }

    $rows = [];
    if (class_exists(\Carbon_Fields\Helper\Helper::class)) {
        $field = \Carbon_Fields\Helper\Helper::get_field_clone(null, 'theme_options', '', $option);
        if ($field && method_exists($field, 'get_value_tree')) {
            $read = static function ($node): string {
                if (is_array($node)) {
                    $first = reset($node);
                    if (is_array($first)) {
                        return is_scalar($first['value'] ?? null) ? (string) $first['value'] : '';
                    }
                    return is_scalar($first) ? (string) $first : '';
                }
                return is_scalar($node) ? (string) $node : '';
            };

            $field->load();
            foreach ((array) $field->get_value_tree() as $row) {
                if (is_array($row)) {
                    $rows[] = [
                        'from_slug' => $read($row['from_slug'] ?? null),
                        'to_slug' => $read($row['to_slug'] ?? null),
                        'km' => $read($row['km'] ?? null),
                    ];
                }
            }
        }
    }

    if (!$rows && function_exists('carbon_get_theme_option')) {
        $stored = carbon_get_theme_option($option);
        if (is_array($stored) && $stored) {
            $string = static fn ($value): string => is_scalar($value) ? (string) $value : '';
            foreach ($stored as $row) {
                if (is_array($row)) {
                    $rows[] = [
                        'from_slug' => $string($row['from_slug'] ?? ''),
                        'to_slug' => $string($row['to_slug'] ?? ''),
                        'km' => $string($row['km'] ?? ''),
                    ];
                }
            }
        }
    }

    return $cache[$option] = $rows;
}

add_action('carbon_fields_register_fields', function () {
    if (!class_exists(Container::class)) {
        return;
    }

    Container::make('post_meta', 'Точка')
        ->where('post_type', '=', 'pt_point')
        ->add_fields([
            Field::make('text', 'pt_lat', 'Широта')->set_attribute('type', 'number')->set_attribute('step', 'any')
                ->set_attribute('min', '-90')->set_attribute('max', '90'),
            Field::make('text', 'pt_lon', 'Долгота')->set_attribute('type', 'number')->set_attribute('step', 'any')
                ->set_attribute('min', '-180')->set_attribute('max', '180'),
            Field::make('complex', 'pt_aliases', 'Варианты поиска')
                ->setup_labels(['singular_name' => 'вариант', 'plural_name' => 'варианты'])
                ->add_fields([
                    Field::make('text', 'alias', 'Вариант названия')->set_width(100),
                ])
                ->set_header_template('<%- alias %>'),
        ]);

    Container::make('post_meta', 'Тариф')
        ->where('post_type', '=', 'pt_tariff')
        ->add_fields([
            Field::make('image', 'pt_photo', 'Фото авто')
                ->set_help_text('Или задайте «Изображение записи» слева — оно приоритетнее.'),
            Field::make('text', 'pt_seats', 'Мест')->set_attribute('type', 'number'),
            Field::make('text', 'pt_bags', 'Багаж')->set_attribute('type', 'number'),
            Field::make('text', 'pt_cars', 'Примеры авто'),
            Field::make('textarea', 'pt_description', 'Описание'),
            Field::make('text', 'pt_base', 'База, ₽')->set_attribute('type', 'number'),
            Field::make('text', 'pt_per_km', '₽ / км')->set_attribute('type', 'number'),
            Field::make('text', 'pt_min_price', 'Минималка, ₽')->set_attribute('type', 'number'),
            Field::make('text', 'pt_tag', 'Бейдж'),
            Field::make('checkbox', 'pt_on_request', 'Цена по запросу'),
        ]);

    Container::make('post_meta', 'Вопросы и ответы')
        ->where('post_type', '=', 'pt_faq')
        ->add_fields([
            Field::make('textarea', 'pt_answer', 'Ответ'),
        ]);

    Container::make('post_meta', 'Отзыв')
        ->where('post_type', '=', 'pt_review')
        ->add_fields([
            Field::make('text', 'pt_city', 'Город'),
            Field::make('text', 'pt_route', 'Маршрут'),
            Field::make('text', 'pt_rating', 'Оценка')->set_attribute('type', 'number')->set_attribute('min', '1')->set_attribute('max', '5'),
            Field::make('text', 'pt_date_label', 'Подпись даты'),
            Field::make('textarea', 'pt_text', 'Текст'),
        ]);

    Container::make('post_meta', 'Заявка')
        ->where('post_type', '=', 'pt_order')
        ->add_fields([
            Field::make('text', 'pt_phone', 'Телефон'),
            Field::make('text', 'pt_from', 'Откуда'),
            Field::make('text', 'pt_to', 'Куда'),
            Field::make('text', 'pt_tariff', 'Тариф'),
            Field::make('text', 'pt_price', 'Цена'),
            Field::make('text', 'pt_when', 'Дата/время'),
            Field::make('textarea', 'pt_payload', 'Снимок данных заявки'),
            Field::make('select', 'pt_status', 'Статус')
                ->add_options([
                    'new' => 'Новая',
                    'confirmed' => 'Подтверждена',
                    'done' => 'Выполнена',
                    'cancelled' => 'Отменена',
                ]),
        ]);

    Container::make('post_meta', 'Контакты и контент')
        ->where('post_type', '=', 'pt_settings')
        ->add_fields([
            Field::make('text', 'pt_brand', 'Бренд'),
            Field::make('text', 'pt_tagline', 'Слоган'),
            Field::make('text', 'pt_phone', 'Телефон'),
            Field::make('text', 'pt_phone_href', 'Телефон (ссылка)'),
            Field::make('text', 'pt_telegram', 'Telegram'),
            Field::make('text', 'pt_max', 'Ссылка MAX'),
            Field::make('text', 'pt_email', 'Email'),
            Field::make('text', 'pt_address', 'Адрес'),
            Field::make('text', 'pt_hours', 'Часы работы'),
            Field::make('text', 'pt_map_api_key', 'Ключ API Яндекс Карт'),
            Field::make('text', 'pt_tg_bot_token', 'Telegram: токен бота'),
            Field::make('text', 'pt_tg_chat_id', 'Telegram: chat id'),
            Field::make('text', 'pt_max_bot_token', 'MAX: токен бота'),
            Field::make('text', 'pt_max_chat_id', 'MAX: chat id группы'),
            Field::make('text', 'pt_rating', 'Рейтинг'),
            Field::make('text', 'pt_reviews_count', 'Число отзывов'),
            Field::make('complex', 'pt_stats', 'Статистика')
                ->setup_labels(['singular_name' => 'показатель', 'plural_name' => 'показатели'])
                ->add_fields([
                    Field::make('text', 'stat_value', 'Значение')->set_width(40),
                    Field::make('text', 'label', 'Подпись')->set_width(60),
                ])
                ->set_header_template('<%- stat_value %> — <%- label %>'),
            Field::make('complex', 'pt_advantages', 'Преимущества')
                ->setup_labels(['singular_name' => 'преимущество', 'plural_name' => 'преимущества'])
                ->add_fields([
                    Field::make('text', 'icon', 'Иконка')->set_width(20),
                    Field::make('text', 'title', 'Заголовок')->set_width(30),
                    Field::make('textarea', 'text', 'Текст')->set_width(50),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_steps', 'Шаги')
                ->setup_labels(['singular_name' => 'шаг', 'plural_name' => 'шаги'])
                ->add_fields([
                    Field::make('text', 'title', 'Заголовок')->set_width(40),
                    Field::make('textarea', 'text', 'Текст')->set_width(60),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_services', 'Услуги')
                ->setup_labels(['singular_name' => 'услуга', 'plural_name' => 'услуги'])
                ->add_fields([
                    Field::make('text', 'icon', 'Иконка')->set_width(20),
                    Field::make('text', 'title', 'Заголовок')->set_width(30),
                    Field::make('textarea', 'text', 'Текст')->set_width(50),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_payments', 'Оплата')
                ->setup_labels(['singular_name' => 'способ оплаты', 'plural_name' => 'способы оплаты'])
                ->add_fields([
                    Field::make('text', 'label', 'Способ'),
                ])
                ->set_header_template('<%- label %>'),
        ]);

    $content_groups = [];
    $group_labels = [
        'advantages' => 'Преимущества', 'tariffs' => 'Автопарк', 'popular_routes' => 'Направления',
        'how_it_works' => 'Как это работает', 'services' => 'Услуги', 'reviews' => 'Отзывы',
        'faq' => 'Вопросы и ответы', 'contacts' => 'Контакты', 'contacts_/_cta' => 'Контакты и связь', 'footer' => 'Подвал',
        'navigation' => 'Навигация', 'calculator' => 'Калькулятор', 'booking' => 'Бронирование',
    ];
    foreach (pt_landing_schema() as $key => $definition) {
        $group = $group_labels[$definition['group']] ?? $definition['group'];
        $content_groups[$group][] = Field::make($definition['type'] ?? 'textarea', 'pt_copy_' . $key, $definition['label'])
            ->set_default_value($definition['default']);
    }
    foreach ($content_groups as $group => $fields) {
        Container::make('post_meta', 'Лендинг: ' . $group)
            ->where('post_type', '=', 'pt_settings')
            ->add_fields($fields);
    }
    Container::make('post_meta', 'Поисковые системы и обложка')
        ->where('post_type', '=', 'pt_settings')
        ->add_fields([
            Field::make('text', 'pt_seo_title', 'Заголовок страницы для поисковых систем'),
            Field::make('textarea', 'pt_seo_description', 'Описание страницы для поисковых систем'),
            Field::make('image', 'pt_hero_image', 'Фоновое изображение первого экрана'),
        ]);

    $calc_defaults = pt_calc_defaults();
    Container::make('theme_options', 'Калькулятор')
        ->set_page_parent('edit.php?post_type=pt_tariff')
        ->set_page_file('pt-calculator')
        ->add_fields([
            Field::make('text', 'pt_road_factor', 'Коэффициент дороги для оценки по координатам')
                ->set_default_value((string) $calc_defaults['road_factor'])
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.01'),
            Field::make('text', 'pt_km_round', 'Округление км')
                ->set_default_value((string) $calc_defaults['km_round'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_short', 'Скорость короткого маршрута')
                ->set_default_value((string) $calc_defaults['speed_short'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_mid', 'Скорость среднего маршрута')
                ->set_default_value((string) $calc_defaults['speed_mid'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_long', 'Скорость длинного маршрута')
                ->set_default_value((string) $calc_defaults['speed_long'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_mid_threshold', 'Граница короткого маршрута, км')->set_default_value((string) $calc_defaults['speed_mid_threshold'])->set_attribute('type', 'number')->set_attribute('min', '0'),
            Field::make('text', 'pt_speed_long_threshold', 'Граница длинного маршрута, км')->set_default_value((string) $calc_defaults['speed_long_threshold'])->set_attribute('type', 'number')->set_attribute('min', '0'),
            Field::make('text', 'pt_time_buffer', 'Надбавка времени, мин')
                ->set_default_value((string) $calc_defaults['time_buffer'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_time_round', 'Округление времени, мин')
                ->set_default_value((string) $calc_defaults['time_round'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_round_trip_factor', 'Коэффициент туда-обратно')
                ->set_default_value((string) $calc_defaults['round_trip_factor'])
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.01'),
            Field::make('text', 'pt_extra_stop_price', 'Доп. остановка, ₽')
                ->set_default_value((string) $calc_defaults['extra_stop_price'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_price_round', 'Округление цены')
                ->set_default_value((string) $calc_defaults['price_round'])
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_child_seat_price', 'Детское кресло, ₽ (0 — бесплатно)')->set_default_value((string) $calc_defaults['child_seat_price'])->set_attribute('type', 'number')->set_attribute('min', '0'),
        ]);

    $route_tariffs = [];
    foreach (get_posts(['post_type' => 'pt_tariff', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC']) as $tariff) {
        $route_tariffs[$tariff->post_name] = $tariff->post_title;
    }

    // Options are resolved when the field renders, after all fields are registered,
    // so stored rows can be read through Carbon's own datastore.
    $route_options = static function (string $option): callable {
        return static fn (): array => pt_route_point_options(pt_route_saved_rows($option));
    };
    $route_help = 'Переименование точки не меняет её код, поэтому маршрут продолжит работать. Если изменить код (slug) точки, сохранённые маршруты сломаются. «точка не найдена» — сохранённый код без опубликованной точки.';

    Container::make('theme_options', 'Маршруты')
        ->set_page_parent('edit.php?post_type=pt_point')
        ->set_page_file('pt-routes')
        ->add_fields([
            Field::make('select', 'pt_popular_tariff', 'Тариф для цен популярных направлений')->add_options($route_tariffs),
            Field::make('complex', 'pt_popular_routes', 'Популярные направления')
                ->setup_labels(['singular_name' => 'направление', 'plural_name' => 'направления'])
                ->set_help_text($route_help)
                ->add_fields([
                    Field::make('select', 'from_slug', 'Откуда')->add_options($route_options('pt_popular_routes'))
                        ->set_width(50),
                    Field::make('select', 'to_slug', 'Куда')->add_options($route_options('pt_popular_routes'))
                        ->set_width(50),
                ])
                ->set_header_template('<%- from_slug %> → <%- to_slug %>'),
            Field::make('complex', 'pt_route_overrides', 'Ручные км')
                ->setup_labels(['singular_name' => 'маршрут', 'plural_name' => 'маршруты'])
                ->set_help_text($route_help)
                ->add_fields([
                    Field::make('select', 'from_slug', 'Откуда')->add_options($route_options('pt_route_overrides'))
                        ->set_width(35),
                    Field::make('select', 'to_slug', 'Куда')->add_options($route_options('pt_route_overrides'))
                        ->set_width(35),
                    Field::make('text', 'km', 'км')->set_width(30)->set_attribute('type', 'number'),
                ])
                ->set_header_template('<%- from_slug %> → <%- to_slug %>: <%- km %> км'),
        ]);
});

// Carbon puts the readable labels only in the select options, so replace the
// code-based row header on the routes screen with the selected point names.
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'pt_point_page_pt-routes') {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'pt_point_page_pt-routes') {
        return;
    }
    wp_enqueue_script('pt-admin-routes', PT_CORE_URL . 'includes/admin-routes.js', [], PT_CORE_VERSION, true);
});
