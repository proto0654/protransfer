<?php
if (!defined('ABSPATH')) {
    exit;
}

const PT_PARENT_POINT_META = '_pt_parent_point';

function pt_point_type_labels(): array
{
    return ['city' => 'Город', 'resort' => 'Курорт или посёлок', 'airport' => 'Аэропорт', 'rail' => 'Железнодорожный вокзал', 'port' => 'Морской порт'];
}
/** Resolve a unique matching settlement; ambiguous names stay unlinked. */
function pt_unique_settlement_id(string $name, int $point_id, array $settlements): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $needle = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    $matches = [];
    foreach ($settlements as $settlement) {
        $title = trim($settlement->post_title);
        $title = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
        if ((int) $settlement->ID !== $point_id && $title === $needle) {
            $matches[] = (int) $settlement->ID;
        }
    }
    return count($matches) === 1 ? $matches[0] : 0;
}

/** Reject self-links and cycles, including a proposed batch of links. */
function pt_parent_would_cycle(int $point_id, int $parent_id, array $planned = []): bool
{
    $seen = [$point_id => true];
    while ($parent_id > 0) {
        if (isset($seen[$parent_id])) return true;
        $seen[$parent_id] = true;
        $parent_id = (int) (array_key_exists($parent_id, $planned)
            ? $planned[$parent_id]
            : get_post_meta($parent_id, PT_PARENT_POINT_META, true));
    }
    return false;
}

/**
 * Published points that can be a parent: top-level records only. A point that
 * is linked to a settlement (a station, an airport, a district) is inside it.
 *
 * @return WP_Post[]
 */
function pt_settlement_points(): array
{
    $posts = get_posts([
        'post_type' => 'pt_point', 'post_status' => 'publish', 'numberposts' => -1,
        'orderby' => 'title', 'order' => 'ASC',
    ]);

    return array_values(array_filter(
        is_array($posts) ? $posts : [],
        static fn ($post): bool => pt_is_published_settlement($post instanceof WP_Post ? $post : null)
    ));
}

/** A point without a parent link is top-level; a linked point belongs to one. */
function pt_point_is_top_level(WP_Post $post): bool
{
    if (!metadata_exists('post', (int) $post->ID, PT_PARENT_POINT_META)) {
        return true;
    }
    return (int) get_post_meta((int) $post->ID, PT_PARENT_POINT_META, true) <= 0;
}

function pt_is_published_settlement(?WP_Post $post): bool
{
    return $post && $post->post_type === 'pt_point' && $post->post_status === 'publish'
        && pt_point_is_top_level($post);
}

/** Preserve old city text until a relationship is explicitly set. */
function pt_point_city_name(WP_Post $point): string
{
    $legacy = function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($point->ID, 'pt_city') : '';
    if (!metadata_exists('post', $point->ID, PT_PARENT_POINT_META)) {
        return $legacy;
    }
    $parent_id = (int) get_post_meta($point->ID, PT_PARENT_POINT_META, true);
    if ($parent_id === 0) {
        return '';
    }
    $parent = get_post($parent_id);
    return pt_is_published_settlement($parent) ? $parent->post_title : $legacy;
}

add_action('add_meta_boxes_pt_point', function () {
    add_meta_box('pt-point-location', 'Расположение и принадлежность', 'pt_render_point_location', 'pt_point', 'normal', 'high');
});

function pt_render_point_location(WP_Post $post): void
{
    wp_nonce_field('pt_save_point_location', 'pt_point_location_nonce');
    $settlements = pt_settlement_points();
    $has_link = metadata_exists('post', $post->ID, PT_PARENT_POINT_META);
    $parent_id = $has_link ? (int) get_post_meta($post->ID, PT_PARENT_POINT_META, true) : -1;
    $legacy = function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($post->ID, 'pt_city') : '';
    $allowed_ids = array_map(static fn ($p) => (int) $p->ID, $settlements);
    ?>
    <p><label for="pt-parent-point"><strong>Относится к населённому пункту</strong></label></p>
    <p><select name="pt_parent_point" id="pt-parent-point">
      <?php if (!$has_link) : ?>
        <option value="-1" selected><?php echo esc_html($legacy !== '' ? 'Сохранить прежнее значение: ' . $legacy : 'Не указано'); ?></option>
      <?php elseif ($parent_id > 0 && ($parent_id === (int) $post->ID || !in_array($parent_id, $allowed_ids, true))) : ?>
        <option value="-1" selected><?php echo esc_html('Связанная точка недоступна; сохранено: ' . $legacy); ?></option>
      <?php endif; ?>
      <option value="0" <?php selected($parent_id, 0); ?>>Не относится к другому пункту</option>
      <?php foreach ($settlements as $settlement) : if ((int) $settlement->ID === (int) $post->ID) continue; ?>
        <option value="<?php echo esc_attr((string) $settlement->ID); ?>" <?php selected($parent_id, (int) $settlement->ID); ?>><?php echo esc_html($settlement->post_title); ?></option>
      <?php endforeach; ?>
    </select></p>
    <p class="description">Выберите существующий город или посёлок. Старые текстовые значения сохраняются до выбора связи.</p>
    <div id="pt-point-map" style="width:100%;height:360px;background:#f0f0f1" aria-label="Карта выбора координат"></div>
    <p id="pt-point-map-status" class="description" role="status">Загружаем карту…</p>
    <p class="description">Нажмите на карту или перетащите метку. Координаты ниже можно указать вручную, если карта недоступна.</p>
    <?php
}

add_action('save_post_pt_point', function (int $post_id) {
    $nonce = $_POST['pt_point_location_nonce'] ?? null;
    if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'pt_save_point_location') || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    $raw = $_POST['pt_parent_point'] ?? null;
    if (!is_string($raw) || !preg_match('/^(?:-1|0|[1-9][0-9]*)$/D', $raw)) {
        return;
    }
    $selected = (int) $raw;
    if ($selected < 0) {
        return;
    }
    if ($selected > 0) {
        if (!pt_is_published_settlement(get_post($selected)) || pt_parent_would_cycle($post_id, $selected)) {
            return;
        }
    }
    update_post_meta($post_id, PT_PARENT_POINT_META, $selected);
});

add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$screen || $screen->post_type !== 'pt_point') {
        return;
    }
    $settings_id = pt_settings_id();
    $key = function_exists('carbon_get_post_meta') ? (string) carbon_get_post_meta($settings_id, 'pt_map_api_key') : '';
    wp_enqueue_script('pt-admin-point', PT_CORE_URL . 'includes/admin-point.js', [], PT_CORE_VERSION, true);
    wp_add_inline_script('pt-admin-point', 'window.ptPointMapConfig = ' . wp_json_encode(['key' => $key], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
});
