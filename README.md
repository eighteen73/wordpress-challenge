# Challenge

Challenge is our agency's shared anti-bot plugin for WordPress websites. It provides consistent CAPTCHA protection for the public-facing forms and customer journeys we commonly use: Gravity Forms submissions, WooCommerce guest checkout, account registration and password-reset requests.

Each website uses one configured provider: Cap, Cloudflare Turnstile or Google reCAPTCHA. Protection is focused on these supported submission points rather than site-wide bot filtering.

## Covered submissions

- Gravity Forms, including AJAX forms. **Automatic protection** is enabled by default and protects every form. Uncheck it to protect only forms containing a **Challenge** field in the Gravity Forms editor. With automatic protection enabled, adding the field gives the widget deliberate inline placement, regardless of Cap's site-wide Display setting. Cap uses the selected display mode for automatically protected forms; Turnstile and reCAPTCHA v2 appear beside submit; reCAPTCHA v3 is invisible. On multi-page forms, place the field on the final page.
- Gravity Forms Save and Continue: saving a draft and requesting a resume-link email both require verification. Intermediate pages receive a widget beside Save and Continue; the resume-email form receives a fresh widget. Uploads are protected at final submission, not before files are uploaded.
- Guest checkout via WooCommerce classic checkout and the Checkout Block. Logged-in checkout is excluded.
- WordPress registration and password-reset requests, plus WooCommerce My Account registration and password-reset requests. Authorised password-reset emails sent from WordPress user management do not require a CAPTCHA.

Login, comments, product reviews, and the final password-change step are not protected.

## Installation

Requires PHP 8.3 and WordPress 6.8+. Install as a normal WordPress plugin with Composer (`eighteen73/wordpress-challenge`) and activate it in WordPress. Gravity Forms 2.9+ and WooCommerce 9.8+ are optional integrations; the version guidance in Composer's `suggest` entries does not enforce minimum versions.

## Setup

Open **Settings > Challenge**, choose one site-wide provider and save its credentials. Only the selected provider's controls are shown; switching providers keeps previously saved credentials. Cap is the selected provider initially, but has **no default server URL**. Enter the HTTPS base URL of your Cap instance or compatible hosted endpoint, its **site key (site ID)** and its secret key. Enter the server URL without the site key: Challenge constructs `/<site-key>/siteverify` and loads `/assets/widget.js` from that base URL, plus `/assets/floating.js` when floating mode is selected. Existing sites upgrading from a previous release must also enter their server URL. Cap's **Display** setting defaults to **Floating** (opens on submit); choose **Visible** to show the widget inline on protected forms. Turnstile uses Managed mode. Google reCAPTCHA supports separate v2 checkbox and v3 keys; the v3 score threshold defaults to 0.5. Leave a secret field blank to keep its saved value, or tick **Remove key** to delete it.

The plugin does not enforce CAPTCHA until the selected provider is configured (server URL and both keys for Cap); administrators see a setup notice. Once configured, missing or rejected tokens block submissions. Explicit connection failures, timeouts and HTTP 5xx responses during server-side verification allow submissions with a token by default, across all providers and protected actions. HTTP 4xx responses (including rate limits and credential errors), malformed responses, TLS errors and unclassified transport failures remain blocking. Browser-side failures remain blocking too: this fallback only helps visitors who have already obtained a token, not visitors unable to reach Cap to solve a challenge. Site keys appear in page markup; secret keys are used only for server-side verification. Cap widget assets come from the configured server and the other providers load their own widget scripts.

Fallback events go to the PHP error log with the timestamp, provider, submission context and failure category only. **Settings > Challenge** shows the latest recorded fallback; it is historical information, not a live provider health check. No tokens, secrets or submitted form contents are logged. There is no fallback UI switch. Agency code can disable it for a site:

```php
add_filter( 'challenge_allow_outage_fallback', '__return_false' );
```

The filter also receives `$context`, `$provider` and `$reason` after the default boolean. Browser verification failures use inline, focusable error messages rather than alert pop-ups.

For Cap Standalone, enable its asset server (`ENABLE_ASSETS_SERVER=true`). Challenge sets the widget's WASM URLs **before** loading `widget.js`, pointing to `/assets/cap_wasm_bg.wasm` and `/assets/hashwx.wasm` on the configured server instead of jsDelivr. HashWX needs `WASM_VERSION` 0.0.8 or newer. Browsers without `DecompressionStream` also need `/assets/pako_inflate.min.js` on that server for Cap's instrumentation fallback; Cap Standalone does not serve this file by default. Challenge points the fallback there so the browser does not fetch it from jsDelivr, but those browsers will need the file hosted to complete a challenge. Compatible hosted endpoints must provide the same assets.

## Development

The provider implementations use `Driver\Driver` for widget markup, asset enqueueing, configuration checks and verification. Verification returns a boolean or a `VerificationOutage` category; only `Challenge::verify()` applies the fallback policy. Drivers own their script dependencies and pre-load configuration, including Cap's asset overrides and floating script order. The WordPress, Gravity Forms and WooCommerce integrations use `Challenge::verify()` to enforce server-side checks. Gravity Forms final-submission verification uses its resolved final-page state. Its asynchronous `gform/submission/pre_submission` integration supports final submission, draft saving and resume-link emails; reCAPTCHA v3 also generates fresh tokens on Next requests that can become final submissions through conditional page visibility. Draft saving and resume-email processing bypass normal GF validation, so separate server guards protect those paths and route failures back through GF's existing form rendering. Check these paths when upgrading Gravity Forms. Checkout Block tokens travel under `extensions.form-challenge.token` and are verified before the Store API checkout callback runs, with case-insensitive route matching consistent with WordPress. The Checkout Block client currently uses WooCommerce's `__internalSetExtensionData` action; check compatibility when upgrading WooCommerce.

There is no CSS file or JavaScript build step: the small browser and settings scripts in `assets/` are shipped directly. Run `composer install` and `composer lint` for the agency PHP coding standard; `php tests/verify-drivers.php` exercises provider, Store API and Gravity Forms protection, and `node --check` on each script in `assets/` checks JavaScript syntax.

Run `node tests/verify-checkout-reset.cjs` for the classic and block checkout Cap retry regression checks, and `node tests/verify-gravity-submission.cjs` for Gravity Forms Cap/v3 submission, save/resume and inline retry checks. These checks exercise integrations in isolation; end-to-end browser testing is still required.

The initial pilot targets forms and accounts using a shared self-hosted Cap instance with separate site credentials. Verify both display modes, AJAX and multi-page/conditional forms, upload submissions, Save and Continue, registration and password resets on a designated test site. Gravity Forms payment compatibility remains unverified until the specific add-ons and payment methods are identified.
