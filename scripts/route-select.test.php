<?php
namespace Carbon_Fields\Helper {
    class Helper
    {
        public static function get_field_clone($object_id, $container_type, $container_id, $field_name)
        {
            return $GLOBALS['route_field'] ?? null;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__);

    class WP_Post
    {
        public function __construct(
            public int $ID,
            public string $post_title,
            public string $post_type = 'pt_point',
            public string $post_status = 'publish',
            public string $post_name = ''
        ) {}
    }

    class Route_Field_Stub
    {
        public function load(): void
        {
            $GLOBALS['route_field_loaded'] = true;
        }

        public function get_value_tree(): array
        {
            return $GLOBALS['route_field_tree'] ?? [];
        }
    }

    function add_action(...$args) {}
    function carbon_get_theme_option($option)
    {
        return $GLOBALS['route_theme_options'][$option] ?? [];
    }
    function get_posts(array $args): array
    {
        return ($args['post_type'] ?? '') === 'pt_tariff'
            ? ($GLOBALS['route_tariffs'] ?? [])
            : ($GLOBALS['route_points'] ?? []);
    }

    // fields.php builds the route price fields through calc.php's key helper.
    require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/calc.php';
    require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/fields.php';

    function expect($condition, string $message): void
    {
        static $checks = 0;
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
        $GLOBALS['pt_checks'] = ++$checks;
    }

    $GLOBALS['route_points'] = [
        new WP_Post(1, 'Ялта', 'pt_point', 'publish', 'yalta'),
        new WP_Post(2, 'Аэропорт Симферополь', 'pt_point', 'publish', 'sip-air'),
        new WP_Post(3, 'Симферополь', 'pt_point', 'publish', 'sip'),
        new WP_Post(4, 'Севастополь', 'pt_point', 'publish', 'sev'),
        new WP_Post(5, 'Черновик', 'pt_point', 'draft', 'draft-point'),
        new WP_Post(6, 'Заозёрное', 'pt_point', 'publish', 'zaoz'),
    ];

    $choices = pt_route_point_choices();
    expect(array_keys($choices) === ['sip-air', 'zaoz', 'sev', 'sip', 'yalta'], 'Published points must keep slug keys and be ordered by title');
    expect($choices['sip-air'] === 'Аэропорт Симферополь', 'The label must be the point title without a type');
    expect($choices['yalta'] === 'Ялта', 'A point title must not carry a type suffix');
    expect(strpos($choices['sip'], 'sip') === false, 'Labels must not contain the raw slug');
    expect(!isset($choices['draft-point']), 'Draft points must be excluded');

    $GLOBALS['route_field'] = new Route_Field_Stub();
    // Carbon returns each child value wrapped as a list of ['value' => …] nodes;
    // the plain string in the second row checks the tolerant path.
    $GLOBALS['route_field_tree'] = [
        ['from_slug' => [['value' => 'sip-air']], 'to_slug' => [['value' => 'ghost']], 'km' => [['value' => '50']], 'value' => '_'],
        ['from_slug' => 'ghost', 'to_slug' => [['value' => 'yalta']], 'km' => [['value' => '10']], 'value' => '_'],
    ];
    $stored = pt_route_saved_rows('pt_route_overrides');
    expect($stored === [
        ['from_slug' => 'sip-air', 'to_slug' => 'ghost', 'km' => '50'],
        ['from_slug' => 'ghost', 'to_slug' => 'yalta', 'km' => '10'],
    ], 'Stored rows must unwrap Carbon value nodes without dropping unknown slugs');

    $options = pt_route_point_options($stored);
    expect($options[''] === '— выберите точку —', 'The empty «not chosen» option must come first');
    expect(array_values(array_intersect(['sip-air', 'zaoz', 'sev', 'sip', 'yalta'], array_keys($options))) === ['sip-air', 'zaoz', 'sev', 'sip', 'yalta'], 'Every published point must be a select option');
    expect($options['sip-air'] === 'Аэропорт Симферополь', 'Published point options must use readable titles');
    expect($options['ghost'] === 'ghost — точка не найдена', 'An orphan slug must get an extra preserving option');
    expect(array_keys($options, 'ghost — точка не найдена', true) === ['ghost'], 'An orphan slug must be added only once');
    expect(!isset($options['draft-point']), 'Unpublished points must stay out of the select options');

    $empty = pt_route_point_options([['from_slug' => '', 'to_slug' => 'yalta', 'km' => '5']]);
    expect(array_keys($empty) === ['', 'sip-air', 'zaoz', 'sev', 'sip', 'yalta'], 'An empty slug must not create an orphan option');

    // Fallback: Carbon's tree API is unavailable, but the plain option still has rows.
    $GLOBALS['route_field'] = null;
    $GLOBALS['route_theme_options']['pt_fallback_routes'] = [
        ['from_slug' => 'sip-air', 'to_slug' => 'yalta', 'km' => 50],
    ];
    $fallback = pt_route_saved_rows('pt_fallback_routes');
    expect($fallback === [
        ['from_slug' => 'sip-air', 'to_slug' => 'yalta', 'km' => '50'],
    ], 'An unavailable value tree must fall back to the plain theme option with string fields');

    // Cache: the select callbacks render many times, so read each option once.
    $GLOBALS['route_field'] = new Route_Field_Stub();
    $GLOBALS['route_field_tree'] = [
        ['from_slug' => [['value' => 'sip']], 'to_slug' => [['value' => 'yalta']], 'km' => [['value' => '15']]],
    ];
    $cached = pt_route_saved_rows('pt_cache_a');
    $GLOBALS['route_field_tree'] = [
        ['from_slug' => [['value' => 'ghost']], 'to_slug' => [['value' => 'sev']], 'km' => [['value' => '99']]],
    ];
    expect(pt_route_saved_rows('pt_cache_a') === $cached, 'Repeated reads of one option must use the cached rows');
    $other = pt_route_saved_rows('pt_cache_b');
    expect($other !== $cached && $other[0]['from_slug'] === 'ghost', 'Another option must read and cache its own rows');

    // Price fields are keyed by tariff slug so a saved value survives a title change.
    $GLOBALS['route_tariffs'] = [
        new WP_Post(11, 'Эконом', 'pt_tariff', 'publish', 'economy'),
        new WP_Post(12, 'Комфорт', 'pt_tariff', 'publish', 'comfort'),
        new WP_Post(13, 'Комфорт Плюс', 'pt_tariff', 'publish', 'comfort-pl'),
        new WP_Post(14, 'Черновик', 'pt_tariff', 'draft', 'draft-tariff'),
    ];
    $price_choices = pt_route_price_choices();
    expect(array_keys($price_choices) === ['price_economy', 'price_comfort', 'price_comfort_pl'], 'Only published tariffs must get a price field, with normalised keys');
    expect($price_choices['price_comfort_pl'] === 'Комфорт Плюс', 'A price field must be labelled with its tariff title');
    expect(!isset($price_choices['price_draft_tariff']), 'A draft tariff must not get a price field');

    echo "Route point selects: {$GLOBALS['pt_checks']} checks passed\n";
}
