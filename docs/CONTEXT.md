# Project context

Protransfer is a transfer booking landing with WordPress content management. Vite/Alpine sources live at the repository root; `wordpress/plugins/pt-core` owns WordPress data and calculator settings, and `wordpress/themes/protransfer` serves the built landing. WordPress staging and its operations are documented in [WORDPRESS-STAGING.md](WORDPRESS-STAGING.md).

The original static snapshot is preserved in [mvp/README.md](../mvp/README.md); it is historical and is not the source of the WordPress landing. Current fallback JSON remains in `src/data`; calculator defaults shared by PHP and JavaScript are in `wordpress/plugins/pt-core/data/calc.json`.

Landing copy defaults and editor labels are shared in `wordpress/plugins/pt-core/data/content.json`. The schema covers the static visible landing copy, including navigation, calculator labels, booking-form labels and input placeholders; values assembled from live records and captions inside repeated Alpine card templates stay in code. The theme renders escaped text and SEO on the server and embeds public bootstrap data. Legal documents are served by the theme through `/?pt_legal=1`.

Staging owns CMS records and uploaded media. Local Vite proxies its API/media paths; a push to the configured main-branch paths builds and publishes through GitHub Actions/SSH. Routine deployments back up the current database and code, preserve editor data, and do not rerun seed. See the deployment domain document for access locations and rollback.

The booking form now creates real orders: the public form posts to `POST /wp-json/pt/v1/order`, and WordPress validates the request, recalculates the price on the server, stores a `pt_order` record and sends a Telegram and MAX notification when the matching bot settings are filled. A static/browser-only build without a backend keeps the localStorage draft and messenger handoff as a fallback. Landing-copy save-and-render and order creation were verified on the stand; a field-by-field acceptance across every editor screen is still incomplete.

Crossing the Kerch strait: pairs between Crimea and the mainland use a land bypass corridor (Perekop, Melitopol, Berdyansk, Mariupol, Rostov) and an optional two-car train transfer via Kerch-Yuzhnaya and Taman-Passazhirskaya. The station and timing settings live in «Точки → Маршруты»; the corridor coordinates live in `routes.json` under `crossing.corridor`.

Documentation index: [README.md](README.md).
