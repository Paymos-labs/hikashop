<?php

// Copyright (c) 2026 Paymos Labs
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace PaymosHikaShop;

use Closure;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;

defined('_JEXEC') or die;

final class ConnectAdmin
{
    private const CMS = 'hikashop';
    private const APPROVAL_ORIGIN = 'https://app.paymos.io';
    private const APPROVAL_PATH = '/connect/cms/verify';
    private const DEVICE_TIMEOUT = 15;
    private const MAX_DEVICE_TTL = 3600;
    private const AJAX_ROUTE = 'index.php?option=com_ajax&plugin=paymos&group=hikashoppayment&format=json';
    private const ORDER_STATUSES = [
        'reconciled', 'refund_recorded', 'pending', 'completed', 'failed', 'review',
        'paid', 'paid_over', 'paid_late', 'underpaid', 'underpaid_waiting', 'cancelled',
        'expired', 'awaiting_client', 'awaiting_payment', 'confirming',
    ];

    private readonly DeviceConnectClient $client;
    private readonly Closure $clock;

    public function __construct(
        private readonly Store $store,
        private readonly string $siteUrl,
        private readonly string $installationSecret,
        ?DeviceConnectClient $client = null,
        ?Closure $clock = null
    ) {
        $this->client = $client ?? new DeviceConnectClient(self::APPROVAL_ORIGIN, null, self::DEVICE_TIMEOUT);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function handleAjax(AjaxEvent $event, ?callable $orderAction = null): void
    {
        $app = $event->getApplication();
        $result = self::error('forbidden');
        try {
            $input = $app->getInput();
            if ($app->isClient('administrator') && $input->getCmd('option') === 'com_ajax'
                && $input->getCmd('plugin') === 'paymos' && $input->getCmd('group') === 'hikashoppayment'
                && $input->getWord('format') === 'json') {
                $user = $app->getIdentity();
                $session = $app->getSession();
                $method = $input->server->getString('REQUEST_METHOD');
                if (!$user->guest && (int) $user->id > 0 && !$session->isNew() && $method === 'POST') {
                    $post = $input->post;
                    // A live Joomla session and the exact form token must be present in POST.
                    $csrf = $post->get(Session::getFormToken(), null, 'raw') === '1' && Session::checkToken('post');
                    $context = [
                        'administrator' => true,
                        'method' => $method,
                        'user_id' => (int) $user->id,
                        'session_id' => $session->getId(),
                        'csrf' => $csrf,
                        'can_configure' => $this->canConfigure($user),
                        'can_manage_orders' => $this->canManageOrders($user),
                    ];
                    $request = [];
                    foreach (['action', 'method_id', 'order_id', 'mode', 'request_token', 'operation_id'] as $field) {
                        $request[$field] = $post->get($field, null, 'raw');
                    }
                    $result = $this->dispatch($request, $context, $orderAction);
                }
            }
        } catch (\Throwable) {
            $result = self::error('unavailable');
        }

        $app->setHeader('Cache-Control', 'no-store', true);
        if (!$result['ok']) {
            $status = match ($result['status']) {
                'forbidden' => '403 Forbidden',
                'not_found' => '404 Not Found',
                'unavailable' => '503 Service Unavailable',
                default => '400 Bad Request',
            };
            $app->setHeader('Status', $status, true);
        }
        $event->addResult($result);
    }

    /** $context is supplied by the Joomla adapter above, never from the submitted form. */
    public function dispatch(array $request, array $context, ?callable $orderAction = null): array
    {
        $userId = $context['user_id'] ?? 0;
        $sessionId = $context['session_id'] ?? '';
        if (($context['administrator'] ?? false) !== true || ($context['method'] ?? '') !== 'POST'
            || ($context['csrf'] ?? false) !== true || !is_int($userId) || $userId < 1
            || !is_string($sessionId) || $sessionId === '') {
            return self::error('forbidden');
        }

        $action = $request['action'] ?? null;
        if (!in_array($action, ['start', 'poll', 'mode', 'disconnect', 'refund', 'reconcile'], true)) {
            return self::error('invalid_request');
        }
        $orderOperation = $action === 'refund' || $action === 'reconcile';
        $requiredPermission = $orderOperation ? 'can_manage_orders' : 'can_configure';
        if (($context[$requiredPermission] ?? false) !== true) {
            return self::error('forbidden');
        }

        $methodId = self::positiveId($request['method_id'] ?? null);
        try {
            if ($methodId === null || $this->store->nativeMethod($methodId) === null) {
                return self::error('not_found');
            }
            if ($orderOperation) {
                return $this->orderAction($action, $methodId, $request, $orderAction);
            }

            return match ($action) {
                'start' => $this->start($methodId, $userId, $sessionId),
                'poll' => $this->poll($methodId, $userId, $sessionId),
                'mode' => $this->saveMode($methodId, $request['mode'] ?? null),
                'disconnect' => $this->disconnect($methodId),
            };
        } catch (\Throwable) {
            // Transport errors and bootstrap exceptions may contain remote response data.
            return self::error('unavailable');
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function credentials(int $methodId): array
    {
        $connection = $this->store->connection($methodId);
        $envelope = $connection['credentials_envelope'] ?? null;
        if ($envelope === null) {
            return [];
        }

        $data = AesGcmEnvelope::open($envelope, $this->material(), self::aad($methodId, 'credentials'));
        if (($data['plugin'] ?? null) !== self::CMS || ($data['source_url'] ?? null) !== $this->sourceUrl()) {
            throw new \RuntimeException('paymos_store_binding_changed');
        }
        return $this->normalizeCredentials($data['environments'] ?? []);
    }

    public function mode(int $methodId): string
    {
        return ($this->store->connection($methodId)['mode'] ?? null) === 'live' ? 'live' : 'sandbox';
    }

    public function renderConnection(int $methodId): string
    {
        $app = Factory::getApplication();
        if (!$app->isClient('administrator') || !$this->canConfigure($app->getIdentity())) {
            return '';
        }
        if ($this->store->nativeMethod($methodId) === null) {
            return '<p>' . self::label('SAVE_METHOD') . '</p>';
        }

        $connected = false;
        try {
            $connected = $this->credentials($methodId) !== [];
        } catch (\Throwable) {
            // A moved installation or unreadable key needs a new approval.
        }

        $mode = $connected ? $this->mode($methodId) : 'sandbox';
        $attrs = $this->panelAttributes($app, $methodId);
        $status = self::label($connected ? 'STATUS_CONNECTED' : 'STATUS_DISCONNECTED');
        $controls = $connected ? '' : ' hidden';
        $sandbox = $mode === 'sandbox' ? ' selected' : '';
        $live = $mode === 'live' ? ' selected' : '';
        return "<section $attrs><p data-paymos-status role=\"status\" aria-live=\"polite\">$status</p>"
            . self::button('start', 'CONNECT', 'btn btn-primary')
            . '<p data-paymos-device hidden><strong data-paymos-code></strong> '
            . '<a data-paymos-verification target="_blank" rel="noopener noreferrer">' . self::label('APPROVAL') . '</a></p>'
            . "<div class=\"mt-2\" data-paymos-connection-controls$controls>"
            . '<label>' . self::label('MODE_LABEL') . ' <select class="form-select" data-paymos-mode>'
            . "<option value=\"sandbox\"$sandbox>" . self::label('MODE_SANDBOX') . '</option>'
            . "<option value=\"live\"$live>" . self::label('MODE_LIVE') . '</option></select></label> '
            . self::button('mode', 'SAVE_MODE') . ' ' . self::button('disconnect', 'DISCONNECT') . '</div></section>';
    }

    public function renderOrderPanel(int $methodId, int $orderId, array $options = []): string
    {
        $app = Factory::getApplication();
        if (!$app->isClient('administrator') || !$this->canManageOrders($app->getIdentity())
            || ($this->store->nativeOrder($orderId)['method_id'] ?? null) !== $methodId
            || $this->store->nativeMethod($methodId) === null) {
            return '';
        }

        $operation = self::safeId($options['operation_id'] ?? null);
        $attrs = $this->panelAttributes($app, $methodId) . " data-paymos-order=\"$orderId\""
            . ' data-paymos-request-token="' . bin2hex(random_bytes(16)) . '"';
        if ($operation !== null) {
            $attrs .= ' data-paymos-operation="' . self::escape($operation) . '"';
        }

        $buttons = self::button('reconcile', $operation === null ? 'CHECK_PAYMENT' : 'CHECK_REFUND');
        if (($options['allow_full'] ?? false) === true) {
            $buttons .= ' ' . self::button('refund', 'REFUND_FULL', 'btn btn-outline-secondary', ' data-paymos-refund-mode="full"');
        }
        if (($options['allow_overpayment'] ?? false) === true) {
            $buttons .= ' ' . self::button('refund', 'REFUND_OVERPAYMENT', 'btn btn-outline-secondary', ' data-paymos-refund-mode="overpayment"');
        }

        $state = $options['status'] ?? null;
        $status = in_array($state, self::ORDER_STATUSES, true) ? self::label('STATUS_' . strtoupper($state)) : '';
        return "<section class=\"mt-3 mb-3\" $attrs><h3>" . self::label('ORDER_TITLE') . '</h3>'
            . "<p data-paymos-status role=\"status\" aria-live=\"polite\">$status</p>$buttons</section>";
    }

    private function start(int $methodId, int $userId, string $sessionId): array
    {
        try {
            $source = $this->sourceUrl();
        } catch (\InvalidArgumentException) {
            return self::error('https_required');
        }
        $material = $this->material();
        $device = $this->client->start(self::CMS, $source);
        $userCode = $device['user_code'];
        $deviceCode = $device['device_code'];
        $expiresIn = $device['expires_in'];
        $interval = $device['interval'];
        if (!preg_match('/\A[A-Z0-9-]{4,32}\z/i', $userCode) || $deviceCode === '' || strlen($deviceCode) > 4096
            || preg_match('/[\x00-\x20\x7F]/', $deviceCode) || $expiresIn <= $interval || $expiresIn > self::MAX_DEVICE_TTL) {
            return self::error('unavailable');
        }

        $verification = $this->approvalUrl($device['verification_url'], $userCode, $source);
        if ($verification === null) {
            return self::error('unavailable');
        }

        $flow = bin2hex(random_bytes(16));
        $envelope = AesGcmEnvelope::seal([
            'plugin' => self::CMS,
            'source_url' => $source,
            'session' => hash('sha256', $sessionId),
            'device_code' => $deviceCode,
            'interval' => $interval,
        ], $material, self::aad($methodId, 'device'));
        $now = ($this->clock)();
        $this->store->startDevice($methodId, $userId, $flow, $envelope, $now + $expiresIn, $now + $interval);

        return self::success('waiting', [
            'user_code' => $userCode, 'verification_url' => $verification, 'interval' => $interval,
        ]);
    }

    private function poll(int $methodId, int $userId, string $sessionId): array
    {
        $row = $this->store->connection($methodId);
        if (($row['device_flow_id'] ?? null) === null || ($row['device_envelope'] ?? null) === null) {
            return self::error('device_expired');
        }
        if ($row['device_user_id'] !== $userId) {
            return self::error('forbidden');
        }

        $flow = $row['device_flow_id'];
        $material = $this->material();
        $device = AesGcmEnvelope::open($row['device_envelope'], $material, self::aad($methodId, 'device'));
        $source = $this->sourceUrl();
        if (($device['plugin'] ?? null) !== self::CMS || ($device['source_url'] ?? null) !== $source
            || !is_string($device['session'] ?? null) || !hash_equals($device['session'], hash('sha256', $sessionId))) {
            return self::error('forbidden');
        }

        $now = ($this->clock)();
        if ($row['device_expires_at'] <= $now) {
            $this->store->clearDevice($methodId, $userId, $flow);
            return self::error('device_expired');
        }

        // Keep the reservation longer than the HTTP request and require the timestamp we read.
        // A stale request must not replace a longer interval persisted by another poll.
        $interval = max(self::DEVICE_TIMEOUT + 1, $device['interval']);
        $claimedUntil = $now + $interval;
        if ($this->store->claimDevicePoll($methodId, $userId, $flow, $now, $claimedUntil, $row['device_poll_at']) === null) {
            return $this->deviceWait($methodId, $userId, $flow);
        }

        try {
            $response = $this->client->poll($device['device_code']);
        } catch (\RuntimeException $error) {
            // The SDK's cURL transport currently carries timeout details in its transport error.
            // Keep this string on the server and return only the next polling interval.
            $message = $error->getMessage();
            if ($error->getCode() !== 28 && (!str_starts_with($message, 'Paymos HTTP error: ')
                || !preg_match('/\b(?:timed out|timeout)\b/i', $message))) {
                throw $error;
            }
            $response = ['status' => 'connection_timeout'];
        }
        switch ($response['status']) {
            case 'connected':
                try {
                    $matches = $response['plugin'] === self::CMS && $this->canonicalSource($response['source_url']) === $source;
                } catch (\InvalidArgumentException) {
                    $matches = false;
                }
                if (!$matches) {
                    $this->store->clearDevice($methodId, $userId, $flow);
                    return self::error('binding_mismatch');
                }
                $credentials = $this->normalizeCredentials($response['credentials']);
                $envelope = AesGcmEnvelope::seal([
                    'plugin' => self::CMS, 'source_url' => $source, 'environments' => $credentials,
                ], $material, self::aad($methodId, 'credentials'));

                return $this->store->completeDevice($methodId, $userId, $flow, $envelope, ($this->clock)())
                    ? self::success('connected')
                    : self::error('device_expired');
            case 'authorization_pending':
                return $this->deviceWait($methodId, $userId, $flow);
            case 'slow_down':
            case 'connection_timeout':
                $device['interval'] = min(self::MAX_DEVICE_TTL,
                    $response['status'] === 'slow_down' ? $interval + 5 : $interval * 2);
                $now = ($this->clock)();
                $retryAt = max($claimedUntil, $now + $device['interval']);
                $envelope = AesGcmEnvelope::seal($device, $material, self::aad($methodId, 'device'));
                $this->store->deferDevicePoll($methodId, $userId, $flow, $claimedUntil, $now, $retryAt, $envelope);
                return $this->deviceWait($methodId, $userId, $flow);
            case 'access_denied':
            case 'expired_token':
                $this->store->clearDevice($methodId, $userId, $flow);
                return self::error($response['status'] === 'access_denied' ? 'device_denied' : 'device_expired');
            default:
                return self::error('unavailable');
        }
    }

    private function deviceWait(int $methodId, int $userId, string $flowId): array
    {
        $row = $this->store->connection($methodId);
        $now = ($this->clock)();
        if (($row['device_user_id'] ?? null) !== $userId || ($row['device_flow_id'] ?? null) !== $flowId
            || $row['device_expires_at'] <= $now) {
            return self::error('device_expired');
        }

        return self::success('waiting', ['interval' => max(1, min($row['device_poll_at'], $row['device_expires_at']) - $now)]);
    }

    private function saveMode(int $methodId, mixed $mode): array
    {
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            return self::error('invalid_request');
        }
        if ($this->credentials($methodId) === [] || !$this->store->setMode($methodId, $mode)) {
            return self::error('not_found');
        }

        return self::success('mode_saved');
    }

    private function disconnect(int $methodId): array
    {
        $this->store->disconnect($methodId);
        return self::success('disconnected');
    }

    private function orderAction(string $action, int $methodId, array $request, ?callable $callback): array
    {
        $orderId = self::positiveId($request['order_id'] ?? null);
        if ($orderId === null || ($this->store->nativeOrder($orderId)['method_id'] ?? null) !== $methodId) {
            return self::error('not_found');
        }
        if ($callback === null) {
            return self::error('unavailable');
        }

        if ($action === 'refund') {
            $mode = $request['mode'] ?? null;
            $token = $request['request_token'] ?? null;
            if (!in_array($mode, ['full', 'overpayment'], true) || !is_string($token)
                || !preg_match('/\A[a-zA-Z0-9-]{16,64}\z/', $token)) {
                return self::error('invalid_request');
            }
            $payload = ['mode' => $mode, 'request_token' => $token];
        } else {
            $operation = $request['operation_id'] ?? null;
            if ($operation !== null && self::safeId($operation) === null) {
                return self::error('invalid_request');
            }
            $payload = ['operation_id' => $operation];
        }

        $result = $callback($action, $methodId, $orderId, $payload);
        $status = $result['status'] ?? null;
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            return self::error('unavailable');
        }
        $operation = self::safeId($result['operation_id'] ?? null);

        return self::success($status, $operation === null ? [] : ['operation_id' => $operation]);
    }

    private function sourceUrl(): string
    {
        return $this->canonicalSource($this->siteUrl);
    }

    private function canonicalSource(string $url): string
    {
        $parts = parse_url($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('paymos_https_required');
        }

        $path = preg_replace_callback('/%[0-9a-f]{2}/i', static function (array $match): string {
            $char = chr(hexdec(substr($match[0], 1)));
            return preg_match('/[a-z0-9._~-]/i', $char) ? $char : strtoupper($match[0]);
        }, $parts['path'] ?? '');
        if (preg_match('#(?:^|/)\.{1,2}(?:/|$)|%(?:2f|5c)#i', $path)) {
            throw new \InvalidArgumentException('paymos_https_required');
        }
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '';

        return 'https://' . strtolower($parts['host']) . $port . rtrim($path, '/');
    }

    private function approvalUrl(string $url, string $userCode, string $source): ?string
    {
        $parts = parse_url($url);
        if (strlen($url) > 4096 || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== parse_url(self::APPROVAL_ORIGIN, PHP_URL_HOST)
            || ($parts['port'] ?? 443) !== 443 || ($parts['path'] ?? null) !== self::APPROVAL_PATH
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        $expected = ['user_code' => $userCode, 'cms' => self::CMS, 'source_url' => $source];
        $parameters = [];
        foreach (explode('&', $parts['query'] ?? '') as $entry) {
            [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
            $key = urldecode($key);
            if (!array_key_exists($key, $expected) || isset($parameters[$key]) || urldecode($value) !== $expected[$key]) {
                return null;
            }
            $parameters[$key] = true;
        }
        if (count($parameters) !== count($expected)) {
            return null;
        }

        return self::APPROVAL_ORIGIN . self::APPROVAL_PATH . '?' . http_build_query($expected, '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizeCredentials(array $payload): array
    {
        $credentials = CredentialSet::normalize($payload);
        if (!isset($credentials['sandbox'], $credentials['live'])
            || $credentials['sandbox']['project_id'] !== $credentials['live']['project_id']) {
            throw new \RuntimeException('paymos_connect_incomplete');
        }

        return $credentials;
    }

    private function material(): string
    {
        if ($this->installationSecret === '') {
            throw new \RuntimeException('paymos_installation_secret_missing');
        }
        return $this->installationSecret . "\n" . $this->store->installationId();
    }

    private static function aad(int $methodId, string $purpose): string
    {
        return 'paymos-hikashop-v1:' . $methodId . ':' . $purpose;
    }

    private function canConfigure(object $user): bool
    {
        return !$user->guest && $user->authorise('core.manage', 'com_hikashop')
            && ($user->authorise('core.admin', 'com_hikashop') || $user->authorise('core.options', 'com_hikashop'));
    }

    private function canManageOrders(object $user): bool
    {
        return !$user->guest && $user->authorise('core.manage', 'com_hikashop') && $user->authorise('core.edit', 'com_hikashop')
            && function_exists('hikashop_config') && function_exists('hikashop_isAllowed')
            && \hikashop_isAllowed(\hikashop_config()->get('acl_order_manage', 'all'));
    }

    private function panelAttributes(object $app, int $methodId): string
    {
        $assets = $app->getDocument()->getWebAssetManager();
        $asset = 'plg_hikashoppayment_paymos.admin';
        if (!$assets->assetExists('script', $asset)) {
            // Joomla resolves the file from its installation root and adds the public base path.
            $assets->registerAndUseScript($asset, 'media/plg_hikashoppayment_paymos/admin.js',
                ['version' => 'auto'], ['defer' => true], ['core']);
            foreach (array_merge(self::ORDER_STATUSES, ['waiting', 'connected', 'disconnected', 'mode_saved', 'forbidden',
                'not_found', 'unavailable', 'invalid_request', 'https_required', 'device_expired', 'device_denied',
                'binding_mismatch', 'working']) as $status) {
                Text::script('PLG_HIKASHOPPAYMENT_PAYMOS_STATUS_' . strtoupper($status));
            }
            foreach (['CONFIRM_FULL', 'CONFIRM_OVERPAYMENT', 'CONFIRM_DISCONNECT', 'CHECK_REFUND'] as $key) {
                Text::script('PLG_HIKASHOPPAYMENT_PAYMOS_' . $key);
            }
        }
        return "data-paymos-admin data-paymos-method=\"$methodId\" data-paymos-endpoint=\""
            . self::escape(self::AJAX_ROUTE) . '" data-paymos-token="' . self::escape(Session::getFormToken())
            . '" data-paymos-approval-origin="' . self::escape(self::APPROVAL_ORIGIN) . '"';
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => min(PHP_INT_MAX, 4294967295)]]);
        return $id === false ? null : $id;
    }

    private static function safeId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value) ? $value : null;
    }

    private static function button(
        string $action,
        string $label,
        string $class = 'btn btn-outline-secondary',
        string $attributes = ''
    ): string {
        return "<button type=\"button\" class=\"$class\" data-paymos-action=\"$action\"$attributes>" . self::label($label) . '</button>';
    }

    private static function label(string $key): string
    {
        return self::escape(Text::_('PLG_HIKASHOPPAYMENT_PAYMOS_' . $key));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function success(string $status, array $details = []): array
    {
        return ['ok' => true, 'status' => $status] + $details;
    }

    private static function error(string $status): array
    {
        return ['ok' => false, 'status' => $status];
    }
}
