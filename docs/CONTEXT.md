# Project context

Protransfer is a transfer booking landing with WordPress content management. Vite/Alpine sources live at the repository root; `wordpress/plugins/pt-core` owns WordPress data and calculator settings, and `wordpress/themes/protransfer` serves the built landing. WordPress staging and its operations are documented in [WORDPRESS-STAGING.md](WORDPRESS-STAGING.md).

The original static snapshot is preserved in [mvp/README.md](../mvp/README.md); it is historical and is not the source of the WordPress landing. Current fallback JSON remains in `src/data`; calculator defaults shared by PHP and JavaScript are in `wordpress/plugins/pt-core/data/calc.json`.

Landing copy defaults and editor labels are shared in `wordpress/plugins/pt-core/data/content.json`. The theme renders escaped text and SEO on the server and embeds public bootstrap data. Legal documents are served by the theme through `/?pt_legal=1`.

Staging owns CMS records and uploaded media. Local Vite proxies its API/media paths; a push to the configured main-branch paths builds and publishes through GitHub Actions/SSH. Routine deployments back up the current database and code, preserve editor data, and do not rerun seed. See the deployment domain document for access locations and rollback.

The booking form now creates real orders: the public form posts to `POST /wp-json/pt/v1/order`, and WordPress validates the request, recalculates the price on the server, stores a `pt_order` record and sends a Telegram notification when the bot settings are filled. A static/browser-only build without a backend keeps the localStorage draft and messenger handoff as a fallback. Full editor save-and-render acceptance remains incomplete.

Documentation index: [README.md](README.md).
