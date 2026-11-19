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

## Deploy from `main`

GitHub Actions: [`.github/workflows/deploy-weblaba.yml`](../.github/workflows/deploy-weblaba.yml)

Triggers on push to `main` when `wordpress/**` (or the deploy script/workflow) changes, plus `workflow_dispatch`.

Required GitHub repo secret: **`WEBLABA_SSH_PRIVATE_KEY`** — PEM contents of `%USERPROFILE%\.ssh\weblaba_deploy` (Settings → Secrets and variables → Actions). Without it the Weblaba workflow fails; local `npm run deploy:weblaba` still works with the key file.

Local deploy:

```bash
npm run deploy:weblaba
```

Uploads only `pt-core`, child theme and MU plugin; runs `composer install`, activates plugin/theme, `wp pt seed`.

Existing GitHub Pages workflow (`deploy.yml`) is unchanged for the static Vite demo.

## Seed / debug

```bash
wp pt seed
wp pt seed --force
```

Admin: **Тарифы → Отладка** — SIP→Ялта and other pairs, server-side quote breakdown.
