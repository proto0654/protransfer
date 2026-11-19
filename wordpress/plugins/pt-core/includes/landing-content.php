<?php
if (!defined('ABSPATH')) {
    exit;
}

function pt_landing_schema(): array
{
    static $schema;
    if ($schema === null) {
        $schema = json_decode((string) file_get_contents(PT_CORE_PATH . 'data/content.json'), true) ?: [];
    }
    return $schema;
}

function pt_landing_copy(int $settings_id): array
{
    $copy = [];
    foreach (pt_landing_schema() as $key => $field) {
        $meta = 'pt_copy_' . $key;
        $copy[$key] = function_exists('carbon_get_post_meta') && metadata_exists('post', $settings_id, '_' . $meta)
            ? (string) carbon_get_post_meta($settings_id, $meta)
            : $field['default'];
    }
    return $copy;
}

function pt_landing_seo(int $settings_id): array
{
    $defaults = [
        'title' => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
    ];
    foreach ($defaults as $key => $fallback) {
        $value = function_exists('carbon_get_post_meta') ? carbon_get_post_meta($settings_id, 'pt_seo_' . $key) : '';
        $defaults[$key] = $value === '' || $value === null ? $fallback : (string) $value;
    }
    return $defaults;
}

/** Replace only explicitly marked plain-text content; never interpret editor HTML. */
function pt_render_landing_copy(string $html, array $site): string
{
    $html = preg_replace_callback('~(<div\b[^>]*data-pt-legal="([a-z0-9_]+)"[^>]*>).*?(</div>)~si', static function ($match) use ($site) {
        if (!array_key_exists($match[2], $site['copy'] ?? [])) {
            return $match[0];
        }
        return $match[1] . wp_kses_post($site['copy'][$match[2]]) . $match[3];
    }, $html);
    return preg_replace_callback('~(<([a-z][a-z0-9]*)\b[^>]*data-pt-(copy|site)="([a-z0-9_]+)"[^>]*>).*?(</\2>)~si', static function ($match) use ($site) {
        $values = $match[3] === 'copy' ? ($site['copy'] ?? []) : $site;
        if (!array_key_exists($match[4], $values)) {
            return $match[0];
        }
        return $match[1] . esc_html((string) $values[$match[4]]) . $match[5];
    }, $html);
}
