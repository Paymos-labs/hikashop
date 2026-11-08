<?php

// Copyright (c) 2026 Paymos Labs
// SPDX-License-Identifier: GPL-2.0-or-later
// Licensed under the GNU General Public License version 2 or later. See LICENSE.

declare(strict_types=1);

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use PaymosHikaShop\ConnectAdmin;
use PaymosHikaShop\Payments;
use PaymosHikaShop\RefundAdapter;
use PaymosHikaShop\Store;

defined('_JEXEC') or die;

if (!class_exists('hikashopPaymentPlugin')) {
    // com_ajax can load this plugin without dispatching the HikaShop component.
    $helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
    if (!is_file($helper)) {
        return;
    }
    require_once $helper;
}

class plgHikashoppaymentPaymos extends hikashopPaymentPlugin
{
    public $name = 'paymos';
    public $multiple = true;

    protected $autoloadLanguage = true;
    protected ?string $paymosPaymentUrl = null;

    private static ?Store $store = null;
    private ?ConnectAdmin $connect = null;
    private ?Payments $payments = null;
    private ?RefundAdapter $refunds = null;

    public static function getSubscribedEvents(): array
    {
        $events = parent::getSubscribedEvents();
        // The HikaShop compatibility handler unwraps the event into positional arguments.
        $events['onAjaxPaymos'] = 'onAjaxPaymos';
        return $events;
    }

    public function onPaymentConfiguration(&$element): void
    {
        parent::onPaymentConfiguration($element);
        if (($element->payment_type ?? null) !== $this->name) {
            return;
        }

        try {
            $this->bootServices();
            echo $this->connect->renderConnection(self::nativeId($element->payment_id ?? null));
        } catch (Throwable) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PAYMOS_STATUS_UNAVAILABLE'), 'error');
        }
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id): bool
    {
        if (($methods[$method_id]->payment_type ?? null) !== $this->name) {
            return false;
        }

        $this->paymosPaymentUrl = null;
        $orderId = self::nativeId($order->order_id ?? null);
        $methodId = self::nativeId($order->order_payment_id ?? null);
        try {
            if ($orderId > 0 && $methodId > 0 && $methodId === self::nativeId($method_id)) {
                parent::onAfterOrderConfirm($order, $methods, $method_id);
                $this->bootServices();
                $this->paymosPaymentUrl = $this->payments->start($orderId, $methodId);
                if ($this->showPage('end')) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Transport errors can contain provider response data.
        }

        Factory::getApplication()->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PAYMOS_STATUS_UNAVAILABLE'), 'error');
        return false;
    }

    public function onPaymentNotification(&$statuses): string
    {
        $app = Factory::getApplication();
        $server = $app->getInput()->server;
        $code = 503;
        if (!$app->isClient('site')) {
            $code = 404;
        } elseif ($server->getString('REQUEST_METHOD') !== 'POST') {
            $app->setHeader('Allow', 'POST', true);
            $code = 405;
        } else {
            try {
                $this->bootServices();
                $raw = file_get_contents('php://input', false, null, 0, Payments::MAX_WEBHOOK_BYTES + 1);
                $signature = $server->get('HTTP_X_WEBHOOK_SIGNATURE', '', 'raw');
                if ($raw !== false) {
                    $code = $this->payments->webhook($raw, is_string($signature) ? $signature : '');
                }
            } catch (Throwable) {
                // HikaShop copies callback output to its payment log.
            }
        }

        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('Status', match ($code) {
            200 => '200 OK',
            400 => '400 Bad Request',
            401 => '401 Unauthorized',
            404 => '404 Not Found',
            405 => '405 Method Not Allowed',
            default => '503 Service Unavailable',
        }, true);

        // HikaShop's system hook echoes the returned string and exits before Joomla sends headers.
        $app->sendHeaders();

        return $code === 200 ? 'OK' : 'Webhook rejected';
    }

    public function onAfterOrderProductsListingDisplay(&$order, $context): void
    {
        if ($context !== 'order_back_show' || ($order->order_payment_method ?? null) !== $this->name
            || !Factory::getApplication()->isClient('administrator')) {
            return;
        }

        $orderId = self::nativeId($order->order_id ?? null);
        $methodId = self::nativeId($order->order_payment_id ?? null);
        if ($orderId === 0 || $methodId === 0) {
            return;
        }

        try {
            $this->bootServices();
            $options = $this->refunds->panel($methodId, $orderId);
            echo $this->connect->renderOrderPanel($methodId, $orderId, $options);
        } catch (Throwable) {
            // Keep the native order view available during storage or configuration failures.
        }
    }

    public function onAjaxPaymos(AjaxEvent $event): void
    {
        try {
            $this->bootServices();
        } catch (Throwable) {
            $app = $event->getApplication();
            $app->setHeader('Cache-Control', 'no-store', true);
            $app->setHeader('Status', '503 Service Unavailable', true);
            $event->addResult(['ok' => false, 'status' => 'unavailable']);
            return;
        }

        $this->connect->handleAjax($event, function (string $action, int $methodId, int $orderId, array $input): array {
            if ($action === 'reconcile' && ($input['operation_id'] ?? null) === null) {
                return ['status' => $this->payments->reconcile($orderId, $methodId)];
            }

            return $this->refunds->adminAction($action, $methodId, $orderId, $input);
        });
    }

    private function bootServices(): void
    {
        if ($this->connect !== null) {
            return;
        }

        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('paymos_sdk_unavailable');
        }
        require_once $autoload;

        $store = self::$store ??= new Store(Factory::getContainer()->get(DatabaseInterface::class));
        $secret = (string) Factory::getApplication()->get('secret', '');
        $connect = new ConnectAdmin($store, Uri::root(), $secret);
        $refunds = new RefundAdapter($store, $connect, static function (int $orderId, string $status, array $history): void {
            // Call the order class directly so HikaShop applies the verified refund status.
            hikashop_get('class.order')->modifyOrder($orderId, $status, 'paymos', $history, null);
        });
        $payments = new Payments($store, $connect, static function (int $orderId, string $marker): void {
            hikashop_get('class.order')->modifyOrder($orderId, 'confirmed', 'paymos', ['notified' => 0, 'data' => $marker], null);
        }, [$refunds, 'callback']);

        $this->payments = $payments;
        $this->refunds = $refunds;
        $this->connect = $connect;
    }

    private static function nativeId(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }

        return filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => min(PHP_INT_MAX, 4294967295)],
        ]) ?: 0;
    }
}
