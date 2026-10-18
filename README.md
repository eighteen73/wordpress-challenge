# Challenge

**Anti-bot protection where your WordPress visitors actually submit.**

Challenge brings one consistent CAPTCHA layer to forms, registration, password-reset requests and guest checkout. Built by [eighteen73](https://eighteen73.co.uk) for agency-managed websites, it keeps provider configuration in one place and verifies supported submissions server-side.

## Why Challenge?

- **Your provider, one setup.** Choose Cap, Cloudflare Turnstile or Google reCAPTCHA v2/v3 for the whole website.
- **Gravity Forms without repetitive setup.** Protect every form automatically, or opt in individual forms with a Challenge field. Includes AJAX, draft saving and resume-link emails.
- **Built for shared agency infrastructure.** Connect client websites to your self-hosted Cap instance, with separate credentials per site and floating or visible widgets.
- **Beyond the contact form.** Protect native WordPress account journeys, WooCommerce My Account registration/reset requests and guest classic/block checkout.
- **Practical failure handling.** Inline verification errors and logged fallback for qualifying server-side outages help balance protection and availability.

Challenge is focused submission protection, not site-wide bot filtering. Login forms, comments, product reviews and the final password-change step are intentionally outside its current scope.

## Installation

```bash
composer require eighteen73/wordpress-challenge
```

Requires **PHP 8.3+** and **WordPress 6.8+**. Activate Challenge as a normal plugin, then choose your provider in **Settings > Challenge**. Protection starts only once the provider is configured.

## Documentation

[Read the documentation](https://docs.eighteen73.co.uk/wordpress/plugins/challenge/) for provider setup, supported forms, self-hosted Cap requirements, outage policy and developer hooks.

[Packagist](https://packagist.org/packages/eighteen73/wordpress-challenge) · [Report an issue](https://github.com/eighteen73/wordpress-challenge/issues) · GPL-2.0-or-later
