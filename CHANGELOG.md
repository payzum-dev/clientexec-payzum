# Changelog

All notable changes to the Payzum payment plugin for ClientExec are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-09-03

First release with a declared version. Everything below shipped today.

### ⚠️ Action required
- **Set the new `Currency` setting** under Settings → Payment Gateways → Payzum. The plugin has a
  currency setting for the first time; previously it was never declared, so it always read back
  empty and **every invoice was created in USD** regardless of what the client was billed in. There
  is deliberately no default: guessing bills the client in the wrong money.

### Fixed
- Crediting the invoice was wrapped in a `method_exists()` guard but reported success either way.
  On a build without that method, every single payment was a silent no-op — money taken, invoice
  untouched, nothing in the log. The plugin now logs the failure and answers `500` so Payzum
  retries. It never reports success for a payment it could not book.
- The IPN now checks the amount and currency paid against the invoice before crediting it, and an
  amount that cannot be read counts as a mismatch rather than passing unverified.
- Repeat deliveries of the same event are now de-duplicated by the plugin itself.
- The API key and webhook secret are declared as password fields, so the settings page no longer
  prints them in clear on every visit.
- The local record of credited payments moved off a predictable `/tmp` path (see the Blesta and
  HostBill notes — same shared-hosting exposure): the name is derived from the install location,
  and symlinks and foreign directories are rejected.
