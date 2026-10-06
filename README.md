# SideRelay for WooCommerce

Preview plugin for PHP 8.2+ and WooCommerce, using the public PHP SDK.
It has not yet been verified against a running WooCommerce shop.

1. Install the SideRelay plugin ZIP through **Plugins → Add plugin → Upload**.
2. In **WooCommerce → SideRelay**, paste the token from your SideRelay backend
   connection. It is encrypted and hidden after saving.
3. Keep the existing SideRelay website script and privacy banner installed.
4. Complete a test payment and check **SideRelay → Activity** for Received and
   destination results. Tracking runs through WooCommerce's Action Scheduler.

Use Disconnect to stop sending. Replace the saved token after using Replace token
in SideRelay. If WordPress security salts change, reconnect with a fresh token.

## Reliable purchases

Payment-complete and paid-order status hooks schedule the same purchase ID.
The thank-you page uses that ID too, preventing browser/backend double counting.
Retries keep the paid time and order reference. An SDK call makes up to three
attempts; the existing Action Scheduler makes up to ten background executions.
A Check configuration outcome waits for correction. After correcting the token,
use **Order actions → Retry SideRelay tracking** on the order. SideRelay status is
stored in the order's `_siderelay_status` metadata for advanced diagnostics.

Classic checkout and current Checkout Blocks hand off the website's saved choice.
Missing choices remain unknown. GPC and advertising opt-outs remove advertising
identifiers before saving or sending. Backend confirmation does not grant consent.
Raw billing email/phone are hashed only when advertising permission is granted;
phone numbers must already include their country code. No signing key is configured.
This version captures purchases; use the PHP SDK's lead method from an authoritative
lead handler rather than treating cart visits or account creation as leads.

## Build and verify

```sh
composer install --no-dev
composer validate --strict
php tests/run.php
```

Package `siderelay.php`, `src`, `vendor`, and `LICENSE` inside a `siderelay-woocommerce`
folder. The release workflow bundles the SDK for a one-ZIP installation; customers
do not run Composer. Source uses a public VCS SDK dependency until Packagist is set up.

Verify classic and block checkout, permission changes, payment completion after the
browser closes, repeated thank-you visits, token replacement/disconnection, scheduler
failures, and browser/backend deduplication on the installed WooCommerce version
before activating this preview. Hosts may need CSP approval for frontend scripts.

Official references: [Store API checkout handoff](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-custom-fields/)
and [Checkout Store](https://developer.woocommerce.com/docs/block-development/reference/data-store/checkout).
