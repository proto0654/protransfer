<?php
if (!defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', function () {
    if (!class_exists(Container::class)) {
        return;
    }

    Container::make('post_meta', 'Точка')
        ->where('post_type', '=', 'pt_point')
        ->add_fields([
            Field::make('text', 'pt_city', 'Город'),
            Field::make('text', 'pt_lat', 'Широта')->set_attribute('type', 'number')->set_attribute('step', 'any'),
            Field::make('text', 'pt_lon', 'Долгота')->set_attribute('type', 'number')->set_attribute('step', 'any'),
            Field::make('complex', 'pt_aliases', 'Алиасы поиска')
                ->add_fields([
                    Field::make('text', 'alias', 'Алиас')->set_width(100),
                ])
                ->set_header_template('<%- alias %>'),
        ]);

    Container::make('post_meta', 'Тариф')
        ->where('post_type', '=', 'pt_tariff')
        ->add_fields([
            Field::make('image', 'pt_photo', 'Фото'),
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

    Container::make('post_meta', 'FAQ')
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
            Field::make('textarea', 'pt_payload', 'Снимок JSON'),
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
            Field::make('text', 'pt_phone_href', 'Телефон (href)'),
            Field::make('text', 'pt_whatsapp', 'WhatsApp'),
            Field::make('text', 'pt_telegram', 'Telegram'),
            Field::make('text', 'pt_max', 'MAX URL'),
            Field::make('text', 'pt_email', 'Email'),
            Field::make('text', 'pt_address', 'Адрес'),
            Field::make('text', 'pt_hours', 'Часы работы'),
            Field::make('text', 'pt_map_api_key', 'Yandex Maps API key'),
            Field::make('text', 'pt_rating', 'Рейтинг'),
            Field::make('text', 'pt_reviews_count', 'Число отзывов'),
            Field::make('complex', 'pt_stats', 'Статистика')
                ->add_fields([
                    Field::make('text', 'stat_value', 'Значение')->set_width(40),
                    Field::make('text', 'label', 'Подпись')->set_width(60),
                ])
                ->set_header_template('<%- stat_value %> — <%- label %>'),
            Field::make('complex', 'pt_advantages', 'Преимущества')
                ->add_fields([
                    Field::make('text', 'icon', 'Иконка')->set_width(20),
                    Field::make('text', 'title', 'Заголовок')->set_width(30),
                    Field::make('textarea', 'text', 'Текст')->set_width(50),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_steps', 'Шаги')
                ->add_fields([
                    Field::make('text', 'title', 'Заголовок')->set_width(40),
                    Field::make('textarea', 'text', 'Текст')->set_width(60),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_services', 'Услуги')
                ->add_fields([
                    Field::make('text', 'icon', 'Иконка')->set_width(20),
                    Field::make('text', 'title', 'Заголовок')->set_width(30),
                    Field::make('textarea', 'text', 'Текст')->set_width(50),
                ])
                ->set_header_template('<%- title %>'),
            Field::make('complex', 'pt_payments', 'Оплата')
                ->add_fields([
                    Field::make('text', 'label', 'Способ'),
                ])
                ->set_header_template('<%- label %>'),
        ]);

    Container::make('theme_options', 'Калькулятор')
        ->set_page_parent('edit.php?post_type=pt_tariff')
        ->set_page_file('pt-calculator')
        ->add_fields([
            Field::make('text', 'pt_road_factor', 'Коэффициент дороги (haversine)')
                ->set_default_value('1.35')
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.01'),
            Field::make('text', 'pt_km_round', 'Округление км')
                ->set_default_value('5')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_short', 'Скорость ≤50 км')
                ->set_default_value('50')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_mid', 'Скорость 50–150 км')
                ->set_default_value('68')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_speed_long', 'Скорость >150 км')
                ->set_default_value('82')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_time_buffer', 'Надбавка времени, мин')
                ->set_default_value('10')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_time_round', 'Округление времени, мин')
                ->set_default_value('5')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_round_trip_factor', 'Коэффициент туда-обратно')
                ->set_default_value('1.9')
                ->set_attribute('type', 'number')
                ->set_attribute('step', '0.01'),
            Field::make('text', 'pt_extra_stop_price', 'Доп. остановка, ₽')
                ->set_default_value('500')
                ->set_attribute('type', 'number'),
            Field::make('text', 'pt_price_round', 'Округление цены')
                ->set_default_value('10')
                ->set_attribute('type', 'number'),
            Field::make('checkbox', 'pt_child_seat_free', 'Детское кресло бесплатно')
                ->set_default_value(true),
        ]);

    Container::make('theme_options', 'Маршруты')
        ->set_page_parent('edit.php?post_type=pt_point')
        ->set_page_file('pt-routes')
        ->add_fields([
            Field::make('complex', 'pt_popular_routes', 'Популярные направления')
                ->add_fields([
                    Field::make('text', 'from_slug', 'Откуда (slug)')->set_width(50),
                    Field::make('text', 'to_slug', 'Куда (slug)')->set_width(50),
                ])
                ->set_header_template('<%- from_slug %> → <%- to_slug %>'),
            Field::make('complex', 'pt_route_overrides', 'Ручные км')
                ->add_fields([
                    Field::make('text', 'from_slug', 'Откуда (slug)')->set_width(35),
                    Field::make('text', 'to_slug', 'Куда (slug)')->set_width(35),
                    Field::make('text', 'km', 'км')->set_width(30)->set_attribute('type', 'number'),
                ])
                ->set_header_template('<%- from_slug %> → <%- to_slug %>: <%- km %> км'),
        ]);
});
