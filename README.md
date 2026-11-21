# Caravel Pay for WHMCS

- Docs: <https://caravelpay.app/docs/integrations#whmcs>
- Download and checksums: <https://caravelpay.app/plugins/#whmcs>
- Status: beta. It passes our tests against a stand-in of the platform and has not yet run on a production install. Tell us if anything looks off.
- Questions: [hello@caravelpay.app](mailto:hello@caravelpay.app)

Accept card, Apple Pay, Google Pay and USDC on WHMCS invoices. Each payment goes to your own Polygon wallet, minus the 2% Caravel fee, in the token the buyer paid with (card payments arrive as USDC).

- WHMCS 8.x (PHP 7.4 to 8.3). No Composer needed: the Caravel Pay PHP SDK is bundled.
- One gateway module, `caravelpay`, and one webhook file. No accounts, no dashboard, no API keys to copy.

## Install

1. Download `caravel-pay-whmcs.zip` and unzip it.
2. Upload the `modules/` folder into your WHMCS root, merging it with the existing `modules/` folder. It adds:
   - `modules/gateways/caravelpay.php`
   - `modules/gateways/caravelpay/` (the module's code and the bundled SDK)
   - `modules/gateways/callback/caravelpay.php` (the webhook endpoint)
3. In WHMCS, go to **Setup › Payments › Payment Gateways › All Payment Gateways** and click **Caravel Pay**.
4. Paste your **Payout wallet (Polygon)**, optionally a **Contact email**, and click **Save Changes**.

Saving connects WHMCS to Caravel Pay: the module calls `POST /v1/connect` with your WHMCS System URL and wallet, and stores the merchant id and secret encrypted in its own `mod_caravelpay_*` tables. The settings page then shows **Connected as mch_…**. On WHMCS versions that don't validate gateway settings on save, the module connects on the first payment instead.

**Who pays the fees** (gateway setting): *Seller pays everything* (default): your client pays the invoice total and both fees come off what you receive. *Split: buyer pays the card fee*: the card provider adds its fee to your client's total and you pay the 2% Caravel fee. *Buyer pays everything*: the 2% Caravel fee is added to your client's total as well, so you receive the full invoice total (WHMCS then records a fee of 0.00). Clients see a line about it on the invoice before they pay. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules first.

### Requirements

- Your **System URL** (Setup › General Settings) must be `https://`. Buyers return to it and webhooks are sent to it.
- **Currencies.** Clients are always charged in **US dollars**. Invoices in US dollars are charged as they are. Invoices in EUR, GBP, CAD, AUD, NZD, SEK, NOK, DKK, CHF, PLN, CZK, HUF, RON, HKD, SGD, MXN, BRL, ZAR, ILS, INR, THB, MYR, PHP, TRY, JPY and KRW go to Caravel Pay in their own currency and are converted to US dollars once, when the client clicks **Pay Now**; the invoice page says so ("You'll be charged in US dollars: about $48.60"), the checkout shows both prices, and the invoice is credited with its own currency amount. The optional **Exchange-rate buffer (%)** setting (0 to 5, default 0) adds a margin to the converted amount, to cover exchange-rate movement until you convert your USDC. For any other currency, set **Convert To For Processing** to USD on the gateway: WHMCS then converts with its own rates and the USD amount is charged.
- `/modules/gateways/callback/caravelpay.php` must accept `POST` requests from the internet. Check that your firewall, CDN or maintenance mode doesn't block it.

## How invoices update

The client clicks **Pay Now** on the invoice and is sent to the hosted checkout. The checkout is created at that moment, not when the invoice is viewed or emailed. A second click reuses the open checkout.

Caravel Pay then sends a signed webhook. The module verifies it, **re-fetches the checkout from the API**, and only then changes the invoice:

| Checkout status | WHMCS |
|---|---|
| `paid` | `addInvoicePayment()` with the payout transaction hash as the transaction id, the invoice amount and the 2% Caravel fee. Gateway Log: **Successful** |
| `underpaid` | Not paid. Gateway Log **Underpaid** and an invoice note with how much arrived |
| `review` | Not paid. Gateway Log **Review** and an invoice note |
| `expired` | Not paid. Gateway Log **Expired**. The client can click Pay Now again for a new checkout |
| `confirming` | Gateway Log entry only. Don't deliver yet |

A payment for an invoice that is already paid or cancelled is never added automatically. It is logged (**Paid Twice** / **Needs Review**) with a note, so you can refund or apply it by hand. Every webhook outcome, rejected signatures included, is in **Billing › Gateway Log**.

Renewal invoices are paid the same way: the client pays each one from the invoice. There are no automatic card charges.

## Switching from PayGate

The PayGate WHMCS modules (`paygatedottohosted`, `paygatedottocustom`) can stay active while you switch:

1. Install and activate Caravel Pay as above. If a PayGate gateway is configured, the Caravel Pay settings page shows the Polygon wallet PayGate pays out to, so you can paste the same one. Nothing is copied or changed automatically.
2. Check the result with a small real invoice: there is no test mode.
3. Make Caravel Pay the default gateway (drag it to the top under Payment Gateways). When you later deactivate a PayGate gateway, WHMCS asks which gateway its clients and invoices should move to: choose Caravel Pay.

Differences to expect: there is no "fees tolerance" setting, because a short payment is reported as `underpaid` and never marks the invoice paid. Payouts are on Polygon in the token the buyer used, minus the 2% Caravel fee.

## Troubleshooting

- **"Not connected yet" after saving:** check the Gateway Log for the reason (for example a System URL that isn't HTTPS, or a wallet that can't receive payouts). Save again after fixing it.
- **Invoices stay Unpaid after paying:** the webhook isn't reaching WHMCS. Look for **Rejected** or missing entries in the Gateway Log, and allow `POST` to `/modules/gateways/callback/caravelpay.php`.
- **Changing your wallet:** paste the new one and save. The module gets new keys, and new payments go to the new wallet. Checkouts already started finish on the old one; their webhooks still verify, because the old keys are kept for 7 days.

Staging: define `CARAVEL_PAY_API_BASE` (for example `https://api.staging.example`) in `configuration.php` to use another API host.

Uninstall: deactivate the gateway, delete the three paths above, and drop the `mod_caravelpay_*` tables if you don't need the payment history.

## Download

- [Latest release](https://github.com/caravelpay/caravel-pay-whmcs/releases/latest): `caravel-pay-whmcs.zip`, with `SHA256SUMS`.
- The same file is at <https://caravelpay.app/plugins/#whmcs>, and its checksum is in <https://caravelpay.app/download/SHA256SUMS>.

## Docs and support

- Guide: <https://caravelpay.app/docs/integrations#whmcs>
- API reference: <https://caravelpay.app/docs/>
- Questions and bug reports: [hello@caravelpay.app](mailto:hello@caravelpay.app), or open an issue here.

## Licence

MIT. See `LICENSE`.
