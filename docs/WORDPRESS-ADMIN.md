# WordPress admin: sections and workflows

This is an operator guide to the current Protransfer staging site. The quoted labels below are the Russian labels in the admin interface. Use the [staging site](https://protransfer.weblaba.ru/) and its [WordPress admin](https://protransfer.weblaba.ru/wp-admin/). Credentials are stored outside Git as described in [staging operations](WORDPRESS-STAGING.md).

**Current boundary:** the site is a staging/demo environment. Prices, contacts, reviews and legal documents need business approval. Saving an editor change affects the staging site and the data used by the local frontend; Git deployment updates code, while editor content and media are stored on the staging server. A form submission on the site creates a «Заявка» in WordPress and, on production, sends a Telegram notification when the bot settings are filled. Staging stores orders but suppresses notifications (see [staging operations](WORDPRESS-STAGING.md)).

## Find the right section

| Admin section | What it controls | Typical task |
|---|---|---|
| «Общие данные» → «Настройки сайта» | Shared contacts, homepage copy, collections, SEO, hero image and legal text | Change a phone number, heading or photo |
| «Точки» | Places offered by the route picker, their coordinates and search aliases | Add an airport, station or city |
| «Точки» → «Маршруты» | Popular route pairs, tariff used on their cards and fallback road distances | Add a popular route or its manual distance |
| «Тарифы» | Vehicle classes, capacity, photos and pricing inputs | Change the minimum price or add a class |
| «Тарифы» → «Калькулятор» | Saved global calculation settings | Change rounding or an option price |
| «Тарифы» → «Отладка» | Interactive quote preview and global settings | Test a route and understand the formula |
| «Вопросы и ответы» | Question titles and answers | Add or edit a question |
| «Отзывы» | Review author and review fields | Edit a review |
| «Заявки» | Incoming orders, their prices, statuses and notification state | Inspect a new booking, confirm or cancel it, diagnose a Telegram failure |
| «Медиафайлы» | WordPress media library | Upload and reuse images |

Standard WordPress items such as «Записи», «Страницы», «Комментарии», «Внешний вид», «Плагины», «Пользователи» and «Настройки» also appear. They are WordPress administration areas; the landing's main editor is the project-specific menu above, not the default sample post or page. Do not change themes, plugin activation, users, permalinks or indexing settings as part of ordinary content editing.

## General workflow for an editor

1. Open the relevant section and select an existing record, or use its «Добавить» command.
2. Edit the named fields. For groups with multiple rows, add, edit, remove or reorder rows in the group editor as needed.
3. Save using the applicable button: «Обновить»/«Опубликовать» for a record, or «Сохранить изменения» for an options screen. The debugger has its own «Сохранить настройки» button.
4. Open or refresh the staging landing and check the changed section. For local development, reload the local page too; it reads the same staging backend through the development proxy.

All project post types use the named project boxes only. The standard «Произвольные поля» (Custom Fields) panel is not available on these screens, so there is no second, technical place to edit the same values. Carbon Fields controls and messages (for example «Добавить», «Свернуть все») follow the Russian admin language.

Only published points, tariffs, FAQs and reviews are read into the public data. Page attributes/order can affect the display order; check the landing after changing it. Avoid changing a point's slug after routes refer to it, because route rows store that slug as text.

## «Общие данные» → «Настройки сайта»

Open the single existing record «Настройки сайта». Its «Контакты и контент» box contains the brand, tagline, display phone, phone link, WhatsApp, Telegram, MAX, email, address, hours, Yandex Maps key, review rating and review count. The display phone and «Телефон (ссылка)» are separate fields: update both when the number changes. The map key field is «Ключ API Яндекс Карт», and the MAX field is «Ссылка MAX». WhatsApp is used as a number in a `wa.me` link; Telegram is a handle, and MAX is a URL. Check each link on the site after editing.

The same box contains the four order-notification fields: «Telegram: токен бота», «Telegram: chat id», «MAX: токен бота» and «MAX: chat id группы». They are used by «Заявки»; see the notification instructions in that section. Leave a pair empty to keep orders in WordPress without delivery to that channel.

The same box holds repeatable «Статистика», «Преимущества», «Шаги», «Услуги» and «Оплата» rows. Their text and order feed the corresponding homepage lists. Icon fields contain icon identifiers used by the existing site icon set, not arbitrary image uploads; keep an existing working identifier when changing only text.

Boxes named «Лендинг: …» contain the visible landing copy by section: «Первый экран», «Навигация», «Преимущества», «Автопарк», «Направления», «Как это работает», «Услуги», «Отзывы», «Вопросы и ответы», «Калькулятор», «Бронирование», «Бренд», «Контакты», «Подвал» and «Документы». The box «Лендинг: Контакты и связь» contains additional contact/call-to-action text. The hero title is split into beginning, highlighted word and ending. The copy boxes cover the static visible text of the landing: header and mobile navigation, section headings and subtitles, calculator step labels and options, the booking dialog with its form labels and placeholders, contact-card captions, footer navigation and legal link labels. A placeholder field changes the grey hint inside a calculator or booking input. Values assembled from live records — prices, selected point and tariff names, review and FAQ content, rating and kilometre counts — are not copy fields; edit the source record instead. Captions inside the repeated tariff-card template and dynamic button states also stay in code.

To find a specific line, open the box for its section — «Навигация» for the header and mobile menu, «Калькулятор» for the calculation form, «Бронирование» for the booking dialog and form, «Подвал» for footer links — then scan the field labels, which name the visible location. The sticky tab strip opens any box directly.

«Поисковые системы и обложка» contains the SEO title/description and the first-screen image. Pick an image from the media library and save the record. An unset hero image falls back to the image in the built site. The legal copy in «Лендинг: Документы» feeds the WordPress documents page; have the business owner approve its contents before public use.

For landing-copy fields, an unset field uses initial text; an explicitly saved empty field can be empty on the site. Verify the visible result after saving. The static demo uses bundled initial content rather than the WordPress editor.

### Navigating the settings screen

A sticky strip of tabs sits above the first project box. It has one entry per project box; the label is the box heading without the «Лендинг: » prefix, so «Первый экран» opens «Лендинг: Первый экран», while «Контакты и контент» and «Поисковые системы и обложка» keep their full headings. Selecting a tab shows only that box and hides the other project boxes. The standard WordPress panels, including «Опубликовать», «Ярлык» and «Настройки экрана», remain visible and are not part of the tab set.

The active tab is highlighted. Its identifier is stored in the URL hash, for example `#pt-settings-pervyy-ekran`, so a link can open one group directly, and the last opened group is also remembered in the browser's local storage for the current WordPress user. A reload therefore returns to the same group. Use the arrow keys to move between tabs and Enter or Space to open the focused tab.

A red dot on a tab means that the group contains edits whose current values differ from the values loaded with the page; the same mark appears if a repeatable row is removed or duplicated. If any group still has such edits, leaving the page triggers the browser's standard unsaved-changes prompt. «Обновить» or «Опубликовать» saves and clears the marks. Do not use the prompt as a substitute for saving: it only warns, and it does not keep a copy of the draft.

On wide screens, plain single-line text, number and checkbox fields in a project box are arranged two per row to shorten the group. Text areas, images and repeatable/complex rows keep their full width and existing field proportions.

### Scenario: change contacts

1. Open «Общие данные» → «Настройки сайта» → «Контакты и контент».
2. Update the visible phone, its link value, and any affected messenger/email fields.
3. Click «Обновить», reload the landing, and test the phone and messenger links. Do not rely only on the text displayed in the editor.

## «Точки»

Each published point represents a named place that can be selected in the calculator. Its record has a title, an optional linked settlement, latitude, longitude and «Варианты поиска». Coordinates drive map placement and distance estimation; search variants are alternate names. The former point type field was removed: a point is identified by its name alone («станция Керчь-Южная»), and the title is what route lists and the calculator show. The `pt_point_type` taxonomy stays registered only for legacy stored terms; it is hidden from the editor and is not part of the public point payload, so do not look for a type selector in the point screen.

The point screen has no separate city text field and no «Произвольные поля» panel. The city is defined by «Относится к населённому пункту»; legacy city text from older records is kept server-side for unlinked or unavailable parents and is not shown as an editable input. The former «Pt City» field was removed, so do not look for it in the «Точка» box.

### Scenario: add a point

1. Choose «Точки» → «Добавить: Точка» and enter its name. Under «Расположение и принадлежность», optionally select an existing top-level point as its parent. Click the map or drag its marker to set coordinates; manual latitude/longitude fields remain available when the map fails.
2. Add useful «Варианты поиска», one row per variant, when the point is known by other names (for example «жд керчь южная»).
3. Publish the record. Note its slug: route options refer to this identifier, not the displayed title.
4. Refresh the landing and verify search, map placement and a route to another point. The calculator accepts the published point list; it is not a general arbitrary-address geocoder.

Parent choices are published points that do not belong to another point themselves (no stored parent link, or an explicit «не относится»); a linked point is a station, an airport or a district inside its settlement and is not offered as a parent. A point cannot be its own parent or create a cycle. A linked parent supplies its current name; unchanged legacy text is retained for unlinked or unavailable parents. The migration dry run and rollback are documented in [staging operations](WORDPRESS-STAGING.md). If a point is missing, check publication status, search variants and whether the browser is showing the current staging data. Do not assume that changing a title also updates references to its slug.

## «Точки» → «Маршруты»

- «Тариф для цен популярных направлений» chooses the tariff whose one-way estimate appears on popular-route cards. If the selected tariff is unavailable, the frontend falls back to the first available tariff.
- «Популярные направления» stores pairs of points. Choose the departure and destination from the «Откуда» and «Куда» lists instead of typing codes; the lists show published points by title, alphabetically. Each row still stores the point code (slug), and both points must be published. Cards whose points cannot be found are omitted.
- «Ручные км» stores a positive distance for a route pair chosen in the same way from «Откуда» and «Куда». The same distance is used in both directions. This is a **fallback distance**, not a fixed route price and not a guaranteed override of live routing.

### Scenario: add a popular route

1. In «Маршруты», add a row to «Популярные направления».
2. Choose the departure and destination points in «Откуда» and «Куда» and save. The row keeps the point codes, so normal editing only needs the readable lists.
3. If needed, add the same pair with a positive value under «Ручные км» and save.
4. Refresh the site, open the route card and verify the calculator. The cards use an estimate; the calculator may later receive a different road distance from a routing provider.

Both route groups start with «— выберите точку —». If a saved row refers to a code that no longer matches a published point, that group gets an extra option such as `sip-air — точка не найдена`; keep it selected to preserve the route, or replace it deliberately. Renaming a point's title is safe because its code does not change. Changing a point's code (slug) still breaks saved route rows; if it cannot be avoided, re-select both points in every affected row. Each route group shows the same warning once in its help text. A row header shows the names of the selected departure and destination points, not their codes, and updates immediately when either selection changes; «Ручные км» rows also show the entered distance.

For the website calculator, routing is attempted through Yandex when configured, then OSRM; the manual distance or a coordinate-based estimate is the fallback. A successful provider response can replace the initial fallback. The admin debugger instead uses manual route distance or coordinate estimation unless a distance is entered explicitly. The estimated travel time can also differ from a provider's time.

## «Тарифы»

Each published tariff defines the vehicle class name, image, seats, baggage, vehicle examples, description, base amount, per-kilometre rate, minimum price, badge and «Цена по запросу». A record's featured image («Изображение записи») takes precedence over its «Фото авто» field. The number of seats controls whether the tariff fits the passenger count; the maximum passenger selector is derived from the available tariffs.

### Scenario: change a tariff price or photo

1. Open «Тарифы» and the desired published class.
2. Edit «База, ₽», «₽ / км» and «Минималка, ₽»; use the image field or featured image for a new photo.
3. Click «Обновить» and open «Тарифы» → «Отладка» to test a representative route. Then verify the tariff card and calculator on the landing.
4. To display «По запросу» instead of a calculated amount, enable «Цена по запросу» and check the result on the site.

The tariff formula is `max(minimum, base + distance × rate)`. Optional round-trip factor, extra stop and child seat are applied afterwards, followed by the configured price rounding. A tariff's minimum is not a fixed price for every route.

## «Тарифы» → «Калькулятор» and «Отладка»

«Калькулятор» holds global values shared across tariffs: road factor, distance rounding, three estimated speeds and their distance thresholds, time buffer and rounding, round-trip factor, extra-stop price, price rounding and child-seat price. A child-seat price of zero makes that option free. The road factor and estimated speeds apply to coordinate-based fallback estimates; live routing can provide its own distance and time.

The debugger embeds the actual customer calculator above a detailed live client-side formula. Its route, selected tariff, options and displayed distance synchronize to the server comparison below. The server comparison also has route and tariff selectors, option checkboxes, an optional «Расстояние с сайта, км» field, a calculation breakdown and editable global settings beside it. Selector, option and setting changes recalculate the preview. **Changing the settings in this preview does not change the site until «Сохранить настройки» is pressed.** The «Обновить расчёт» button requests a fresh calculation. Saving applies global settings, while tariff-specific amounts remain in each tariff record.

### Scenario: test a price without publishing a setting

1. In the embedded site calculator, choose different points under «Откуда» and «Куда», then select a tariff.
2. Turn options on or off. Read the distance source, `base + rate × distance`, minimum, multiplier, surcharges, rounding and total in «Расчёт».
3. The embedded calculator passes its displayed kilometres to the server comparison. You can replace that distance for the selected pair. Choosing a different pair in the server form clears the distance, so the server estimates the new route. The server's time is always an estimate.
4. Change a setting to explore a result in both previews. The client preview receives only values accepted by the server comparison. Leave without pressing «Сохранить настройки» if the change is only a test. Save only after confirming the intended global effect.
5. The embedded customer calculator can call Yandex/OSRM. Use the separate server comparison for the same price calculation with its own estimated time. Booking controls are disabled in the test view.

## «Вопросы и ответы», «Отзывы» and «Медиафайлы»

For «Вопросы и ответы», the record title is the question and «Ответ» is its answer. Publish the record and check the question/answer on the landing.

For «Отзывы», the title is the author/name. Edit city, route, rating, date label and review text; publish only approved real content. The separately configured aggregate rating/count in «Общие данные» is not automatically recalculated from review records.

Use «Медиафайлы» to upload/select images. Changing a media library item or choosing a different hero/tariff image requires checking the affected public card or hero after saving. For tariffs, remember the featured-image priority above.

## «Заявки»: incoming orders

The public booking form posts to `POST /wp-json/pt/v1/order`. WordPress validates the request, resolves the selected points and tariff, recalculates the price on the server, stores a published `pt_order` record and then tries to notify the configured Telegram chat. The browser shows «Заявка принята» only after the endpoint returns `201`. A validation or server error leaves the booking form open with the server's Russian message; a browser-only/static build or an unavailable backend falls back to the offline draft and the messenger handoff.

Each order stores:
- a title in the form `Имя · Откуда → Куда · ГГГГ-ММ-ДД ЧЧ:ММ`;
- «Телефон» (normalised to 11 digits), «Откуда», «Куда», «Тариф», «Цена», «Дата/время» and «Снимок данных заявки» with the validated request and the distance source;
- status «Новая»;
- the server-calculated price. If the browser supplied the kilometres it displayed, the server uses that distance so the stored price matches the quote the customer saw; otherwise it uses «Ручные км» or the coordinate estimate from «Маршруты». A differing client-declared total is kept in a hidden record for comparison.

The «Заявки» list shows «Дата и время», «Телефон» as a clickable `tel:` link, «Маршрут», «Цена», «Статус» and «Уведомление». The date column is sortable, and the status filter above the list narrows the view. The edit screen still contains «Телефон», «Откуда», «Куда», «Тариф», «Цена», «Дата/время», «Снимок данных заявки» and «Статус».

Statuses are «Новая», «Подтверждена», «Выполнена» and «Отменена». They are maintained manually; the public form always creates a «Новая» record. An empty list now means that no order reached WordPress (or that the current filter hides it), not that the form is disconnected. The visitor's browser keeps a local draft as a convenience only; WordPress is the source of truth.

### Notification settings and failures

Enter the credentials in «Общие данные» → «Настройки сайта» → «Контакты и контент»:
- «Telegram: токен бота» — the token from Telegram's BotFather;
- «Telegram: chat id» — the personal, group or channel chat identifier (group IDs are usually negative). Add the bot to that chat first.
- «MAX: токен бота» — the token the MAX business bot gives you at `https://max.ru/business_bot` (MAX requires the bot to be registered to a company or self-employed account);
- «MAX: chat id группы» — the group identifier, usually negative, e.g. `-19000000000001`. Add the bot to that group first.

Each filled pair turns its channel on; an empty pair keeps it silent without recording an error. Every new order then goes to both channels with the same content — order number, route, date/time, tariff, passengers, price, name, phone, options and comment:

- Telegram gets an HTML message at `https://api.telegram.org/bot<token>/sendMessage`, values escaped, `parse_mode=HTML`;
- MAX gets a markdown message at `https://botapi.max.ru/messages?chat_id=<id>` with the token in the `Authorization` header and `format=markdown`.

The MAX message carries no buttons and no link to the order: MAX rejects non-http(s) button URLs, so a `tel:` «Позвонить» button is impossible, and the business decision is phone-only — the number sits on its own «Телефон» line and the MAX client makes it tappable. A missing setting or a network/broker error never deletes the order: the «Уведомление» column shows «Ошибка: …» with the channel name (`Telegram:` or `MAX:`), and the full text is stored with the order. Check the column after a test booking.

When Telegram fails:
1. Open the order and read the «Уведомление» error. «chat not found» or «bot was blocked» usually means a wrong «Telegram: chat id» or that the bot is not a member of the chat.
2. Correct the token/chat id in «Контакты и контент», save, and make another test booking. There is no retry button for an already stored order.
3. Contact the customer from the stored phone even if the notification failed. Never rely on the «Уведомление» column alone: an order with no notification settings is still a real order.
4. Staging deliberately suppresses notifications even when the fields are filled; see [staging operations](WORDPRESS-STAGING.md).

When MAX fails:
1. Read the «Уведомление» error after «MAX:». `verify.token` or `Invalid access_token` means the token is wrong or was reissued in the MAX business bot; `chat not found` (HTTP 404) means the bot is not a member of that group or the id is wrong.
2. Fix the field in «Контакты и контент», save, and make another test booking. There is no retry button for an already stored order.
3. Check the bot is still in the group: the MAX group admin can remove it, and the bot then loses the right to post.

MAX is an approved notification channel: the client asked for leads in a closed MAX group and registered the bot himself, so the code sends to both Telegram and MAX. Do not add a third external service without an approved business decision — the channels go through the `pt_order_notification_channels` filter and each one is a separate `pt_notify_<channel>()` function.

## Common checks and escalation

- **Change is missing:** confirm the right record and save button, published status, the correct staging URL and a fresh browser reload. On localhost, check that the staging API is reachable; an unavailable API or empty points/tariffs can activate demo JSON.
- **Price differs from the debugger:** compare the distance source and enter the site's kilometres in the debugger. Check tariff amounts, selected options, the round-trip factor and price rounding. Time can still differ.
- **A field or icon is missing on the landing:** check the matching «Лендинг: …» box first; text assembled from live data (prices, point names, reviews, FAQ, ratings) and captions inside repeated card templates are generated, so edit the source record instead. An unknown icon identifier may not exist. Record the exact section and field for development rather than changing unrelated WordPress settings.
- **A route card is missing:** check both exact slugs and that both points are published. A manual kilometre row is a fallback and does not guarantee the provider's result.
- **A form submission failed:** read the Russian message under the form. The visitor's data stays in place, so the message can be reported without asking for it again. A repeated failure on every submission may mean the order endpoint is unavailable, the nonce-free route is blocked by a security plugin, or the rate limit was reached.
- **An order arrived without a Telegram message:** open «Заявки» and compare «Уведомление». Fix the two Telegram fields and test with a new booking; the stored order is not lost and must still be handled from its record.
- **Need an initial import, deployment, rollback or access recovery:** use the operations instructions in [WordPress staging](WORDPRESS-STAGING.md). Normal editing does not require running seed, especially `--force`.

The menu labels and debugger layout were inspected on the staging admin. This guide also reflects source-level behavior; it is not a completed field-by-field save/reload acceptance test.
