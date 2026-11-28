# Changelog

## [Unreleased]

## [1.1.3] - 2026-11-28

- fix(hikashop): migrate products_json before files, re-read accepted refunds
- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- fix: close bug batches 11-12 across SDKs, CMS plugins, workers, proxy, backup and admin
- chore: bundle Paymos PHP SDK v1.13.0
- chore: rebuild canonical CMS package

- An update now adds the `products_json` column to the payment attempt table before Joomla copies the new files. When the database refuses the change, the update stops and the previous version keeps taking payments, instead of finishing with every new payment failing on the missing column.
- An accepted refund whose first read after the request got a temporary 401, 403 or 404 no longer stays in review for good: the plugin keeps the unverified withdrawal ID (`RefundCandidateStoreInterface`), so **Check refund** reads it again and records the refund in HikaShop. `composer.json` now requires `paymos/php-sdk` `^1.12.0`, the first release with that interface.

## [1.1.2] - 2026-11-22

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-11-21

- chore: rebuild canonical CMS package

## [1.1.0] - 2026-11-20

- fix(checkout): enforce PHP SDK compatibility across CMS packages
- refactor(checkout): remove product images from invoice products
- feat(plugins): add products[] and products_json migration to HikaShop 1.1.0 (10.1)
- chore: bundle Paymos PHP SDK v1.11.1
- chore: rebuild canonical CMS package

## [1.0.14] - 2026-11-16

- chore: rebuild canonical CMS package

## [1.0.13] - 2026-11-15

- chore: rebuild canonical CMS package

## [1.0.12] - 2026-11-15

- fix(refunds): decouple refund RBAC from Withdraw and unify 12 CMS refund adapters
- chore: rebuild canonical CMS package

## [1.0.11] - 2026-11-14

- chore: rebuild canonical CMS package

## [1.0.10] - 2026-11-14

- chore: rebuild canonical CMS package

## [1.0.9] - 2026-11-13

- chore: bundle Paymos PHP SDK v1.10.2

## [1.0.8] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.0.7] - 2026-11-13

- chore: bundle Paymos PHP SDK v1.10.1
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.0.5] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-11-11

- chore: rebuild canonical CMS package

## [1.0.3] - 2026-11-10

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-11-10

- chore: bundle Paymos PHP SDK v1.10.0
- chore: rebuild canonical CMS package

## [1.0.1] - 2026-11-08

- chore: rebuild canonical CMS package

## 1.0.0

- Add Paymos Connect for HikaShop payment methods with Sandbox and Live modes.
- Open Paymos hosted checkout and confirm orders through signed notifications and API verification.
- Show payment reconciliation and eligible full and overpayment refunds on the HikaShop order page.
- Add the Joomla Update Server and bundle the Paymos PHP SDK in the installable ZIP.
