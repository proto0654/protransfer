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

    class WP_Term
    {
        public function __construct(public string $slug, public string $name) {}
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
    function pt_point_type_labels(): array
    {
        return [
            'city' => 'Город',
            'resort' => 'Курорт или посёлок',
            'airport' => 'Аэропорт',
            'rail' => 'Железнодорожный вокзал',
            'port' => 'Морской порт',
        ];
    }
    function get_posts(array $args): array
    {
        return $GLOBALS['route_points'] ?? [];
    }
    function wp_get_post_terms(int $id, string $taxonomy): array
    {
        return isset($GLOBALS['route_point_terms'][$id]) ? [$GLOBALS['route_point_terms'][$id]] : [];
    }

    require dirname(__DIR__) . '/wordpress/plugins/pt-core/includes/fields.php';

    function expect($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    $GLOBALS['route_points'] = [
        new WP_Post(1, 'Ялта', 'pt_point', 'publish', 'yalta'),
        new WP_Post(2, 'Аэропорт Симферополь', 'pt_point', 'publish', 'sip-air'),
        new WP_Post(3, 'Симферополь', 'pt_point', 'publish', 'sip'),
        new WP_Post(4, 'Севастополь', 'pt_point', 'publish', 'sev'),
        new WP_Post(5, 'Черновик', 'pt_point', 'draft', 'draft-point'),
        new WP_Post(6, 'Заозёрное', 'pt_point', 'publish', 'zaoz'),
    ];
    $GLOBALS['route_point_terms'] = [
        1 => new WP_Term('resort', 'Курорт или посёлок'),
        2 => new WP_Term('airport', 'Аэропорт'),
        3 => new WP_Term('city', 'Город'),
        4 => new WP_Term('city', 'Город'),
        5 => new WP_Term('city', 'Город'),
    ];

    $choices = pt_route_point_choices();
    expect(array_keys($choices) === ['sev', 'sip', 'yalta', 'sip-air', 'zaoz'], 'Published points must keep slug keys and be ordered by type then title');
    expect($choices['sip-air'] === 'Аэропорт Симферополь — аэропорт', 'Airport label must carry the title and the type');
    expect($choices['yalta'] === 'Ялта — курорт или посёлок', 'Resort label must carry the title and the type');
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
    expect(array_values(array_intersect(['sev', 'sip', 'yalta', 'sip-air', 'zaoz'], array_keys($options))) === ['sev', 'sip', 'yalta', 'sip-air', 'zaoz'], 'Every published point must be a select option');
    expect($options['sip-air'] === 'Аэропорт Симферополь — аэропорт', 'Published point options must use readable labels');
    expect($options['ghost'] === 'ghost — точка не найдена', 'An orphan slug must get an extra preserving option');
    expect(array_keys($options, 'ghost — точка не найдена', true) === ['ghost'], 'An orphan slug must be added only once');
    expect(!isset($options['draft-point']), 'Unpublished points must stay out of the select options');

    $empty = pt_route_point_options([['from_slug' => '', 'to_slug' => 'yalta', 'km' => '5']]);
    expect(array_keys($empty) === ['', 'sev', 'sip', 'yalta', 'sip-air', 'zaoz'], 'An empty slug must not create an orphan option');

    echo "Route point selects: 13 checks passed\n";
}
