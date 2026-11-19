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

Pipeline: `npm ci` → `vite build --mode weblaba` → upload landing into child theme `landing/` + `pt-core` + MU → composer/activate/seed. Homepage (`front-page.php`) отдаёт `landing/preview.html`.

Required GitHub repo secret: **`WEBLABA_SSH_PRIVATE_KEY`** — PEM of `%USERPROFILE%\.ssh\weblaba_deploy`.

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
