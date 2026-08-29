# Kavenegar Voice Calls for WooCommerce

Send configurable Kavenegar voice verification calls when WooCommerce order statuses change.

## Features

- Per-status voice-call rules
- Kavenegar Verify/Lookup `type=call` integration
- API key configured from WordPress admin instead of hardcoded source
- Per-status Kavenegar template
- Configurable `token`, `token10`, and `token20` sources
- Token sources: order ID/number, new status, customer name, phone, email, order total, site name
- Optional send/skip control on manual admin status changes
- Automatic sending for other enabled status transitions
- Filterable/paginated audit log
- Last call result and recent logs on each order
- WooCommerce HPOS compatibility
- WooCommerce CRUD for order data
- No site-specific domain, branding, user ID, or store configuration

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce
- Kavenegar account/API key
- Kavenegar Verify/Lookup voice-capable templates

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Kavenegar Voice Calls for WooCommerce**.
3. Open the plugin's status settings page.
4. Enter your Kavenegar API key.
5. Enable desired WooCommerce statuses, enter their Kavenegar template names, and map tokens.
6. Save settings.

## How It Works

The plugin listens to `woocommerce_order_status_changed`. For an enabled destination status it normalizes the billing phone, resolves configured tokens, sends Kavenegar Verify/Lookup with `type=call`, records the result, and stores a compact last-call result on the WooCommerce order.

## Phone Normalization

The provider integration currently accepts common Iranian mobile formats:

- `09xxxxxxxxx`
- `9xxxxxxxxx`
- `+989xxxxxxxxx`
- `00989xxxxxxxxx`

## Permissions

Admin pages require `manage_woocommerce`, not a hardcoded Administrator role.

## Data Storage

The plugin creates:

`{prefix}wckvc_order_voice_logs`

Operational logs may contain order identifiers, phone number, template, resolved tokens, provider result identifiers, timestamps, and errors.

The complete raw provider response is not persisted by the public version.

Latest compact order result:

`_wckvc_last_voice_call_result`

## Security & Privacy

- API key is not hardcoded.
- Settings use WordPress Settings API sanitization.
- Manual order-page send/skip control is nonce protected.
- Admin access is capability checked.
- SQL filter values are prepared/sanitized.
- Order information uses WooCommerce CRUD.
- Raw API responses are not persisted by default.

Phone numbers and token values are still operational personal data. Configure a suitable retention/privacy policy for your deployment.

## HPOS

HPOS compatibility is declared and order data is accessed through WooCommerce APIs.

## Limitations

- Kavenegar-specific integration; not a generic provider abstraction.
- No automatic retry queue.
- No automatic log-retention purge.
- Phone normalization currently targets Iranian mobile numbers.
- Kavenegar account limits, pricing, template configuration and provider availability are external to this plugin.

## Suggested GitHub Description

Send configurable Kavenegar voice calls on WooCommerce order status changes with token mapping, HPOS support, audit logs, and per-order results.

## License

GPL-3.0

## Author

Amirreza Shayesteh Far

---

# تماس صوتی کاوه‌نگار برای ووکامرس

افزونه‌ای عمومی برای ارسال تماس صوتی کاوه‌نگار هنگام تغییر وضعیت سفارش WooCommerce.

## امکانات

- قانون مستقل برای هر وضعیت سفارش
- API Key قابل تنظیم از پنل مدیریت
- Template مستقل برای هر وضعیت
- تعیین منبع `token`، `token10` و `token20`
- ثبت و فیلتر Log تماس‌ها
- نمایش آخرین نتیجه و Logهای اخیر در سفارش
- امکان لغو تماس هنگام تغییر دستی وضعیت
- سازگاری HPOS
- بدون API Key هاردکدشده
- بدون وابستگی به دامنه یا فروشگاه خاص

## حریم خصوصی

شماره موبایل و Tokenهای استفاده‌شده برای Audit عملیاتی در Log ذخیره می‌شوند؛ Raw Response کامل API در نسخه عمومی Persist نمی‌شود. برای Logها Retention Policy مناسب تعریف کنید.

## مجوز

GPL-3.0
