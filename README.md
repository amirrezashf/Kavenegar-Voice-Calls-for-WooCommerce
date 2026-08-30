# Kavenegar Voice Calls for WooCommerce

A production-oriented WooCommerce plugin for sending configurable **Kavenegar voice verification calls** when order statuses change.

The plugin lets store administrators define independent voice-call rules for WooCommerce statuses, choose the Kavenegar template used for each status, map dynamic order data to Kavenegar `token`, `token10`, and `token20`, inspect delivery/API results, and control calls triggered by manual order-status changes.

It is designed as a standalone plugin with WooCommerce HPOS support and without hardcoded API credentials or store-specific configuration.

## Features

- Send Kavenegar voice calls when WooCommerce order statuses change
- Independent enable/disable rule for each destination order status
- Independent Kavenegar template name for each status
- Configurable data source for:
  - `token`
  - `token10`
  - `token20`
- Supported token sources include:
  - Order ID
  - Order number
  - New order status
  - Customer name
  - Billing phone
  - Billing email
  - Order total
  - Site name
- Kavenegar API key configurable from WordPress admin
- No API key hardcoded in plugin source
- Manual send/skip control for administrator-triggered order status changes
- Automatic sending for other enabled status transitions
- Operational audit log
- Filterable and paginated log screen
- Last voice-call result stored on the WooCommerce order
- Recent call results visible from the order administration workflow
- WooCommerce HPOS compatibility
- WooCommerce CRUD usage for order information and order meta
- Iranian mobile-number normalization
- Provider error handling
- WordPress HTTP API integration
- No full raw Kavenegar response persisted by default
- No store-specific domain, user ID, branding, or production API credential

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce
- An active Kavenegar account
- A valid Kavenegar API key
- Kavenegar Verify/Lookup templates that support voice calls

Kavenegar account availability, API pricing, provider limits, template approval/configuration, and service availability are external to this plugin.

## Installation

1. Download the installable ZIP.
2. In WordPress admin, open **Plugins → Add New → Upload Plugin**.
3. Upload `Kavenegar-Voice-Calls-for-WooCommerce.zip`.
4. Install and activate the plugin.
5. Open the plugin's WooCommerce status/voice-call settings page.
6. Enter the Kavenegar API key.
7. Configure the statuses that should trigger calls.
8. Enter the corresponding Kavenegar template for each enabled status.
9. Configure the desired `token`, `token10`, and `token20` mappings.
10. Save the settings.

## Basic Configuration

The plugin is status-driven.

For every WooCommerce destination status you want to use, configure:

1. whether voice calling is enabled;
2. the Kavenegar template name;
3. the value source for `token`;
4. the value source for `token10`;
5. the value source for `token20`.

A status without a valid enabled rule is skipped.

## How It Works

The plugin listens to WooCommerce order status transitions.

When an order moves to a new status:

1. the destination status is normalized;
2. the plugin loads the configured rule for that status;
3. it checks whether voice calling is enabled;
4. it resolves the Kavenegar template;
5. it reads and normalizes the billing phone through the WooCommerce order object;
6. it resolves configured token values from order/site data;
7. it sends a Kavenegar Verify/Lookup request using `type=call`;
8. it interprets the HTTP/provider response;
9. it records the operational result;
10. it stores a compact last-call result on the order.

## Example Flow

Suppose an order changes from:

```text
processing → completed
```

and the `completed` rule is enabled.

The rule could conceptually use:

```text
Template: order-completed
token:    Order number
token10:  Customer name
token20:  Order total
```

The plugin resolves those values for the actual order and submits them to the configured Kavenegar voice template.

The exact spoken message is controlled by the template configured in the Kavenegar account.

## Token Mapping

The plugin supports dynamic values for Kavenegar template tokens.

Available sources include order/site information such as:

- order ID;
- displayed order number;
- destination status;
- customer name;
- billing phone;
- billing email;
- order total;
- WordPress site name.

This allows different statuses to use different voice messages without modifying plugin source code.

### Important Token Consideration

Kavenegar template/token requirements are provider-side concerns.

The configured template must be compatible with the values sent by the plugin. If the provider rejects a token or template, the call may fail even when WooCommerce integration is functioning correctly.

## Manual Status Changes

The plugin includes control over calls associated with manual administrative status changes.

This is useful when an administrator changes an order status but does not want that particular change to trigger a customer call.

The manual control is protected with WordPress nonce/capability checks.

Automated or other enabled transitions continue to follow the configured status rules.

## Phone Number Normalization

The current provider integration targets Iranian mobile numbers.

Common forms such as these are normalized for Kavenegar use:

```text
09xxxxxxxxx
9xxxxxxxxx
+989xxxxxxxxx
00989xxxxxxxxx
```

Invalid or unsupported phone values prevent the provider request from being treated as a normal successful call.

This normalization is intentionally provider/market specific and is documented as a limitation rather than presented as globally compatible phone handling.

## API Key Management

The Kavenegar API key is configured through WordPress administration.

It is **not hardcoded inside the PHP source file**.

The setting is sanitized before storage and the relevant administration page is restricted using the WooCommerce management capability.

The plugin does not intentionally expose the API key to frontend JavaScript.

## Kavenegar Integration

The plugin uses Kavenegar's Verify/Lookup workflow with:

```text
type=call
```

The request contains the configured template and resolved token values.

The plugin uses the WordPress HTTP API for external communication and handles WordPress-level HTTP failures separately from provider-level results.

A successful HTTP request alone should not be interpreted as proof that the customer actually received or answered a voice call. Provider result semantics and delivery behavior remain dependent on Kavenegar.

## Logs

The plugin maintains an operational log for voice-call attempts.

Depending on the event/result, log information can include operational fields such as:

- WooCommerce order identifier
- destination order status
- normalized recipient phone
- Kavenegar template
- resolved token values
- provider/result identifier
- result state
- error information
- timestamps

The log screen supports filtering and pagination so administrators do not need to inspect individual orders for every result.

## Order-Level Result

A compact representation of the latest voice-call result is stored on the WooCommerce order under:

`_wckvc_last_voice_call_result`

This allows the latest call state to remain associated with the relevant order.

The plugin uses WooCommerce CRUD/meta APIs for this data.

## Database Storage

The plugin creates an operational log table:

`{prefix}wckvc_order_voice_logs`

The actual prefix follows the active WordPress database prefix.

The plugin also uses WordPress options for configuration and WooCommerce order meta for the compact latest result.

### Main Stored Data

Configuration:

`wckvc_order_voice_status_settings`

Kavenegar API key:

`wckvc_kavenegar_api_key`

Database/schema version:

`wckvc_order_voice_status_db_version`

Latest order result:

`_wckvc_last_voice_call_result`

Log table:

`{prefix}wckvc_order_voice_logs`

## Privacy

Voice-call operation necessarily involves sending a customer's phone number and configured token values to Kavenegar.

The local audit log can also contain personal/operational information such as:

- phone number;
- order identifier;
- customer-derived token values;
- template;
- timestamps;
- errors/results.

Site operators should therefore account for this plugin and Kavenegar in their privacy documentation and retention policy.

The public plugin intentionally avoids persisting the complete raw provider response by default.

This reduces unnecessary storage of provider-returned data but does not make the feature free of personal-data processing.

## Security

The plugin includes the following safeguards:

- no hardcoded production API key;
- WordPress Settings API sanitization;
- WooCommerce management capability checks;
- nonce verification for relevant manual administrative actions;
- sanitized/normalized settings and input;
- prepared/sanitized database filtering;
- WooCommerce CRUD access for order data;
- no public arbitrary-send endpoint;
- no full raw API response persistence by default.

The Kavenegar API key is still a sensitive credential stored in the WordPress database. WordPress/database access must therefore be protected appropriately.

## WooCommerce HPOS

The plugin declares compatibility with:

`custom_order_tables`

Order information is accessed through WooCommerce order objects rather than direct assumptions about `wp_posts`/`wp_postmeta`.

This is important for stores using WooCommerce High-Performance Order Storage.

## Performance

The plugin performs work primarily when a relevant order status transition occurs.

It does not continuously scan all orders.

Operational logs are stored separately rather than growing a large serialized history inside every order.

The log administration interface is paginated/filterable.

For very high-volume stores, external API throughput, Kavenegar rate limits, synchronous request latency, database log retention, and retry strategy should be reviewed for the site's actual workload.

## Error Handling

Possible failures include:

- missing API key;
- invalid API key;
- missing/invalid status rule;
- missing Kavenegar template;
- missing/invalid billing phone;
- provider/network timeout;
- WordPress HTTP error;
- provider-side rejection;
- account/provider limits.

The plugin records operational failure information where appropriate so administrators can diagnose unsuccessful attempts.

## What the Plugin Does Not Do

The plugin does not:

- provide a generic multi-provider voice-call abstraction;
- replace Kavenegar account/template configuration;
- guarantee that a customer answers a call;
- bypass Kavenegar limits or pricing;
- provide a full asynchronous retry/queue system;
- automatically purge old logs;
- provide globally generic phone normalization;
- act as an SMS plugin;
- expose a customer-facing call-history dashboard.

## Limitations

- The integration is specifically designed for Kavenegar.
- Phone normalization currently targets Iranian mobile numbers.
- Kavenegar templates must already exist and be correctly configured provider-side.
- External API availability and latency are outside WordPress control.
- There is no built-in asynchronous retry queue.
- There is no automatic log-retention cleanup in version 1.0.0.
- Large/high-volume stores should define an appropriate log-retention policy.
- Provider API behavior may evolve independently of this plugin.

## Troubleshooting

### No call is triggered

Check:

1. the destination WooCommerce status has an enabled rule;
2. a Kavenegar template is configured for that status;
3. the API key is saved;
4. the order has a valid billing phone;
5. the phone can be normalized to the supported Iranian mobile format;
6. the status transition actually occurred;
7. the manual admin change was not intentionally marked to skip the call.

### Provider returns an error

Review the plugin log and verify:

- API key;
- Kavenegar account state;
- template name;
- template token requirements;
- recipient phone;
- provider limits.

### WooCommerce is inactive

The plugin requires WooCommerce. An administrative notice is displayed when the dependency is missing.

## Data Retention

Deactivating the plugin should not be treated as a request to destroy operational history.

Log/configuration/order-meta retention should be managed deliberately.

For production environments, define how long voice-call logs should be retained based on operational, legal, and privacy requirements.

## Suggested GitHub Description

`Send configurable Kavenegar voice calls on WooCommerce order status changes with token mapping, HPOS support, audit logs, and per-order results.`

## License

GPL-3.0

## Author

**Amirreza Shayesteh Far**

GitHub: `https://github.com/amirrezashf`

---

# تماس صوتی کاوه‌نگار برای ووکامرس

**Kavenegar Voice Calls for WooCommerce** یک افزونه مستقل برای WooCommerce است که هنگام تغییر وضعیت سفارش می‌تواند بر اساس قوانین قابل تنظیم، از طریق سرویس کاوه‌نگار برای مشتری **تماس صوتی خودکار** برقرار کند.

هدف افزونه این است که بدون Hardcode کردن API Key، Template یا اطلاعات فروشگاه در سورس، بتوان برای هر Status ووکامرس یک قانون مستقل تعریف کرد و اطلاعات سفارش را به `token`، `token10` و `token20` قالب صوتی کاوه‌نگار ارسال کرد.

افزونه علاوه بر ارسال تماس، نتیجه عملیات را ثبت می‌کند، Log مدیریتی دارد، آخرین نتیجه را به Order مرتبط می‌کند و برای WooCommerce HPOS طراحی شده است.

## قابلیت‌ها

- ارسال تماس صوتی کاوه‌نگار هنگام تغییر Status سفارش WooCommerce
- فعال یا غیرفعال‌کردن تماس به تفکیک هر Status
- تعیین Template مستقل برای هر وضعیت سفارش
- تعیین منبع مستقل برای `token`
- تعیین منبع مستقل برای `token10`
- تعیین منبع مستقل برای `token20`
- استفاده از اطلاعات واقعی Order به‌عنوان Token
- تنظیم API Key از پنل WordPress
- عدم Hardcode کردن API Key در فایل PHP
- امکان کنترل Send/Skip هنگام تغییر دستی وضعیت توسط مدیر
- ثبت Log تماس‌ها
- صفحه Log با Filter و Pagination
- ذخیره آخرین نتیجه تماس روی Order
- نمایش اطلاعات تماس‌های اخیر در Workflow مدیریتی سفارش
- سازگاری با HPOS
- استفاده از WooCommerce CRUD
- Normalize کردن شماره موبایل ایران
- مدیریت خطاهای شبکه و Provider
- استفاده از WordPress HTTP API
- عدم ذخیره Raw Response کامل کاوه‌نگار به‌صورت پیش‌فرض
- بدون وابستگی به Domain، Theme یا تنظیمات یک فروشگاه خاص

## نیازمندی‌ها

برای استفاده از افزونه به موارد زیر نیاز دارید:

- WordPress 6.0 یا جدیدتر
- PHP 7.4 یا جدیدتر
- WooCommerce
- حساب فعال کاوه‌نگار
- API Key معتبر کاوه‌نگار
- Templateهای Verify/Lookup مناسب تماس صوتی در حساب کاوه‌نگار

هزینه تماس، محدودیت حساب، وضعیت سرویس، قوانین Provider و تأیید/پیکربندی Templateها توسط کاوه‌نگار کنترل می‌شوند و جزو مسئولیت این افزونه نیستند.

## نصب

1. فایل ZIP افزونه را دانلود کنید.
2. در WordPress وارد **افزونه‌ها → افزودن افزونه تازه → بارگذاری افزونه** شوید.
3. ZIP را انتخاب و نصب کنید.
4. افزونه **Kavenegar Voice Calls for WooCommerce** را فعال کنید.
5. وارد صفحه تنظیمات تماس صوتی Statusها شوید.
6. API Key کاوه‌نگار را وارد کنید.
7. Statusهایی که باید تماس ایجاد کنند فعال کنید.
8. برای هر Status نام Template مربوط به کاوه‌نگار را وارد کنید.
9. منبع `token`، `token10` و `token20` را مشخص کنید.
10. تنظیمات را ذخیره کنید.

## ساختار تنظیمات Statusها

منطق افزونه بر اساس **وضعیت مقصد سفارش** است.

برای هر Status می‌توان مشخص کرد:

- تماس فعال باشد یا خیر؛
- از چه Template کاوه‌نگار استفاده شود؛
- مقدار `token` از کدام اطلاعات گرفته شود؛
- مقدار `token10` از کدام اطلاعات گرفته شود؛
- مقدار `token20` از کدام اطلاعات گرفته شود.

بنابراین برای مثال می‌توان برای `processing` یک پیام و برای `completed` پیام صوتی کاملاً متفاوتی داشت.

## نحوه عملکرد

وقتی Status یک Order تغییر می‌کند، افزونه این مراحل را طی می‌کند:

1. Status جدید را دریافت و Normalize می‌کند.
2. Rule مربوط به Status مقصد را می‌خواند.
3. بررسی می‌کند تماس برای آن Status فعال باشد.
4. Template کاوه‌نگار را دریافت می‌کند.
5. شماره Billing مشتری را از `WC_Order` دریافت می‌کند.
6. شماره را Normalize و Validate می‌کند.
7. مقادیر `token`، `token10` و `token20` را بر اساس تنظیمات Resolve می‌کند.
8. درخواست Verify/Lookup با `type=call` را به کاوه‌نگار ارسال می‌کند.
9. نتیجه HTTP و نتیجه Provider را بررسی می‌کند.
10. نتیجه عملیاتی را در Log ثبت می‌کند.
11. خلاصه آخرین نتیجه را روی Order ذخیره می‌کند.

## مثال

فرض کنید سفارش از:

```text
processing → completed
```

تغییر کند.

برای `completed` می‌توان Rule را به‌صورت مفهومی چنین تنظیم کرد:

```text
Template: order-completed
token:    شماره سفارش
token10:  نام مشتری
token20:  مبلغ سفارش
```

افزونه مقادیر واقعی همان Order را Resolve کرده و به Template کاوه‌نگار ارسال می‌کند.

متن نهایی که مشتری در تماس می‌شنود توسط Template تعریف‌شده در حساب کاوه‌نگار کنترل می‌شود.

## Tokenها

افزونه می‌تواند اطلاعات مختلفی را به Tokenهای کاوه‌نگار Map کند، از جمله:

- ID سفارش
- شماره سفارش
- Status جدید
- نام مشتری
- شماره موبایل Billing
- ایمیل Billing
- مبلغ Order
- نام سایت WordPress

این ساختار باعث می‌شود بدون تغییر سورس PHP بتوان برای Statusهای مختلف تماس‌های متفاوت تعریف کرد.

### نکته مهم درباره Template

Template و Tokenهای مورد انتظار آن در سمت کاوه‌نگار تعریف می‌شوند.

بنابراین Template انتخاب‌شده باید با Tokenهایی که افزونه ارسال می‌کند سازگار باشد.

اگر نام Template اشتباه باشد یا Template Token دیگری انتظار داشته باشد، Provider می‌تواند درخواست را Reject کند؛ حتی اگر بخش WooCommerce افزونه کاملاً درست کار کند.

## تغییر دستی وضعیت توسط مدیر

گاهی مدیر فروشگاه Status سفارش را دستی تغییر می‌دهد اما نمی‌خواهد در همان لحظه تماس صوتی برای مشتری ارسال شود.

افزونه برای این سناریو امکان کنترل Send/Skip را در Workflow تغییر دستی Status در نظر گرفته است.

عملیات مدیریتی مرتبط با Nonce و Capability مناسب محافظت می‌شود.

Transitionهای دیگر همچنان مطابق Ruleهای فعال پردازش می‌شوند.

## Normalize شماره موبایل

نسخه فعلی برای شماره‌های موبایل ایران طراحی شده است.

فرمت‌های رایج زیر قابل Normalize شدن هستند:

```text
09xxxxxxxxx
9xxxxxxxxx
+989xxxxxxxxx
00989xxxxxxxxx
```

اگر شماره Billing وجود نداشته باشد یا به فرمت قابل قبول تبدیل نشود، تماس نباید به‌عنوان عملیات موفق عادی پردازش شود.

این رفتار عمداً به‌عنوان یک محدودیت Provider/Market مستند شده و افزونه ادعا نمی‌کند Phone Normalization جهانی دارد.

## API Key

API Key کاوه‌نگار داخل فایل PHP Hardcode نشده است.

کلید از صفحه تنظیمات افزونه دریافت و در WordPress ذخیره می‌شود.

این مقدار:

- از طریق Settings API مدیریت می‌شود؛
- Sanitization دارد؛
- صفحه مدیریتی آن با Capability مناسب WooCommerce محدود شده است؛
- عمداً به Frontend JavaScript ارسال نمی‌شود.

API Key همچنان یک Secret حساس است و امنیت Database و دسترسی Administratorهای WordPress باید به‌درستی مدیریت شود.

## ارتباط با کاوه‌نگار

افزونه از Workflow مربوط به Verify/Lookup کاوه‌نگار با:

```text
type=call
```

استفاده می‌کند.

در Request، Template و Tokenهای Resolveشده ارسال می‌شوند.

ارتباط HTTP از طریق WordPress HTTP API انجام می‌شود.

خطای شبکه/WordPress با نتیجه‌ای که Provider برمی‌گرداند یکسان در نظر گرفته نمی‌شود.

همچنین HTTP Success به‌تنهایی به این معنی نیست که مشتری حتماً تماس را دریافت یا پاسخ داده است. Delivery و رفتار نهایی به کاوه‌نگار وابسته است.

## Log تماس‌ها

برای بررسی عملیاتی، افزونه Log تماس‌ها را نگهداری می‌کند.

بسته به نتیجه، Log می‌تواند شامل اطلاعاتی مانند موارد زیر باشد:

- Order ID
- Status مقصد
- شماره موبایل Normalizeشده
- Template
- Tokenهای Resolveشده
- شناسه/نتیجه Provider
- وضعیت عملیات
- Error
- Timestamp

صفحه Log دارای Filter و Pagination است تا بررسی تماس‌ها نیازمند بازکردن تک‌تک سفارش‌ها نباشد.

## آخرین نتیجه روی سفارش

خلاصه آخرین نتیجه تماس در Order Meta زیر نگهداری می‌شود:

`_wckvc_last_voice_call_result`

این داده از طریق WooCommerce CRUD مدیریت می‌شود.

## ساختار Storage

افزونه برای Log عملیاتی جدول زیر را ایجاد می‌کند:

`{prefix}wckvc_order_voice_logs`

Prefix واقعی بر اساس Prefix دیتابیس WordPress تعیین می‌شود.

تنظیم Ruleها:

`wckvc_order_voice_status_settings`

API Key:

`wckvc_kavenegar_api_key`

نسخه Schema:

`wckvc_order_voice_status_db_version`

آخرین نتیجه Order:

`_wckvc_last_voice_call_result`

## سازگاری با HPOS

افزونه Compatibility با:

`custom_order_tables`

را اعلام می‌کند.

اطلاعات Order از طریق `WC_Order` و WooCommerce CRUD خوانده و نوشته می‌شوند و پیاده‌سازی برای اطلاعات سفارش به ساختار قدیمی `wp_posts` و `wp_postmeta` وابسته نیست.

این موضوع برای فروشگاه‌هایی که **High-Performance Order Storage** را فعال کرده‌اند اهمیت دارد.

## Performance

افزونه هنگام Transition مرتبط Order فعال می‌شود و تمام سفارش‌ها را دائماً Scan نمی‌کند.

Logها در Storage جداگانه نگهداری می‌شوند تا یک History حجیم Serialized داخل هر Order ساخته نشود.

صفحه Log نیز Pagination و Filtering دارد.

با این حال در فروشگاه‌های بسیار پرترافیک باید موارد زیر متناسب با حجم واقعی بررسی شوند:

- Latency درخواست خارجی کاوه‌نگار
- Rate Limit سرویس
- تعداد تماس‌ها
- حجم Log
- Retention Policy
- نیاز یا عدم نیاز به Queue/Retry غیرهمزمان

نسخه `1.0.0` سیستم Queue/Retry کامل Async ندارد.

## امنیت

در نسخه عمومی موارد زیر لحاظ شده‌اند:

- API Key داخل سورس Hardcode نیست.
- Settings Sanitization دارد.
- دسترسی مدیریتی با Capability بررسی می‌شود.
- عملیات دستی حساس Nonce دارد.
- مقادیر ورودی و تنظیمات Normalize/Sanitize می‌شوند.
- Filterهای Database به‌شکل امن پردازش می‌شوند.
- Order Data از WooCommerce CRUD گرفته می‌شود.
- Endpoint عمومی برای ارسال دلخواه تماس وجود ندارد.
- Raw Response کامل Provider به‌صورت پیش‌فرض Persist نمی‌شود.

## حریم خصوصی

برای برقراری تماس، شماره مشتری و Tokenهای مورد نیاز به کاوه‌نگار ارسال می‌شوند.

Log محلی نیز می‌تواند اطلاعات عملیاتی/شخصی مانند موارد زیر داشته باشد:

- شماره موبایل
- Order ID
- Tokenهایی که ممکن است از اطلاعات مشتری ساخته شده باشند
- Template
- Timestamp
- Error/Result

بنابراین مدیر سایت باید این پردازش را در Privacy Policy و Data Retention Policy خود در نظر بگیرد.

نسخه عمومی Raw Response کامل کاوه‌نگار را به‌صورت پیش‌فرض ذخیره نمی‌کند تا از Persist شدن داده‌های غیرضروری جلوگیری شود.

## خطاهای احتمالی

تماس ممکن است به دلایل مختلف انجام نشود، از جمله:

- API Key ثبت نشده
- API Key نامعتبر
- Rule آن Status غیرفعال است
- Template مشخص نشده
- Template اشتباه است
- Billing Phone وجود ندارد
- شماره موبایل معتبر نیست
- Timeout شبکه
- `WP_Error`
- Reject شدن درخواست توسط Provider
- محدودیت حساب کاوه‌نگار
- اختلال سرویس خارجی

اطلاعات عملیاتی خطا تا حد مناسب در Log ثبت می‌شود تا امکان عیب‌یابی وجود داشته باشد.

## عیب‌یابی

### Status تغییر کرده ولی تماس انجام نشده

این موارد را بررسی کنید:

1. Rule مربوط به Status مقصد فعال باشد.
2. Template آن Status وارد شده باشد.
3. API Key ذخیره شده باشد.
4. Order دارای Billing Phone باشد.
5. شماره موبایل قابل Normalize باشد.
6. Status واقعاً Transition کرده باشد.
7. در تغییر دستی مدیر، گزینه Skip انتخاب نشده باشد.

### کاوه‌نگار Error برمی‌گرداند

Log را بررسی کنید و سپس موارد زیر را کنترل کنید:

- API Key
- وضعیت حساب کاوه‌نگار
- نام Template
- Tokenهای مورد انتظار Template
- شماره مقصد
- محدودیت‌های Provider

### WooCommerce فعال نیست

این افزونه به WooCommerce وابسته است و در نبود WooCommerce باید Notice مدیریتی نمایش دهد.

## چه کارهایی انجام نمی‌دهد؟

این افزونه:

- سیستم عمومی چند Provider نیست.
- جایگزین تنظیم Template در کاوه‌نگار نیست.
- دریافت/پاسخ‌دادن مشتری به تماس را تضمین نمی‌کند.
- محدودیت یا هزینه کاوه‌نگار را دور نمی‌زند.
- Queue/Retry کامل Async ندارد.
- Logهای قدیمی را خودکار Purge نمی‌کند.
- Phone Normalization جهانی ندارد.
- افزونه SMS عمومی نیست.
- Dashboard تاریخچه تماس برای مشتری ایجاد نمی‌کند.

## محدودیت‌ها

- Provider فقط Kavenegar است.
- Normalize شماره فعلاً برای موبایل ایران طراحی شده است.
- Template باید از قبل در کاوه‌نگار وجود داشته و درست تنظیم شده باشد.
- Availability و Latency سرویس خارجی تحت کنترل WordPress نیست.
- Retry Queue خودکار در نسخه فعلی وجود ندارد.
- Automatic Log Retention/Purge وجود ندارد.
- برای فروشگاه پرترافیک باید Retention Policy مناسب تعریف شود.
- تغییر API کاوه‌نگار در آینده ممکن است نیازمند Update افزونه باشد.

## نگهداری داده‌ها

Deactivate کردن افزونه نباید به‌معنی حذف خودکار Logهای عملیاتی تلقی شود.

حذف Logها و تنظیمات باید آگاهانه و مطابق سیاست نگهداری داده فروشگاه انجام شود.

برای Production بهتر است مدت نگهداری Log تماس‌ها بر اساس نیاز عملیاتی، قانونی و Privacy مشخص شود.

## GitHub Description پیشنهادی

`Send configurable Kavenegar voice calls on WooCommerce order status changes with token mapping, HPOS support, audit logs, and per-order results.`

## مجوز

GPL-3.0

## نویسنده

**Amirreza Shayesteh Far**

GitHub: `https://github.com/amirrezashf`
