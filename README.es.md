# Payment - Paymos para HikaShop

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Acepte pagos en stablecoins mediante el checkout alojado de Paymos en HikaShop. La extensión es gratuita y requiere una cuenta y un proyecto de Paymos. Pueden aplicarse comisiones del servicio de Paymos y de la red blockchain. [Precios de Paymos](https://paymos.io/es/pricing)

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Recorrido en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- Joomla 5.4 o 6.2 con HikaShop 6.8.1 o un parche posterior de la serie 6.8.
- PHP 8.3 o superior con cURL y OpenSSL.
- MySQL o MariaDB. Las tablas nativas de HikaShop para pedidos, historial, productos, líneas de pedido, descuentos y usuarios deben usar InnoDB.
- Una tienda HTTPS pública y los estados de pedido estándar de HikaShop `created`, `confirmed` y `refunded`. Configure **Order created status** en `created` y mantenga disponibles `confirmed` y `refunded`.

## Instalar y conectar

1. Descargue el archivo ZIP con versión y súbalo desde **System → Install → Extensions** en Joomla. [Versiones oficiales](https://github.com/Paymos-labs/hikashop/releases)
2. En **System → Manage → Plugins**, active **Payment - Paymos for HikaShop**. En HikaShop, abra **System → Payment methods**, cree un método Paymos y guárdelo.
3. Elija **Connect Paymos**. Abra la página de aprobación, verifique que el código coincide con el mostrado en HikaShop y seleccione un proyecto de Paymos. Vuelva a HikaShop tras la aprobación.
4. Seleccione **Sandbox**, guarde el modo y pruebe un pedido. Cuando esté listo, seleccione **Live** y guárdelo antes de activar el método para los clientes.

El archivo ZIP incluye el SDK de PHP de Paymos. Joomla instala el plugin, sus tablas de base de datos y su sitio de actualización. La conexión vincula el método guardado y la dirección de la tienda con el proyecto aprobado. Utilice la dirección HTTPS canónica y vuelva a conectar si cambia.

Puede configurar la **Success URL** de su proyecto de Paymos hacia la página nativa de retorno de checkout de HikaShop bajo la URL base de Joomla. Para un sitio en `https://store.example/shop/`, utilice `https://store.example/shop/index.php?option=com_hikashop&ctrl=checkout&task=after_end`. Revise este ajuste global del proyecto si varias tiendas comparten el mismo proyecto.

## Pago y cumplimiento

En el checkout se envían a Paymos el proyecto, una referencia del pedido y el importe y divisa del pedido guardado. Cuando el pedido contiene entre 1 y 50 líneas de producto válidas en `#__hikashop_order_product` (con descripciones opcionales en texto plano desde `#__hikashop_product`), el checkout también envía las líneas normalizadas `products[]` junto con el total del pedido de HikaShop (`order_full_price`). Las descripciones ausentes o en blanco se omiten por artículo. No se consultan ni envían imágenes de productos. Si el pedido tiene 0 líneas de producto, más de 50 líneas o alguna línea inválida (como cantidad fraccionaria, título vacío o precio unitario negativo o no válido), el checkout recurre a una factura `amount-only`. La instantánea normalizada se guarda en `#__hikashop_paymos_attempt.products_json` antes de la primera solicitud, de modo que los reintentos reutilizan los detalles de producto guardados. Las instantáneas históricas que contengan `image_url` conservan su JSON almacenado intacto; únicamente se omite esa clave obsoleta de la copia saliente. El cliente completa el pago en Paymos y puede volver a la página del pedido en HikaShop. La confirmación del pago procede de una notificación firmada y de un resultado coincidente en la API de Paymos. La página de retorno muestra el estado actual del pedido en HikaShop.

HikaShop confirma una sola vez un pedido coincidente totalmente pagado. Un pago con sobrepago verificado también puede confirmar el pedido. Los pagos parciales esperan el importe restante o permanecen sin confirmar, y los pagos tardíos o con discrepancias requieren revisión. Las actualizaciones de stock y el acceso a los productos siguen el flujo configurado en HikaShop para un pedido confirmado.

Si falta una actualización, abra el pedido en HikaShop y seleccione **Check payment** en el panel de Paymos. Esta acción consulta y concilia la factura guardada.

## Reembolsos

Abra un pedido pagado en HikaShop como administrador con permiso para editar pedidos. El panel de Paymos muestra las acciones elegibles **Refund full payment** y **Refund overpayment** cuando el proyecto admite reembolsos. Paymos calcula el importe y utiliza la dirección de remitente verificada registrada en el pago.

- Un reembolso completo cambia el pedido a `refunded` después de que Paymos verifique su finalización. HikaShop aplica el comportamiento configurado de pedidos y stock.
- Un reembolso de sobrepago completado mantiene el estado actual del pedido y añade una entrada al historial.
- Ante un resultado pendiente o poco claro, utilice **Check refund** sobre la operación existente. Una notificación firmada o la conciliación actualizan la operación conforme Paymos informa de su estado.
- Un reembolso iniciado fuera de este plugin que no pueda vincularse a una operación guardada requiere revisión en Paymos y en HikaShop.

## Credenciales y desinstalación

Las credenciales se almacenan cifradas mediante el secreto de instalación de Joomla. Resuelva los pagos y reembolsos pendientes antes de retirar la integración. **Disconnect** elimina las credenciales guardadas del método y detiene sus actualizaciones de pago hasta volver a conectarlo. Al desinstalar el plugin se eliminan sus propias tablas y credenciales; los pedidos y el historial nativos de HikaShop se conservan.

## Licencia y soporte

Esta extensión utiliza la licencia GPL-2.0-or-later. El SDK de PHP de Paymos incluido utiliza la licencia MIT. [Licencia de la extensión](LICENSE), [avisos de terceros](NOTICE).

Para consultar la guía de integración o solicitar soporte, visite la [documentación de Paymos](https://paymos.io/es/docs/cms-hikashop) o contacte con el [soporte de Paymos](mailto:support@paymos.io).
