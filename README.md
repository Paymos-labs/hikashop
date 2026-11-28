# Payment - Paymos for HikaShop

**English** | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Accept stablecoin payments through Paymos hosted checkout in HikaShop. The extension is free and requires a Paymos account and project. Paymos service and blockchain network fees may apply. [Paymos pricing](https://paymos.io/pricing)

[![Watch: 12 Official CMS Crypto Plugins — 1-Click Connect (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video walkthrough (1:12, subtitles in 6 languages):** [Watch on YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requirements

- Joomla 5.4 or 6.2 with HikaShop 6.8.1 or a later patch in the 6.8 series.
- PHP 8.3 or later with cURL and OpenSSL.
- MySQL or MariaDB. The native HikaShop order, history, product, order product, discount and user tables must use InnoDB.
- A public HTTPS storefront and the standard HikaShop order statuses `created`, `confirmed` and `refunded`. Set **Order created status** to `created` and keep `confirmed` and `refunded` available.

## Install and connect

1. Download the versioned installation ZIP and upload it from **System → Install → Extensions** in Joomla. [Official releases](https://github.com/Paymos-labs/hikashop/releases)
2. In **System → Manage → Plugins**, enable **Payment - Paymos for HikaShop**. In HikaShop, open **System → Payment methods**, create a Paymos method and save it.
3. Choose **Connect Paymos**. Open the approval page, match the code shown in HikaShop and select a Paymos project. Return to HikaShop after approval.
4. Select **Sandbox**, save the mode and test an order. When ready, select **Live** and save it before enabling the method for customers.

The ZIP bundles the Paymos PHP SDK. Joomla installs the plugin, its database tables, and its update site. Connect binds the saved method and store address to the approved project. Use the canonical HTTPS address and reconnect if it changes.

You can set your Paymos project's **Success URL** to the native HikaShop checkout return page under your Joomla base URL. For a site at `https://store.example/shop/`, use `https://store.example/shop/index.php?option=com_hikashop&ctrl=checkout&task=after_end`. Review this project-wide setting if several stores share a project.

## Payment and fulfillment

Checkout sends Paymos the project, an order reference, and the saved order's amount and currency. When the order contains 1 to 50 valid product lines in `#__hikashop_order_product` (with optional plain-text descriptions from `#__hikashop_product`), checkout also sends the normalized `products[]` line items alongside the HikaShop order total (`order_full_price`). Missing or blank descriptions are omitted per item. Product images are not queried or sent. If the order has 0 product lines, more than 50 product lines, or any invalid product line (such as a fractional quantity, empty title, or negative/invalid unit price), checkout falls back to an `amount-only` invoice. The normalized snapshot is persisted in `#__hikashop_paymos_attempt.products_json` before the first request so retries replay the saved product details. Historical snapshots containing `image_url` keep their stored JSON unchanged; only that obsolete key is omitted from the outgoing copy. The customer completes payment on Paymos and can return to the HikaShop order page. Payment confirmation comes from a signed notification and a matching Paymos API result. The return page displays the HikaShop order state.

HikaShop confirms a fully paid matching order once. A payment with a verified overpayment can also confirm the order. Partial payments wait for the balance or stay unconfirmed, and late or mismatched payments need review. Stock updates and product access follow the configured HikaShop workflow for a confirmed order.

If an update is missing, open the order in HikaShop and select **Check payment** in the Paymos panel. This fetches and reconciles the saved invoice.

## Refunds

Open a paid order in HikaShop as an administrator with permission to edit orders. The Paymos panel shows eligible **Refund full payment** and **Refund overpayment** actions for a project with refund support. Paymos calculates the amount and uses the verified sender address recorded for the payment.

- A full refund sets the order to `refunded` after Paymos verifies completion. HikaShop applies its configured order and stock behavior.
- A completed overpayment refund keeps the current order status and adds a history entry.
- For a pending or unclear result, use **Check refund** on the existing operation. A signed notification or reconciliation updates the operation as Paymos reports its state.
- A refund initiated outside this plugin that cannot be matched to a saved refund operation needs review in Paymos and HikaShop.

## Credentials and removal

Credentials are stored encrypted using the Joomla installation secret. Resolve pending payments and refunds before removing the integration. **Disconnect** removes saved credentials for the method and stops its payment updates until reconnection. Uninstalling the plugin removes its own tables and credentials. Native HikaShop orders and history stay available.

## License and support

This extension uses GPL-2.0-or-later. The bundled Paymos PHP SDK uses the MIT License. [Extension license](LICENSE), [third-party notices](NOTICE)

For integration guidance and support, see [Paymos documentation](https://paymos.io/docs/cms-hikashop) or contact [Paymos support](mailto:support@paymos.io).
