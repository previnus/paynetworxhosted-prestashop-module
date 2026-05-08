# Paynetworx Hosted — PrestaShop Payment Module

A **PCI DSS SAQ A-eligible** payment gateway for **PrestaShop 8.x and 9.x**. Card data is collected entirely inside a Paynetworx-hosted iframe — raw PAN, CVC, and expiry never reach your server. The module receives only a one-time token, then charges it via the standard Paynetworx API.

> **PCI DSS scope:** SAQ A eligible once the Hosted Payments environment is activated by Paynetworx support.

---

## How It Works

```
Browser                     Your Server                  Paynetworx
──────                     ────────────                  ──────────
 1. Load checkout page  →  Generate nonce + session URL  →  /sessions/create
 2. Render hosted iframe ←  Return session URL           ←
 3. Customer enters card   (card data stays inside iframe)
 4. Submit form        →   postMessage({ type:'tokenize' })  →
                           ←  { token_id: "..." }            ←
 5. POST token_id      →   authCaptureWithToken()         →  /transaction/authcapture
 6. Order confirmed    ←   validateOrder()                ←  { Approved: true }
```

---

## Features

- **SAQ A eligible** — card data never touches your server
- **PrestaShop 8 & 9** compatible
- **Replay protection** — CSPRNG nonce, cookie-stored, `hash_equals()` verified
- **Idempotency** — `paynetworxhosted_transactions` table with unique cart key prevents double-charges
- **Orphaned-charge recovery** — CRITICAL log entry with TransactionID if `validateOrder()` fails after approved charge
- **30-second tokenization timeout** with user-visible feedback
- **Token format validation** before sending to gateway
- **TLS 1.2 minimum**, SSL peer verification, rejection-sampling KSUID IDs

---

## Requirements

- PrestaShop 8.0.0 or higher
- PHP 7.4+
- cURL extension with TLS 1.2 support
- **Two** sets of Paynetworx credentials:
  1. **Hosted Payments API Key** — for iframe session creation (request from Paynetworx support)
  2. **Access Token User + Password** — standard API credentials for charging the token

> **Important:** The Hosted Payments API Key is a separate credential from the standard Access Token. You must also ask Paynetworx support to activate the Hosted Payments environment for your account.

---

## Installation

1. Upload the `paynetworxhosted` folder to `/modules/`.
2. In the Back Office go to **Module Manager**, search for **Paynetworx (Hosted)**, click **Install**.
3. Click **Configure** and enter:
   - **Environment** — Test / Sandbox or Live / Production
   - **Hosted Payments API Key** — from Paynetworx Hosted Payments onboarding
   - **Access Token User** — standard Paynetworx API user
   - **Access Token Password** — standard Paynetworx API password
4. Click **Save**.

---

## Test Environment Notes

- Hosted Payments QA must be activated by Paynetworx support before the iframe will load.
- Payment API QA requires **USD** and amounts under $15.00.
- Request test card numbers from your Paynetworx account manager.

---

## Logs

**Advanced Parameters → Logs**, filter by `Cart`. Look for:
- `Paynetworx Hosted: charge attempt` — gateway call initiated
- `Paynetworx Hosted: gateway response` — API result
- `CRITICAL — Paynetworx Hosted` — charge approved but order creation failed (requires manual reconciliation)

---

## Upgrade / Reset

Click **Reset** in Module Manager after upgrading to refresh hook registrations.

---

## Author

**ArcPro Media Inc.**  
[www.arcpromedia.com](https://www.arcpromedia.com)
