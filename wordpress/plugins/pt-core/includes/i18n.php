<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reload Carbon Fields translations once the admin locale is known.
 *
 * Carbon boots at after_setup_theme priority 5 and loads its own textdomains
 * later on the same hook with get_locale(), which may still be en_US in the
 * admin. Reloading here with determine_locale() keeps the field UI Russian.
 */
function pt_reload_carbon_textdomains(): void
{
    if (!defined('PT_CORE_PATH') || !function_exists('determine_locale') || !function_exists('load_textdomain')) {
        return;
    }
    $directory = PT_CORE_PATH . 'vendor/htmlburger/carbon-fields/languages/';
    $locale = determine_locale();
    foreach (['carbon-fields', 'carbon-fields-ui'] as $domain) {
        $file = $directory . $domain . '-' . $locale . '.mo';
        if (file_exists($file)) {
            load_textdomain($domain, $file);
        }
    }
}
add_action('after_setup_theme', 'pt_reload_carbon_textdomains', 11);

/**
 * Feed the Carbon UI JavaScript strings through the config it already reads.
 *
 * Verified in the browser: `wp.i18n.setLocaleData` does not reach the bundled
 * components (wp.i18n still returns the English msgid), while Carbon's own UI
 * reads `cf.config.locale`. Merging the map here is what actually translates
 * «Add %s» and «There are no entries yet.»; existing vendor entries win.
 */
function pt_translate_carbon_ui_strings(array $config): array
{
    if (!isset($config['config']['locale']) || !is_array($config['config']['locale'])) {
        return $config;
    }
    foreach (pt_carbon_ui_js_translations() as $msgid => $translation) {
        if (!isset($config['config']['locale'][$msgid])) {
            $config['config']['locale'][$msgid] = [$translation];
        }
    }
    return $config;
}
if (function_exists('add_filter')) {
    add_filter('carbon_fields_config', 'pt_translate_carbon_ui_strings');
}

/**
 * Russian translations for the Carbon Fields UI JavaScript strings.
 *
 * Carbon 3.6.11 ships these msgids only as .mo/.po catalogs and has no JS
 * translation files, so @wordpress/i18n falls back to the English msgids in
 * the browser. The list is derived from the carbon-fields-ui calls in
 * build/classic/core.js and build/classic/metaboxes.js.
 *
 * @return array<string, string>
 */
function pt_carbon_ui_js_translations(): array
{
    return [
        '%1$s %2$s is already registered.' => '%1$s %2$s уже зарегистрирован.',
        '%1$s type must be a string.' => 'Тип %1$s должен быть строкой.',
        'Add' => 'Добавить',
        'Add %s' => 'Добавить %s',
        'An error occured.' => 'Произошла ошибка.',
        'An error occurred while trying to create the sidebar.' => 'Произошла ошибка при попытке создать боковую панель.',
        'An error occurred while trying to fetch association options.' => 'Произошла ошибка при попытке получить параметры связи.',
        'An error occurred while trying to fetch files data.' => 'Произошла ошибка при попытке получить данные файлов.',
        'An error occurred while trying to fetch oembed preview.' => 'Произошла ошибка при попытке получить предварительный просмотр oembed.',
        'An unknown field is used in condition - "%s"' => 'Неизвестное поле используется в условии - "%s"',
        'Clear' => 'Очистить',
        'Click to edit' => 'Нажмите, чтобы изменить',
        'Close' => 'Закрыть',
        'Collapse' => 'Свернуть',
        'Collapse All' => 'Свернуть все',
        "Couldn't create the label of group - %s" => 'Не удалось создать метку группы - %s',
        'Could not find DOM element for container "%1$s".' => 'Не удалось найти элемент DOM для контейнера "%1$s".',
        'Duplicate' => 'Дублировать',
        'Edit' => 'Изменить',
        'Error alert' => 'Предупреждение об ошибке',
        'Expand All' => 'Развернуть все',
        'Geocode was not successful for the following reason: ' => 'Запрос геокодера завершился ошибкой: ',
        'Maximum number of items reached (%s items)' => 'Достигнуто максимальное число элементов (%s шт.)',
        'Minimum number of items not reached (%s items)' => 'Не достигнуто минимальное количество элементов (%s шт.)',
        'Minimum number of rows not reached (%1$d %2$s)' => 'Не достигнуто минимальное количество строк (%1$d %2$s)',
        'No options.' => 'Нет опций.',
        'Not Found' => 'Не найдено',
        'Please choose' => 'Пожалуйста, выберите',
        'Please enter the name of the new sidebar:' => 'Пожалуйста, введите название новой боковой панели:',
        'Remove' => 'Удалить',
        'Search...' => 'Поиск...',
        'Select Attachments' => 'Выбрать файлы',
        'Select Date' => 'Выбрать дату',
        'Select File' => 'Выбрать файл',
        'Select Image' => 'Выбрать изображение',
        'Select Time' => 'Выбрать время',
        'Select a color' => 'Выбрать цвет',
        'Show All Options' => 'Показать все варианты',
        'Show Less Options' => 'Показать меньше вариантов',
        'Showing %1$d of %2$d results' => 'Показаны %1$d из %2$d результатов',
        'Text' => 'Текст',
        'The "component" param must be a function.' => 'Параметр "component" должен быть функцией.',
        'The address could not be found.' => 'Адрес не найден.',
        'There are no entries yet.' => 'Записей пока нет.',
        'This field is required.' => 'Обязательное поле.',
        'Thumbnail' => 'Миниатюра',
        'Unsupported container condition - "%1$s".' => 'Неподдерживаемое условие контейнера - "%1$s".',
        'Unsupported container condition comparison operator used - "%1$s".' => 'Используется неподдерживаемый оператор сравнения условий контейнера - "%1$s".',
        'Unsupported container condition relation used - "%1$s".' => 'Используется неподдерживаемое отношение условий контейнера - "%1$s".',
        'Use Attachments' => 'Выбрать',
        'Use File' => 'Выбрать',
        'Use Image' => 'Выбрать',
        'Visual' => 'Визуальный',
    ];
}

/**
 * Queue wp-i18n before Carbon Fields so setLocaleData runs first.
 */
function pt_prepare_carbon_ui_translations(): void
{
    if (!function_exists('is_admin') || !is_admin() || !function_exists('wp_script_is') || !wp_script_is('carbon-fields-vendor', 'registered')) {
        return;
    }
    if (function_exists('wp_enqueue_script')) {
        wp_enqueue_script('wp-i18n');
    }
    if (function_exists('wp_scripts') && class_exists('WP_Scripts')) {
        $scripts = wp_scripts();
        if ($scripts instanceof WP_Scripts && isset($scripts->registered['carbon-fields-vendor'])) {
            $dependencies = &$scripts->registered['carbon-fields-vendor']->deps;
            if (!in_array('wp-i18n', $dependencies, true)) {
                $dependencies[] = 'wp-i18n';
            }
            unset($dependencies);
        }
    }
}
add_action('admin_print_footer_scripts', 'pt_prepare_carbon_ui_translations', 10);

/**
 * Print the translation map before the Carbon Fields vendor bundle runs.
 *
 * Carbon enqueues its assets at admin_print_footer_scripts priority 9 and
 * initializes the UI at priority 9999. Attaching to the vendor handle with
 * the "before" position keeps the map ahead of both the bundle and the UI.
 */
function pt_print_carbon_ui_translations(): void
{
    if (!function_exists('is_admin') || !is_admin() || !function_exists('wp_script_is') || !wp_script_is('carbon-fields-vendor', 'registered')) {
        return;
    }
    if (!function_exists('wp_add_inline_script') || !function_exists('wp_json_encode')) {
        return;
    }
    $map = wp_json_encode(
        pt_carbon_ui_js_translations(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $script = 'if (window.wp && wp.i18n && typeof wp.i18n.setLocaleData === "function") {'
        . 'wp.i18n.setLocaleData(' . $map . ', "carbon-fields-ui");'
        . '}';
    wp_add_inline_script('carbon-fields-vendor', $script, 'before');
}
add_action('admin_print_footer_scripts', 'pt_print_carbon_ui_translations', 11);
