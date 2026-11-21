# WordPress staging — Protransfer

## Runtime

- URL: https://protransfer.weblaba.ru/
- Admin: https://protransfer.weblaba.ru/wp-admin/
- REST: https://protransfer.weblaba.ru/wp-json/pt/v1/bootstrap
- SSH: `u1396397@server199.hosting.reg.ru`, key `~/.ssh/weblaba_deploy`
- Docroot: `~/www/protransfer.weblaba.ru/`
- DB: `u1396397_wp545`, table prefix `pt_`
- Tools: `~/.local/share/protransfer/tools/{wp-cli,composer}.phar`
- Credentials / old-DB dump path: server `~/.local/share/protransfer/access.json` (also local `.sites-runtime/wordpress-access.json`, gitignored)

Pre-WordPress dump of the previous site: `~/.local/share/protransfer/backups/wp545-before-wordpress-*.sql.gz`.

Stack: WordPress (ru), GeneratePress parent + `protransfer` child, Carbon Fields via Composer in `pt-core`, MU `pt-staging.php` (noindex, no mail, order notifications suppressed).

## Local Vite → WP proxy

`npm run dev` proxies:

- `/wp-json` → `https://protransfer.weblaba.ru`
- `/wp-content/uploads` → same

The WordPress theme embeds bootstrap data in its response. Local Vite uses `/wp-json/pt/v1/bootstrap` through the proxy. An API error, eight-second timeout, or empty points/tariffs enables bundled demo JSON. Force JSON: `VITE_DATA_SOURCE=json`. Staging owns editor data and uploads; Git owns code and build inputs.

Use the same staging backend for local previews and the published landing; do not copy its database or credentials into Git.

## Deploy from `main` → this staging stand

Target: **https://protransfer.weblaba.ru/** (пока единственный деплой-стенд).

GitHub Actions: [`.github/workflows/deploy-weblaba.yml`](../.github/workflows/deploy-weblaba.yml) — push to `main` (frontend + wordpress paths) or `workflow_dispatch`.

Pipeline: `npm ci` → `vite build --mode weblaba` → verified current-site database/code backup → upload landing into child theme `landing/` + `pt-core` + MU → Composer install from lockfile / activate / verify WordPress. Routine deployments do not rerun seed or rewrite permalinks. Homepage (`front-page.php`) отдаёт `landing/preview.html`.

GitHub repository secret **`WEBLABA_SSH_PRIVATE_KEY`** is configured from the existing `%USERPROFILE%\.ssh\weblaba_deploy` identity. Never place the key material in Git or logs.

Local:

```bash
npm run deploy:weblaba
```

GitHub Pages workflow (`deploy.yml`) остаётся отдельным резервом для статического demo.

## Seed / debug

```bash
wp pt seed
wp pt seed --force
```

Seed is an explicit initial/import operation; `--force` overwrites saved values and is not part of deployment.

For editor-facing section descriptions and examples, see [WordPress admin: sections and workflows](WORDPRESS-ADMIN.md).

## Calculator settings and testing

Calculator defaults are shared by PHP and the static frontend through `wordpress/plugins/pt-core/data/calc.json`. In WordPress, edit the saved values under **Тарифы → Калькулятор** and each tariff in **Тарифы**. **Тарифы → Отладка** embeds the real frontend calculator, a client-side formula breakdown, a synchronized server comparison and editable settings alongside it. The laboratory response is available only to users with `manage_options`; unsaved settings are sent to the same-origin iframe for preview and do not change stored values. Edits immediately update the preview without changing the site; press **Сохранить настройки** to apply them. Invalid non-positive divisors and speeds fall back to defaults.

The website requests Yandex and OSRM before falling back to manual route distances and a coordinate estimate. The server debugger does not call those services. The embedded calculator synchronizes its displayed distance to the server comparison; it can also be entered manually; the displayed server time remains an estimate. The debugger does not submit an order. The landing posts real orders to `pt/v1/order`; WordPress validates them, recalculates the price and stores a `pt_order`. The order endpoint is not a payment integration.

Run `node --test scripts/calculator.test.js` with PHP and Node installed to compare the client and server formulas. Landing copy and legal text are managed through the site settings fields described below.

## Landing content and theme integration

Edit **Общие данные → Настройки сайта** to manage brand, contacts, SEO title/description, the hero image, and grouped landing copy. The content schema in `wordpress/plugins/pt-core/data/content.json` defines labels and initial text for the same fields used by PHP and Vite. An unset field receives its initial text; explicitly saving empty text keeps it empty. The historical files under `mvp` are not modified by content updates.

FAQ, reviews, fleet and points retain their own editors. **Точки → Маршруты** selects the tariff displayed on popular route cards. The passenger limit is derived from fleet capacity. Form control labels, SVG decoration and layout remain template code.

The child theme renders the built landing through WordPress, applies escaped content and SEO metadata, embeds public bootstrap data, and calls `wp_head`, `wp_body_open` and `wp_footer`. The frontend uses that embedded data without a second bootstrap request. Local Vite still uses the REST proxy, with an eight-second timeout and bundled JSON fallback. The main and preview source entries must remain identical.

Legal documents are edited in the site's **Документы** content group and filtered through `wp_kses_post`. On WordPress, links use `/?pt_legal=1#privacy`, `#consent` and `#offer`; the same theme serves the built legal page. Static builds continue using `legal.html`. Existing legal copy is only an initial template and still needs the business owner's approval before publication.

Additional verification: `node --test scripts/content.test.js` and `php scripts/landing-content.test.php`. These check marker/schema consistency, field identifiers, fallback behavior and escaped server rendering without a full WordPress runtime. A complete save/reload/render walkthrough against WordPress with Carbon Fields 3.6.11 remains an acceptance task; HTTP smoke checks alone do not prove editorial coverage. Content keys must match `[a-z][a-z0-9_]*`; Carbon Fields rejects names containing spaces or slashes, and client bindings use these keys as property names.

## Deployment backups and rollback

The script accepts only the documented Protransfer docroot. Before any upload it exports the current database, compresses and verifies it, and archives `wp-content`, `wp-config.php` and an existing `.htaccess` into the private `~/.local/share/protransfer/backups/<UTC timestamp>-<commit>/` directory. The backup directory records the target commit; successful publication records `~/.local/share/protransfer/deployed-commit`.

GitHub deployments are serialized without cancelling an in-progress upload. SSH scripts are sent through stdin to Bash so shell variables and multiline commands reach the server intact. Failed backups and Composer commands stop deployment. Existing uploads, configuration and editor data are preserved; seed remains a separate, explicit first-install/content-import operation.

For rollback, restore the matching code archive from that backup. Restore its database only when required and explicitly authorized; do not use the older pre-WordPress database dump for ordinary rollback. Inspect the backup manifest and archive contents before restoring. Deployment is currently an in-place upload, so review the run log and public responses if a transfer fails midway.

The installed WP-CLI needs `--no-tablespaces=true` when exporting with `--column-statistics=0`; the bare `--no-tablespaces` flag was converted to an unsupported `tablespaces=` option. The deployment script uses the explicit form and verifies the compressed result before uploading.

## Order notifications on staging

`wordpress/mu-plugins/pt-staging.php` adds `add_filter('pt_order_notify_enabled', '__return_false')` when `wp_get_environment_type()` is `staging`. No Telegram request leaves the staging stand, even if the bot token and chat id are filled in «Контакты и контент». Orders are still validated, priced and stored; the notification attempt is recorded as skipped and the «Уведомление» column stays empty.

Safe testing:
- use a dedicated test bot and a private test chat, never the real business chat;
- run the delivery test on a local WordPress or a throwaway copy whose environment type is not `staging`, or temporarily remove the MU filter only in that local copy;
- fill the token and chat id on that copy, submit a test booking, and check the chat plus the order's «Уведомление» column;
- remove the test values afterwards. Do not put production Telegram credentials into staging or Git.

Expected failures such as a missing token or a wrong chat id appear in the order's notification meta and in the «Уведомление» column. The order itself is never rolled back by a failed notification.

## Verification boundary

For each publication, inspect the Actions conclusion and the private `deployed-commit` file, then check the homepage, `/?pt_legal=1`, bootstrap API and admin/login response. Verify built JS/CSS responses and MIME types, valid content identifiers and public `noindex` headers. Local `/wp-json/pt/v1/bootstrap` must reach the same staging backend. These read-only checks do not verify logging in or saving content.

The calculator still uses provider distances in the browser and estimated/manual distances in admin. Orders now use server-side intake: the public form creates a `pt_order`, the price is recalculated on the server, and Telegram delivery is attempted only where notifications are enabled. Some UI strings and template content remain code; do not treat marker coverage as a complete inventory of all editorial values.

## Point relationship migration

A point can link to an existing published `city` or `resort` point by ID; the public `city` string is derived from that parent while it is available. Existing `_pt_city` text is retained and used for unlinked or unavailable parents. Explicitly choosing no parent is distinct from an unlinked legacy record. The selector rejects self-links and cycles. The Yandex map in the point editor writes to the existing Carbon latitude/longitude fields; manual entry remains available if the map is unavailable.

After deploying the updated plugin, run `wp pt migrate-points` for a read-only preview. It reports unambiguous links, default type labels and unmatched point slugs. `wp pt migrate-points --apply` stores a non-autoloaded rollback record, creates only unique links and translates unchanged default term names while keeping their slugs. It leaves ambiguous and unmatched names intact. Inspect the preview and make a current database backup before applying. `wp pt migrate-points --rollback` reverts only values still matching the migration and retains rollback entries for later editor changes. Routine deployments never invoke this migration.

Verify `php scripts/point-editor.test.php`, `php scripts/point-migration.test.php`, `node --test scripts/point-map.test.js` and `php scripts/calculator-lab.test.php`, in addition to the existing calculator tests. These tests do not replace an authenticated WordPress browser check of the actual map, Carbon Fields persistence and lab iframe.
