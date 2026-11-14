# ArionPay for Magento 2

Lets your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Works with Magento Open Source / Adobe Commerce 2.4 and Mage-OS. Tested on a real Magento 2.4.9 (PHP 8.3, MariaDB, OpenSearch 2.19, Luma checkout): checkout, payment, late payment, payment tolerance, changed order total, "Pay now" after leaving the payment page, webhook checks, and `setup:di:compile`.

## What you need

- Magento 2.4 with PHP 7.4 or newer (2.4.7+ needs PHP 8.2+)
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- Your shop on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

**With Composer** (once the package is published):

```bash
composer require arionpay/module-magento2
bin/magento module:enable ArionPay_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode only
bin/magento setup:static-content:deploy # production mode only
bin/magento cache:flush
```

**By hand:** unzip `arionpay-magento2.zip` in the Magento folder (it creates `app/code/ArionPay/Payment`), then run the same `bin/magento` commands.

## Settings

Go to **Stores > Configuration > Sales > Payment Methods > ArionPay (crypto)**:

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

Set **Enabled** to Yes, keep **Test mode** on, and save. The secrets are stored encrypted.

You do not need to paste any address into ArionPay: each invoice tells ArionPay to send its payment updates to `https://your-shop/arionpay/webhook/index`. You can also paste that address on the ArionPay **Webhooks** page to use **Send test webhook** there.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. Keep **Test mode** on.
2. Buy something and choose **Crypto (Bitcoin, USDT and more)** at checkout, then **Place Order**.
3. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
4. The order should go from **Pending Payment** to **Processing**, with a Magento invoice and the comment "[TEST - no real money] ArionPay invoice ... is paid".
5. Turn **Test mode** off before you take real payments.

## How orders change

- **Place Order** saves the order as **Pending Payment** (no order email yet) and sends the customer to the ArionPay page.
- When ArionPay confirms the full payment, Magento records the payment (a capture with the blockchain transaction id), creates the invoice, moves the order to **Processing** and sends the order and invoice emails.
- The order is paid only if the paid invoice still matches it: same currency and the same total. If the order was edited after checkout, it is not marked paid and a comment explains why.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the order is paid here too, with a comment.
- A late payment (after the ArionPay invoice expired) still pays the order, with a comment.
- If more money arrives after an order was paid, you get a comment asking you to check your wallet. Nothing else changes.
- After the ArionPay page, the customer lands on a small order status page: "payment received", or "not received yet" with a **Pay now with crypto** button that reopens the open invoice or makes a new one.
- Coming back from the payment page never marks an order paid.

## Good to know

- ArionPay is offered for these currencies: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR. If a customer views the shop in another currency, the invoice uses your base currency.
- Magento cancels **Pending Payment** orders after the "Pending Payment Order Lifetime" (default 8 hours, under Sales > Checkout). That is far longer than an ArionPay invoice stays open, so it does not cut off payments.
- Refunds are made from your own wallet. The module does not send refunds.
- The module sends ArionPay the order number, amount, currency and your store name. It does not send the customer's name, email or address.
- **Debug log** writes to `var/log/arionpay.log`. Keys are never written.
- It adds two tables, `arionpay_invoice` and `arionpay_event`.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
