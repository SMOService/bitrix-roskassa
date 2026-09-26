# Roskassa for 1C-Bitrix

[![CI](https://github.com/SMOService/bitrix-roskassa/actions/workflows/ci.yml/badge.svg)](https://github.com/SMOService/bitrix-roskassa/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-7.4%E2%80%938.3-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

[Roskassa](https://roskassa.net) payment system module for the 1C-Bitrix Online Store (`sale`) module.

[Русская версия](README.md)

## What it does differently

| | Typical handler | This module |
|---|---|---|
| When an order counts as paid | on a valid notification signature | signature **and** payment confirmed through the gateway API |
| If a notification is lost | the order stays unpaid while the gateway holds the money | a reconciliation agent finds the payment every 10 minutes |
| Where notifications arrive | shared `sale_ps_result.php`, handler guessed from request fields | a dedicated URL with an explicit pay system ID |
| Repeated or concurrent notifications | may mark a payment paid twice | order lock + payment journal: one gateway payment is credited once |
| Payment unpaid or refunded by an admin | a repeated notification pays it again | never re-credited automatically, goes to manual review |
| Old invoice suddenly "paid" by the gateway | credited | paid more than 7 days after creation — manual review |
| Gateway domain change | code change | one settings field |

## Requirements

- 1C-Bitrix Site Manager or on-premise Bitrix24 with the Online Store module (cloud Bitrix24 does not allow third-party modules).
- PHP 7.4–8.3.
- A Roskassa shop: shop ID, secret key and **API key**.
- Agents running on cron (recommended).

## Installation

1. Download the module archive from [Releases](https://github.com/SMOService/bitrix-roskassa/releases) and unpack `smoservice.roskassa` into `/local/modules/` (or `/bitrix/modules/`).
2. Admin → Marketplace → Installed solutions → "Roskassa payments" → Install.
3. Store → Settings → Payment systems → Add, handler **Roskassa (roskassanet)**.
4. Fill in the fields, make a small test payment, then activate.

## Settings

| Field | Required | Meaning |
|---|---|---|
| Shop ID | yes | `shop_id`, the public shop key |
| Secret key | yes | signs the payment form and notifications |
| API key | yes | payments are not accepted without it |
| Gateway URL | no | defaults to `https://roskassa.net` |
| Order number prefix | no | gateway order number is `<prefix>-<payment ID>`; empty — a random per-site prefix generated at install. Do not change after launch |
| Test mode | no | test payments are accepted only in this mode and only for administrators' orders |
| Payment method ID | no | open a specific method right away |
| Payment page language | no | `ru` / `en` |
| Success / failure pages | no | absolute URLs; empty — shop defaults |
| Notification URL | no | usually empty: the module sends its own URL with each payment |

## How it works

- The payment form is signed (md5) and sent to `roskassa.net/pay/`.
- The gateway POSTs a notification to `/bitrix/tools/smoservice_roskassa_notify.php?ps=ID`. The module checks the signature, shop, order number, amount and currency before the Bitrix core, then confirms the payment through the REST API, claims it in the journal and marks the payment paid — under an order lock.
- The shared `sale_ps_result.php` never selects this handler.
- The gateway sends a notification only once, so an agent runs every 10 minutes: a quick pass over the last 7 days of paid gateway orders and a resumable full pass over the whole history since install. It credits lost payments and flags double payments (`duplicate`) and payments without a site payment (`orphan`).

## Journals

- **Payment journal** `smoservice_roskassa_credit` (primary key = gateway order id): `credited`, `mismatch`, `duplicate` (refund the buyer), `review`, `save_failed`, `orphan`. Everything except `credited` needs manual review. The table is kept when the module is removed.
- **Pay system error log** (`b_sale_pay_system_err_log`), entries prefixed `Roskassa:`. No secrets are logged.

## Uninstall

Delete the payment systems that use the Roskassa handler first — otherwise the installer refuses.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md); security issues — [SECURITY.md](SECURITY.md).

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

[MIT](LICENSE)
