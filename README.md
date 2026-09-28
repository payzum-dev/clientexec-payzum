# Payzum for ClientExec — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[ClientExec](https://www.clientexec.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. **Zero chargebacks** — a major win for hosting billing, where
chargeback fraud on VPS and domain orders is routine.

- **Plugin:** `payzum` (external payment plugin) · **Version:** 1.0.0 · **License:** MIT
- **Requires:** ClientExec, PHP ≥ 8.1 with `ext-curl`
- The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php)
  SDK is vendored — no composer step on the server.

## How it works

1. The client opens their invoice and picks **Payzum**. The plugin creates a
   Payzum hosted-checkout invoice and redirects them to it (QR code + deposit
   address, live status), where they choose the coin and chain. No wallet data
   touches your server.
2. Crypto confirmation is **asynchronous**, so the invoice is credited from
   Payzum's signed server-to-server IPN, never from the client's browser — a
   closed tab never loses a paid invoice.
3. Every IPN is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, 10-minute replay window) before a single field of it
   is read. If the plugin cannot book the payment, it answers `5xx` so Payzum
   retries the delivery — it never reports success for a payment it could not
   credit.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon,
  Ethereum, Arbitrum, Base, Optimism, Tron, Solana and more), plus major
  cryptocurrencies — the client picks the coin on the hosted checkout.
- **Non-custodial** — payments settle to your own wallet.
- **No chargebacks** — crypto payments are final.
- **Honest failure handling** — a payment that cannot be credited is logged
  and retried, never silently swallowed.
- **Correct-currency invoicing** — the invoice is created in your
  installation's currency (`CURRENCY_CODE`, or the plugin's `Currency`
  setting); the plugin refuses to invoice in a guessed currency.
- **Production / staging support** via a plugin setting.

## Installation

**From the release zip (recommended).** Download
[`payzum-clientexec-1.0.0.zip`](https://github.com/payzum-dev/clientexec-payzum/releases/latest) and
unzip it at your ClientExec root — the archive already mirrors the expected tree, so the plugin
lands at `plugins/payment/payzum/`.

**From a clone.** This repository holds the plugin under `payment/payzum/`, which maps to
`plugins/payment/payzum/` inside ClientExec:

```
payment/payzum/   →   <clientexec>/plugins/payment/payzum/
```

Then enable **Payzum** under **Settings → Payment Gateways**.

> **Compatibility note:** ClientExec's payment-plugin API is proprietary. This
> plugin follows the standard plugin pattern; validate the external-redirect
> and invoice-credit flow against your ClientExec version in staging before
> going live, and report anything odd via the issues here.

## Configuration

| Setting | Meaning |
|---|---|
| API Key | From your [Payzum merchant dashboard](https://merchant.payzum.com) (Dashboard → Developers → API keys) |
| Webhook secret | IPN signing secret from your Payzum webhook settings — verifies the HMAC-SHA-512 signature |
| Environment | `production` (`merchant.payzum.com`) or `staging` (`staging.payzum.com`; staging needs its own API key) |
| Currency | Fallback ISO currency code (e.g. `EUR`) used only when the installation does not define `CURRENCY_CODE`. **Set this** — there is deliberately no default |

The IPN signature header is fixed and read by the SDK itself — nothing to
configure.

## Invoice status mapping

| Payzum payment status | ClientExec effect |
|---|---|
| `finished` | Invoice credited (covers overpayment, which the merchant surface reports as `finished`) |
| anything else | Logged; invoice untouched |

Only a verified `finished` settles the invoice.

## FAQ

**Can ClientExec accept USDT or USDC payments?**
Yes — with this plugin a client pays any ClientExec invoice in USDT, USDC or
other supported assets from any wallet, and the invoice is credited
automatically.

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Does this eliminate chargebacks on hosting orders?**
Yes. Crypto payments are final, so there is no chargeback path — no more
losing a VPS plus the money plus a dispute fee.

**What happens if the client pays and closes the browser?**
Nothing is lost. The invoice is credited from Payzum's signed IPN, which is
server-to-server and retried on failure.

**Why must I set the Currency setting?**
It is the fallback when your installation does not define `CURRENCY_CODE`.
The plugin refuses to guess: a wrong guess would bill the client in the wrong
money.

**Does it need composer on the server?**
No. The official Payzum PHP SDK is vendored inside the plugin.

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, billing and donation
platforms — WooCommerce, Magento 2, PrestaShop, Shopware 6, OpenCart,
Zen Cart, nopCommerce, Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure,
Saleor, Sylius, Easy Digital Downloads, GiveWP, Paid Memberships Pro, WHMCS,
Blesta, HostBill, pretix, Frappe/ERPNext, Akaunting and django-payments —
plus official SDKs for PHP, Node.js/TypeScript, Python and Rust. Browse them
all at [github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[MIT](LICENSE). Contributed and maintained by Payzum.
