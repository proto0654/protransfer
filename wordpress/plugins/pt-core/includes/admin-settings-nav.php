<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add the section navigation to the single pt_settings record screen.
 *
 * Carbon renders every container as a .carbon-box postbox. The browser script
 * builds one tab per such box and only toggles their visibility, so the
 * standard WordPress panels and Carbon's field components stay untouched. When
 * Carbon Fields is unavailable no .carbon-box is rendered and the script exits
 * without changing the screen.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$screen || $screen->post_type !== 'pt_settings') {
        return;
    }

    wp_enqueue_style('pt-admin-settings-nav', PT_CORE_URL . 'includes/admin-settings-nav.css', [], PT_CORE_VERSION);
    wp_enqueue_script('pt-admin-settings-nav', PT_CORE_URL . 'includes/admin-settings-nav.js', [], PT_CORE_VERSION, true);
    wp_add_inline_script(
        'pt-admin-settings-nav',
        'window.ptSettingsNavConfig = ' . wp_json_encode([
            'storageKey' => 'pt-settings-nav-' . get_current_user_id(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
        'before'
    );
});
