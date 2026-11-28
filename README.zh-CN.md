# Paymos HikaShop 稳定币支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

在 HikaShop 中通过 Paymos 托管收银台接收稳定币支付与 USDT/USDC 收款。本扩展免费开源，使用前需要拥有 Paymos 账号及项目，并可能适用 Paymos 服务费及区块链网络费。查看 [Paymos 费率说明](https://paymos.io/zh-hans/pricing)。

[![观看视频：12 款官方 CMS 稳定币支付插件 — 一键连接（Paymos）](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **视频演示（1:12，提供 6 种语言字幕）：** [在 YouTube 观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- Joomla 5.4 或 6.2，搭配 HikaShop 6.8.1 或 6.8 系列更高补丁版本。
- PHP 8.3 及以上版本，并启用 cURL 和 OpenSSL 扩展。
- MySQL 或 MariaDB。原生的 HikaShop 订单、历史记录、商品、订单商品、折扣及用户表均必须使用 InnoDB 存储引擎。
- 可公网访问的 HTTPS 店铺前台，以及标准的 HikaShop 订单状态 `created`、`confirmed` 和 `refunded`。请将 **Order created status** 设为 `created`，并确保 `confirmed` 和 `refunded` 状态保持可用。

## 安装与一键连接

1. 下载带版本号的安装 ZIP 包，并在 Joomla 后台 **System → Install → Extensions** 上传安装。[官方发布页](https://github.com/Paymos-labs/hikashop/releases)
2. 进入 **System → Manage → Plugins**，启用 **Payment - Paymos for HikaShop**。随后在 HikaShop 中打开 **System → Payment methods**，新建一条 Paymos 支付方式并保存。
3. 点击 **Connect Paymos**。打开授权页，核对 HikaShop 中显示的验证码并选择对应的 Paymos 项目。授权完成后返回 HikaShop。
4. 选择 **Sandbox**，保存模式并完成一笔测试订单。准备上线时，再切换为 **Live** 并保存，最后面向前台顾客启用该支付方式。

安装 ZIP 包内已集成 Paymos PHP SDK。Joomla 会自动安装插件、数据库表及更新源。一键连接会将已保存的支付方式和店铺地址绑定到所授权的项目；请始终使用店铺的规范 HTTPS 地址，地址变更后需重新连接。

您可以在 Paymos 项目中将 **Success URL** 设置为 Joomla 基础路径下的原生 HikaShop 结账返回页。例如，若站点位于 `https://store.example/shop/`，可填写 `https://store.example/shop/index.php?option=com_hikashop&ctrl=checkout&task=after_end`。如果多个店铺共用同一个项目，请仔细核对这项项目级配置。

## 支付与履约

结账时，插件会向 Paymos 发送项目标识、订单参考号以及已保存订单的金额与币种。当订单在 `#__hikashop_order_product` 中包含 1 至 50 条有效商品行（可附带 `#__hikashop_product` 中的纯文本商品描述）时，结账请求还会将规范化后的 `products[]` 商品明细随 HikaShop 订单总额（`order_full_price`）一并发送。缺失或空白的描述会按单条商品省略；商品图片不会被查询或发送。如果订单包含 0 条或超过 50 条商品行，或存在任何无效商品行（如小数数量、空标题、负值或非法单价），结账会自动回退为仅含金额（`amount-only`）的账单。规范化后的快照会在首次请求前写入 `#__hikashop_paymos_attempt.products_json`，以便重试时复用已保存的商品详情。历史快照中如包含 `image_url`，其存储 JSON 会保持原样不变，仅在外发副本中省略该废弃字段。顾客在 Paymos 完成支付后即可返回 HikaShop 订单页。最终付款确认依赖签名通知以及与之匹配的 Paymos API 查询结果，返回页本身仅展示 HikaShop 当前的订单状态。

对于金额匹配且全额付清的订单，HikaShop 只会确认一次；经过校验的超额支付同样可以确认订单。部分付款会继续等待补足余款或保持未确认状态，延迟付款或信息不匹配的付款需要人工复核。库存扣减与商品访问权限均遵循 HikaShop 对已确认订单的既定工作流。

如果某笔订单的状态更新缺失，可在 HikaShop 中打开该订单，点击 Paymos 面板中的 **Check payment**，主动拉取并对账已保存的账单。

## 退款

使用具备编辑订单权限的管理员账号在 HikaShop 中打开已支付订单。只要对应项目支持退款，Paymos 面板就会显示可用的 **Refund full payment** 和 **Refund overpayment** 操作。退款金额由 Paymos 服务端计算，资金将退回支付记录中经过验证的原付款地址。

- 全额退款在 Paymos 确认完成后会将订单状态设为 `refunded`，随后 HikaShop 按既定配置处理订单与库存变更。
- 超额退款完成后保持订单原有状态不变，并新增一条订单历史记录。
- 若结果仍处于待处理或不明确状态，请对已有操作点击 **Check refund**。随着 Paymos 报告最新状态，签名通知或对账会同步更新该记录。
- 如果在插件外部发起的退款无法匹配到已保存的退款操作，请在 Paymos 和 HikaShop 中人工核对。

## 凭据与卸载

凭据使用 Joomla 安装密钥加密存储。在移除集成前，请先处理完所有待决的付款与退款。点击 **Disconnect** 会删除该支付方式保存的凭据，并在重新连接前停止其支付状态更新。卸载插件会清除插件自有的数据表和凭据，而原生 HikaShop 订单及历史记录会完整保留。

## 开源协议与支持

本扩展采用 GPL-2.0-or-later 协议；内置的 Paymos PHP SDK 采用 MIT 协议。详见[扩展许可证](LICENSE)与[第三方声明](NOTICE)。

如需集成指南与技术支持，请查阅 [Paymos 文档](https://paymos.io/zh-hans/docs/cms-hikashop)或联系 [Paymos 支持](mailto:support@paymos.io)。
