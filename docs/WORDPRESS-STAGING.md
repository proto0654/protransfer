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

Stack: WordPress (ru), GeneratePress parent + `protransfer` child, Carbon Fields via Composer in `pt-core`, MU `pt-staging.php` (noindex, no mail).

## Local Vite → WP proxy

`npm run dev` proxies:

- `/wp-json` → `https://protransfer.weblaba.ru`
- `/wp-content/uploads` → same

The landing loads `/wp-json/pt/v1/bootstrap` first; if WP is down, falls back to `src/data/*.json`. Force JSON: `VITE_DATA_SOURCE=json`.

Same idea as weblaba-nuxt (`/api/wp/**` → api.weblaba.ru) and eastagro (localhost → stage).

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

Admin: **Тарифы → Отладка** — SIP→Ялта and other pairs, server-side quote breakdown.

## Calculator settings and testing

Calculator defaults are shared by PHP and the static frontend through `wordpress/plugins/pt-core/data/calc.json`. In WordPress, edit the saved values under **Тарифы → Калькулятор** and each tariff in **Тарифы**. **Тарифы → Отладка** provides route, tariff and options selectors, a live server-side breakdown and editable settings alongside it. Edits immediately update the preview without changing the site; press **Сохранить настройки** to apply them. Invalid non-positive divisors and speeds fall back to defaults.

The website requests Yandex and OSRM before falling back to manual route distances and a coordinate estimate. The server debugger does not call those services. Enter the distance shown on the website in its distance field to compare prices; the displayed server time remains an estimate. The debugger does not submit an order. The landing calculates prices in the browser, and the existing quote endpoint is not an order or payment integration.

Run `node --test scripts/calculator.test.js` with PHP and Node installed to compare the client and server formulas. Landing copy and legal text are managed through the site settings fields described below.

## Landing content and theme integration

Edit **Общие данные → Настройки сайта** to manage brand, contacts, SEO title/description, the hero image, and grouped landing copy. The content schema in `wordpress/plugins/pt-core/data/content.json` defines labels and initial text for the same fields used by PHP and Vite. An unset field receives its initial text; explicitly saving empty text keeps it empty. The historical files under `mvp` are not modified by content updates.

FAQ, reviews, fleet and points retain their own editors. **Точки → Маршруты** selects the tariff displayed on popular route cards. The passenger limit is derived from fleet capacity. Form control labels, SVG decoration and layout remain template code.

The child theme renders the built landing through WordPress, applies escaped content and SEO metadata, embeds public bootstrap data, and calls `wp_head`, `wp_body_open` and `wp_footer`. The frontend uses that embedded data without a second bootstrap request. Local Vite still uses the REST proxy, with an eight-second timeout and bundled JSON fallback. The main and preview source entries must remain identical.

Legal documents are edited in the site's **Документы** content group and filtered through `wp_kses_post`. On WordPress, links use `/?pt_legal=1#privacy`, `#consent` and `#offer`; the same theme serves the built legal page. Static builds continue using `legal.html`. Existing legal copy is only an initial template and still needs the business owner's approval before publication.

Additional verification: `node --test scripts/content.test.js` and `php scripts/landing-content.test.php`. These check field coverage, fallback behavior and escaped server rendering without a full WordPress runtime. A browser check against WordPress with Carbon Fields 3.6.11 is still required before publishing.

## Deployment backups and rollback

The same server identity and preservation rules as the Znak Vechnosti staging deployment apply. The script accepts only the documented Protransfer docroot. Before any upload it exports the current database, compresses and verifies it, and archives `wp-content`, `wp-config.php` and an existing `.htaccess` into the private `~/.local/share/protransfer/backups/<UTC timestamp>-<commit>/` directory. The backup directory records the target commit; successful publication records `~/.local/share/protransfer/deployed-commit`.

GitHub deployments are serialized without cancelling an in-progress upload. SSH scripts are sent through stdin to Bash so shell variables and multiline commands reach the server intact. Failed backups and Composer commands stop deployment. Existing uploads, configuration and editor data are preserved; seed remains a separate, explicit first-install/content-import operation.

For rollback, restore the matching code archive from that backup. Restore its database only when required and explicitly authorized; do not use the older pre-WordPress database dump for ordinary rollback. Inspect the backup manifest and archive contents before restoring. Deployment is currently an in-place upload, so review the run log and public responses if a transfer fails midway.
