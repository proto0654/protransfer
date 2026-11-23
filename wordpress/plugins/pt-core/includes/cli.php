<?php
if (!defined('ABSPATH')) {
    exit;
}

class PT_CLI_Command
{
    /**
     * Seed CPT and theme options from plugin data/*.json
     *
     * ## OPTIONS
     *
     * [--force]
     * : Overwrite existing Carbon field values.
     *
     * ## EXAMPLES
     *
     *     wp pt seed
     *     wp pt seed --force
     *
     * @when after_wp_load
     */
    public function seed($args, $assoc_args): void
    {
        $force = !empty($assoc_args['force']);
        $data = PT_CORE_PATH . 'data/';

        $this->seed_points($this->read_json($data . 'points.json'), $force);
        $this->seed_tariffs($this->read_json($data . 'tariffs.json'), $force);
        $this->seed_routes($this->read_json($data . 'routes.json'), $force);
        $this->seed_faq($this->read_json($data . 'faq.json'), $force);
        $this->seed_reviews($this->read_json($data . 'reviews.json'), $force);
        $this->seed_site($this->read_json($data . 'site.json'), $force);
        $this->seed_calc_defaults($force);

        WP_CLI::success('Seed completed');
    }

    /**
     * Link unambiguous legacy city names and translate default point types.
     *
     * [--apply]
     * : Apply the changes after previewing them. Creates a rollback record.
     *
     * [--rollback]
     * : Revert unchanged values from the recorded migration.
     *
     * @subcommand migrate-points
     * @when after_wp_load
     */
    public function migrate_points($args, $assoc_args): void
    {
        $backup_key = 'pt_point_migration_backup';
        $apply = !empty($assoc_args['apply']);
        $rollback = !empty($assoc_args['rollback']);
        if ($apply && $rollback) {
            WP_CLI::error('Choose either --apply or --rollback.');
        }
        if ($rollback) {
            $backup = get_option($backup_key, null);
            if (!is_array($backup)) {
                WP_CLI::error('No point migration rollback record exists.');
            }
            $remaining = ['links' => [], 'terms' => []];
            foreach ($backup['links'] as $id => $parent_id) {
                if (!metadata_exists('post', (int) $id, PT_PARENT_POINT_META)) continue;
                if ((string) get_post_meta((int) $id, PT_PARENT_POINT_META, true) === (string) $parent_id
                    && delete_post_meta((int) $id, PT_PARENT_POINT_META, $parent_id)) {
                    continue;
                }
                $remaining['links'][$id] = $parent_id;
                WP_CLI::warning("Point {$id} could not be safely reverted.");
            }
            foreach ($backup['terms'] as $id => $names) {
                $term = get_term((int) $id, 'pt_point_type');
                if ($term && !is_wp_error($term) && $term->name === $names['old']) continue;
                if ($term && !is_wp_error($term) && $term->name === $names['new']) {
                    if ($this->set_point_type_name((int) $id, $names['old'])) continue;
                }
                $remaining['terms'][$id] = $names;
                WP_CLI::warning("Point type {$id} could not be safely reverted.");
            }
            if ($remaining['links'] || $remaining['terms']) {
                update_option($backup_key, $remaining, false);
                WP_CLI::error('Rollback was partial. Review the remaining entries before retrying.');
            }
            if (!delete_option($backup_key) && get_option($backup_key, null) !== null) {
                WP_CLI::error('Values were restored, but the rollback record could not be cleared. Retry --rollback.');
            }
            WP_CLI::success('Rollback finished.');
            return;
        }
        $settlements = pt_settlement_points();
        $points = get_posts(['post_type' => 'pt_point', 'post_status' => 'any', 'numberposts' => -1]);
        $changes = ['links' => [], 'terms' => []];
        $unmatched = [];
        foreach ($points as $point) {
            if (metadata_exists('post', $point->ID, PT_PARENT_POINT_META)) continue;
            $legacy = (string) carbon_get_post_meta($point->ID, 'pt_city');
            if ($legacy === '' || trim($legacy) === trim($point->post_title)) continue;
            $parent_id = pt_unique_settlement_id($legacy, (int) $point->ID, $settlements);
            if ($parent_id) $changes['links'][$point->ID] = $parent_id;
            else $unmatched[] = (string) $point->post_name;
        }
        $planned_links = $changes['links'];
        foreach ($planned_links as $id => $parent_id) {
            if (pt_parent_would_cycle((int) $id, (int) $parent_id, $planned_links)) {
                unset($changes['links'][$id]);
                $unmatched[] = 'id:' . $id . ' (cyclic relationship)';
            }
        }
        foreach (pt_point_type_labels() as $slug => $label) {
            $term = get_term_by('slug', $slug, 'pt_point_type');
            if ($term && $term->name === ucfirst($slug)) {
                $changes['terms'][$term->term_id] = ['old' => $term->name, 'new' => $label];
            }
        }
        WP_CLI::log('Links: ' . count($changes['links']) . '; type labels: ' . count($changes['terms']) . '; unmatched: ' . count($unmatched));
        if ($unmatched) WP_CLI::warning('Review unmatched point slugs: ' . implode(', ', $unmatched));
        if (!$apply || (!$changes['links'] && !$changes['terms'])) return;
        if (!add_option($backup_key, $changes, '', false)) {
            WP_CLI::error('Rollback record already exists; inspect or roll back before applying another migration.');
        }
        foreach ($changes['links'] as $id => $parent_id) {
            if (!add_post_meta((int) $id, PT_PARENT_POINT_META, $parent_id, true)) {
                WP_CLI::error("Could not link point {$id}. Review the changes or use --rollback before retrying.");
            }
        }
        foreach ($changes['terms'] as $id => $names) {
            $term = get_term((int) $id, 'pt_point_type');
            if (!$term || is_wp_error($term) || $term->name !== $names['old']) {
                WP_CLI::error("Point type {$id} changed during migration. Review the changes or use --rollback before retrying.");
            }
            if (!$this->set_point_type_name((int) $id, $names['new'])) {
                WP_CLI::error("Could not rename point type {$id}. Use --rollback before retrying.");
            }
        }
        WP_CLI::success('Migration finished; legacy city text remains available for rollback.');
    }

    private function set_point_type_name(int $id, string $name): bool
    {
        $result = wp_update_term($id, 'pt_point_type', ['name' => $name]);
        if (is_wp_error($result)) {
            return false;
        }
        $term = get_term($id, 'pt_point_type');
        return $term && !is_wp_error($term) && $term->name === $name;
    }

    private function read_json(string $path)
    {
        if (!file_exists($path)) {
            WP_CLI::error("Missing {$path}");
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            WP_CLI::error("Invalid JSON: {$path}");
        }
        return $data;
    }

    private function upsert_cpt(string $type, string $slug, string $title, int $menu_order = 0): int
    {
        $existing = get_posts([
            'post_type' => $type,
            'name' => $slug,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        if ($existing) {
            $id = (int) $existing[0];
            wp_update_post([
                'ID' => $id,
                'post_title' => $title,
                'post_status' => 'publish',
                'menu_order' => $menu_order,
            ]);
            return $id;
        }

        $id = wp_insert_post([
            'post_type' => $type,
            'post_name' => $slug,
            'post_title' => $title,
            'post_status' => 'publish',
            'menu_order' => $menu_order,
        ], true);

        if (is_wp_error($id)) {
            WP_CLI::error($id->get_error_message());
        }

        return (int) $id;
    }

    private function set_meta(int $id, string $key, $value, bool $force): void
    {
        if (!function_exists('carbon_set_post_meta')) {
            WP_CLI::warning('Carbon Fields not loaded — skip meta');
            return;
        }
        if (!$force) {
            $current = carbon_get_post_meta($id, $key);
            if ($current !== '' && $current !== null && $current !== [] && $current !== false) {
                return;
            }
        }
        carbon_set_post_meta($id, $key, $value);
    }

    private function set_theme(string $key, $value, bool $force): void
    {
        if (!function_exists('carbon_set_theme_option')) {
            WP_CLI::warning('Carbon Fields not loaded — skip theme option');
            return;
        }
        if (!$force) {
            $current = carbon_get_theme_option($key);
            if ($current !== '' && $current !== null && $current !== [] && $current !== false) {
                return;
            }
        }
        carbon_set_theme_option($key, $value);
    }

    private function seed_points(array $rows, bool $force): void
    {
        $i = 0;
        foreach ($rows as $row) {
            $id = $this->upsert_cpt('pt_point', $row['id'], $row['name'], $i++);
            update_post_meta($id, '_pt_source_id', $row['id']);
            $this->set_meta($id, 'pt_city', $row['city'] ?? '', $force);
            $this->set_meta($id, 'pt_lat', (string) ($row['lat'] ?? ''), $force);
            $this->set_meta($id, 'pt_lon', (string) ($row['lon'] ?? ''), $force);
            $aliases = [];
            foreach ($row['alias'] ?? [] as $alias) {
                $aliases[] = ['alias' => $alias];
            }
            $this->set_meta($id, 'pt_aliases', $aliases, $force);
        }
        WP_CLI::log('points: ' . count($rows));
    }

    private function seed_tariffs(array $rows, bool $force): void
    {
        $i = 0;
        $photos = 0;
        foreach ($rows as $row) {
            $id = $this->upsert_cpt('pt_tariff', $row['id'], $row['name'], $i++);
            update_post_meta($id, '_pt_source_id', $row['id']);
            $this->set_meta($id, 'pt_seats', (string) ($row['seats'] ?? ''), $force);
            $this->set_meta($id, 'pt_bags', (string) ($row['bags'] ?? ''), $force);
            $this->set_meta($id, 'pt_cars', $row['cars'] ?? '', $force);
            $this->set_meta($id, 'pt_description', $row['description'] ?? '', $force);
            $this->set_meta($id, 'pt_base', (string) ($row['base'] ?? 0), $force);
            $this->set_meta($id, 'pt_per_km', (string) ($row['perKm'] ?? 0), $force);
            $this->set_meta($id, 'pt_min_price', (string) ($row['minPrice'] ?? 0), $force);
            $this->set_meta($id, 'pt_tag', $row['tag'] ?? '', $force);
            $this->set_meta($id, 'pt_on_request', !empty($row['onRequest']), $force);

            if ($this->attach_tariff_photo($id, $row['photo'] ?? '', $row['name'] ?? '', $force)) {
                $photos++;
            }
        }
        WP_CLI::log('tariffs: ' . count($rows) . " (photos: {$photos})");
    }

    private function fleet_source_path(string $photo_rel): string
    {
        $base = basename(str_replace('\\', '/', $photo_rel));
        if ($base === '' || $base === '.' || $base === '..') {
            return '';
        }
        $candidates = [
            PT_CORE_PATH . 'data/fleet/' . $base,
            get_stylesheet_directory() . '/landing/fleet/' . $base,
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return '';
    }

    private function ensure_fleet_attachment(string $path, string $title): int
    {
        $source = basename($path);
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_pt_fleet_source',
            'meta_value' => $source,
        ]);
        if ($existing) {
            return (int) $existing[0];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($source);
        if (!$tmp || !copy($path, $tmp)) {
            return 0;
        }

        $file_array = [
            'name' => $source,
            'tmp_name' => $tmp,
        ];
        $attachment_id = media_handle_sideload($file_array, 0, $title);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            WP_CLI::warning('media: ' . $attachment_id->get_error_message() . " ({$source})");
            return 0;
        }

        update_post_meta((int) $attachment_id, '_pt_fleet_source', $source);
        return (int) $attachment_id;
    }

    private function attach_tariff_photo(int $post_id, string $photo_rel, string $title, bool $force): bool
    {
        if ($photo_rel === '') {
            return false;
        }
        if (!$force && get_post_thumbnail_id($post_id)) {
            return true;
        }

        $path = $this->fleet_source_path($photo_rel);
        if ($path === '') {
            WP_CLI::warning("fleet file missing for {$photo_rel}");
            return false;
        }

        $attachment_id = $this->ensure_fleet_attachment($path, $title);
        if (!$attachment_id) {
            return false;
        }

        set_post_thumbnail($post_id, $attachment_id);
        if (function_exists('carbon_set_post_meta')) {
            carbon_set_post_meta($post_id, 'pt_photo', (string) $attachment_id);
        }
        return true;
    }

    private function seed_routes(array $data, bool $force): void
    {
        $popular = [];
        foreach ($data['popular'] ?? [] as $row) {
            $popular[] = ['from_slug' => $row['from'], 'to_slug' => $row['to']];
        }
        $overrides = [];
        foreach ($data['overrides'] ?? [] as $row) {
            $overrides[] = [
                'from_slug' => $row['from'],
                'to_slug' => $row['to'],
                'km' => (string) $row['km'],
            ];
        }
        $this->set_theme('pt_popular_routes', $popular, $force);
        $this->set_theme('pt_route_overrides', $overrides, $force);
        WP_CLI::log('routes: popular=' . count($popular) . ' overrides=' . count($overrides));
    }

    private function seed_faq(array $rows, bool $force): void
    {
        $i = 0;
        foreach ($rows as $row) {
            $slug = 'faq-' . ($i + 1);
            $id = $this->upsert_cpt('pt_faq', $slug, $row['q'], $i++);
            $this->set_meta($id, 'pt_answer', $row['a'] ?? '', $force);
        }
        WP_CLI::log('faq: ' . count($rows));
    }

    private function seed_reviews(array $rows, bool $force): void
    {
        $i = 0;
        foreach ($rows as $row) {
            $slug = 'review-' . ($i + 1);
            $id = $this->upsert_cpt('pt_review', $slug, $row['name'], $i++);
            $this->set_meta($id, 'pt_city', $row['city'] ?? '', $force);
            $this->set_meta($id, 'pt_route', $row['route'] ?? '', $force);
            $this->set_meta($id, 'pt_rating', (string) ($row['rating'] ?? 5), $force);
            $this->set_meta($id, 'pt_date_label', $row['date'] ?? '', $force);
            $this->set_meta($id, 'pt_text', $row['text'] ?? '', $force);
        }
        WP_CLI::log('reviews: ' . count($rows));
    }

    private function seed_site(array $site, bool $force): void
    {
        $id = pt_ensure_settings_post();
        $map = [
            'pt_brand' => $site['brand'] ?? '',
            'pt_tagline' => $site['tagline'] ?? '',
            'pt_phone' => $site['phone'] ?? '',
            'pt_phone_href' => $site['phoneHref'] ?? '',
            'pt_whatsapp' => $site['whatsapp'] ?? '',
            'pt_telegram' => $site['telegram'] ?? '',
            'pt_max' => $site['max'] ?? '',
            'pt_email' => $site['email'] ?? '',
            'pt_address' => $site['address'] ?? '',
            'pt_hours' => $site['hours'] ?? '',
            'pt_map_api_key' => $site['map']['apiKey'] ?? '',
            'pt_rating' => (string) ($site['rating'] ?? ''),
            'pt_reviews_count' => (string) ($site['reviewsCount'] ?? ''),
        ];
        foreach ($map as $key => $value) {
            $this->set_meta($id, $key, $value, $force);
        }
        $stats = array_map(static fn ($row) => [
            'stat_value' => $row['value'] ?? ($row['stat_value'] ?? ''),
            'label' => $row['label'] ?? '',
        ], $site['stats'] ?? []);
        $this->set_meta($id, 'pt_stats', $stats, $force);
        $this->set_meta($id, 'pt_advantages', $site['advantages'] ?? [], $force);
        $this->set_meta($id, 'pt_steps', $site['steps'] ?? [], $force);
        $this->set_meta($id, 'pt_services', $site['services'] ?? [], $force);
        $payments = array_map(static fn ($label) => ['label' => $label], $site['payments'] ?? []);
        $this->set_meta($id, 'pt_payments', $payments, $force);
        WP_CLI::log('site settings id=' . $id);
    }

    private function seed_calc_defaults(bool $force): void
    {
        $defaults = pt_calc_defaults();
        $this->set_theme('pt_road_factor', (string) $defaults['road_factor'], $force);
        $this->set_theme('pt_km_round', (string) $defaults['km_round'], $force);
        $this->set_theme('pt_speed_short', (string) $defaults['speed_short'], $force);
        $this->set_theme('pt_speed_mid', (string) $defaults['speed_mid'], $force);
        $this->set_theme('pt_speed_long', (string) $defaults['speed_long'], $force);
        $this->set_theme('pt_time_buffer', (string) $defaults['time_buffer'], $force);
        $this->set_theme('pt_time_round', (string) $defaults['time_round'], $force);
        $this->set_theme('pt_round_trip_factor', (string) $defaults['round_trip_factor'], $force);
        $this->set_theme('pt_extra_stop_price', (string) $defaults['extra_stop_price'], $force);
        $this->set_theme('pt_price_round', (string) $defaults['price_round'], $force);
        $this->set_theme('pt_speed_mid_threshold', (string) $defaults['speed_mid_threshold'], $force);
        $this->set_theme('pt_speed_long_threshold', (string) $defaults['speed_long_threshold'], $force);
        $this->set_theme('pt_child_seat_price', (string) $defaults['child_seat_price'], $force);
        WP_CLI::log('calc defaults seeded');
    }
}

WP_CLI::add_command('pt', 'PT_CLI_Command');
