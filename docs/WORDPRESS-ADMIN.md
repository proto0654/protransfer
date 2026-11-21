# WordPress admin: sections and workflows

This is an operator guide to the current Protransfer staging site. The quoted labels below are the Russian labels in the admin interface. Use the [staging site](https://protransfer.weblaba.ru/) and its [WordPress admin](https://protransfer.weblaba.ru/wp-admin/). Credentials are stored outside Git as described in [staging operations](WORDPRESS-STAGING.md).

**Current boundary:** the site is a staging/demo environment. Prices, contacts, reviews and legal documents need business approval. Saving an editor change affects the staging site and the data used by the local frontend; Git deployment updates code, while editor content and media are stored on the staging server. A form submission on the site does **not** create an order in WordPress or send it automatically.

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
| «Заявки» | Manual order records and statuses | Inspect or enter a record manually; the public form is not connected |
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

The same box holds repeatable «Статистика», «Преимущества», «Шаги», «Услуги» and «Оплата» rows. Their text and order feed the corresponding homepage lists. Icon fields contain icon identifiers used by the existing site icon set, not arbitrary image uploads; keep an existing working identifier when changing only text.

Boxes named «Лендинг: …» contain selected headings and paragraphs by section: «Первый экран», «Преимущества», «Автопарк», «Направления», «Как это работает», «Услуги», «Отзывы», «Вопросы и ответы», «Бренд», «Контакты», «Подвал» and «Документы». The box «Лендинг: Контакты и связь» contains additional contact/call-to-action text. The hero title is split into beginning, highlighted word and ending. These boxes cover selected content; some UI labels and other template text remain in code.

«Поисковые системы и обложка» contains the SEO title/description and the first-screen image. Pick an image from the media library and save the record. An unset hero image falls back to the image in the built site. The legal copy in «Лендинг: Документы» feeds the WordPress documents page; have the business owner approve its contents before public use.

For supported landing-copy fields, an unset field uses initial text; an explicitly saved empty field can be empty on the site. Verify the visible result after saving. The static demo uses bundled initial content rather than the WordPress editor.

### Scenario: change contacts

1. Open «Общие данные» → «Настройки сайта» → «Контакты и контент».
2. Update the visible phone, its link value, and any affected messenger/email fields.
3. Click «Обновить», reload the landing, and test the phone and messenger links. Do not rely only on the text displayed in the editor.

## «Точки» and «Типы точек»

Each published point represents a named place that can be selected in the calculator. Its record has a title, an optional linked settlement, latitude, longitude, type and «Варианты поиска». Coordinates drive map placement and distance estimation; search variants are alternate names. The type categorizes the point, for example city, airport, railway station, port or resort. Type names appear in Russian: «Город», «Курорт или посёлок», «Аэропорт», «Железнодорожный вокзал», «Морской порт».

The point screen has no separate city text field and no «Произвольные поля» panel. The city is defined by «Относится к населённому пункту»; legacy city text from older records is kept server-side for unlinked or unavailable parents and is not shown as an editable input. The former «Pt City» field was removed, so do not look for it in the «Точка» box.

### Scenario: add a point

1. Choose «Точки» → «Добавить: Точка» and enter its name. Under «Расположение и принадлежность», optionally select an existing published city or resort as its parent. Click the map or drag its marker to set coordinates; manual latitude/longitude fields remain available when the map fails.
2. Select the appropriate existing point type and add useful «Варианты поиска», one row per variant.
3. Publish the record. Note its slug: route options refer to this identifier, not the displayed title.
4. Refresh the landing and verify search, map placement and a route to another point. The calculator accepts the published point list; it is not a general arbitrary-address geocoder.

Parent choices are published points with the «Город» or «Курорт или посёлок» type (`city` or `resort` in stored data); a point cannot be its own parent or create a cycle. A linked parent supplies its current name; unchanged legacy text is retained for unlinked or unavailable parents. The migration dry run and rollback are documented in [staging operations](WORDPRESS-STAGING.md). If a point is missing, check publication status, search variants, type and whether the browser is showing the current staging data. Do not assume that changing a title also updates references to its slug.

## «Точки» → «Маршруты»

- «Тариф для цен популярных направлений» chooses the tariff whose one-way estimate appears on popular-route cards. If the selected tariff is unavailable, the frontend falls back to the first available tariff.
- «Популярные направления» stores pairs of point codes (slugs). Each pair must refer to two published points. Cards whose points cannot be found are omitted.
- «Ручные км» stores a positive distance for a pair of codes. The same distance is used in both directions. This is a **fallback distance**, not a fixed route price and not a guaranteed override of live routing.

### Scenario: add a popular route

1. Find the two published point slugs in «Точки».
2. In «Маршруты», add a row to «Популярные направления», enter the exact departure and destination codes (the «Откуда (код точки)» and «Куда (код точки)» fields) and save.
3. If needed, add the same pair with a positive value under «Ручные км» and save.
4. Refresh the site, open the route card and verify the calculator. The cards use an estimate; the calculator may later receive a different road distance from a routing provider.

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

## «Заявки»: present but not connected

The section has fields for phone, route, tariff, price, date/time, a JSON snapshot and status («Новая», «Подтверждена», «Выполнена», «Отменена»). These are fields on a WordPress record, not evidence of an incoming-order pipeline.

Currently, submitting the landing form validates it, saves a copy in the visitor's browser localStorage where available, and displays a prepared message/call options. A visitor must use the offered action to contact the business; WordPress does not automatically receive that submission. Do not use an empty «Заявки» list as proof that no customers submitted the form. A server-side intake, validation, trusted price calculation and notification channel still need implementation and acceptance.

## Common checks and escalation

- **Change is missing:** confirm the right record and save button, published status, the correct staging URL and a fresh browser reload. On localhost, check that the staging API is reachable; an unavailable API or empty points/tariffs can activate demo JSON.
- **Price differs from the debugger:** compare the distance source and enter the site's kilometres in the debugger. Check tariff amounts, selected options, the round-trip factor and price rounding. Time can still differ.
- **A field or icon is missing on the landing:** some template text is not editable yet, and an unknown icon identifier may not exist. Record the exact section and field for development rather than changing unrelated WordPress settings.
- **A route card is missing:** check both exact slugs and that both points are published. A manual kilometre row is a fallback and does not guarantee the provider's result.
- **Need an initial import, deployment, rollback or access recovery:** use the operations instructions in [WordPress staging](WORDPRESS-STAGING.md). Normal editing does not require running seed, especially `--force`.

The menu labels and debugger layout were inspected on the staging admin. This guide also reflects source-level behavior; it is not a completed field-by-field save/reload acceptance test.
