<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop;

use Closure;
use DateTimeImmutable;
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

defined('_JEXEC') or die;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private const PAID_NATIVE = ['confirmed', 'shipped', 'delivered'];
    private const PAID_INVOICE = ['paid', 'paid_over'];
    private const REFUNDED_STATUS = 'refunded';

    private readonly RefundStore $refunds;
    private readonly Closure $projectFullRefund;
    private readonly Closure $clientFactory;
    private readonly Closure $clock;

    /**
     * The projector calls HikaShop class.order::modifyOrder with the integer order ID
     * by reference, history.notified = 0 and email = null on the shared Store connection.
     */
    public function __construct(
        private readonly Store $store,
        private readonly ConnectAdmin $connect,
        callable $projectFullRefund,
        ?callable $clientFactory = null,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
        $this->refunds = new RefundStore($store, $this->clock);
        $this->projectFullRefund = Closure::fromCallable($projectFullRefund);
        $this->clientFactory = $clientFactory === null
            ? static fn (array $keys): Client => new Client(new ClientConfig(
                $keys['api_key'], $keys['api_secret'], $keys['base_url'], 15
            ))
            : Closure::fromCallable($clientFactory);
    }

    /** The caller enforces Joomla POST, ACL and CSRF. The order binding is rechecked here. */
    public function adminAction(string $action, int $methodId, int $orderId, array $input): array
    {
        $paymentKey = RefundStore::paymentKey($orderId);
        return $this->refunds->withPaymentLock($paymentKey, function () use ($action, $methodId, $orderId, $paymentKey, $input): array {
            if (($this->store->nativeOrder($orderId)['method_id'] ?? null) !== $methodId) {
                throw new \RuntimeException('refund_mapping_conflict');
            }

            if ($action === 'refund') {
                $intent = $this->submit($methodId, $orderId, $paymentKey, $input);
            } elseif ($action === 'reconcile' && is_string($input['operation_id'] ?? null)
                && RefundStore::validOperationId($input['operation_id'])) {
                $intent = $this->refunds->getIntent($input['operation_id']);
                $this->assertAdminBinding($intent, $methodId, $paymentKey);
                $intent = $this->reconcile($intent);
            } else {
                throw new \RuntimeException('refund_action_invalid');
            }

            return ['status' => $this->displayStatus($intent), 'operation_id' => $intent->operationId()];
        });
    }

    /** Read-only local order panel. Provider eligibility is checked on the explicit POST. */
    public function panel(int $methodId, int $orderId): array
    {
        if (($this->store->nativeOrder($orderId)['method_id'] ?? null) !== $methodId) {
            return [];
        }

        $paymentKey = RefundStore::paymentKey($orderId);
        $last = null;
        foreach ($this->refunds->forPayment($paymentKey) as $previous) {
            if ($previous->toArray()['method_id'] !== $methodId) {
                continue;
            }
            $last ??= $previous;
            if ($this->displayStatus($previous) === 'review') {
                $last = $previous;
                break;
            }
        }
        $attempt = $this->store->currentAttempt($orderId);
        $review = ($attempt['review_reason'] ?? null) !== null;
        $options = [
            'operation_id' => $last?->operationId(),
            'status' => $review ? 'review' : ($last === null ? ($attempt['status'] ?? null) : $this->displayStatus($last)),
            'allow_full' => false,
            'allow_overpayment' => false,
        ];
        if ($review || $attempt === null || !in_array($attempt['status'], self::PAID_INVOICE, true)) {
            return $options;
        }

        try {
            return $this->refunds->withPaymentLock($paymentKey, function () use ($methodId, $orderId, $paymentKey, $options): array {
                $this->refunds->assertNewActionAllowed($paymentKey);
                $snapshot = $this->nativeSnapshot($methodId, $orderId);
                $keys = $this->connect->credentials($methodId)[$snapshot['attempt']['environment']] ?? [];
                if ($this->supportsNewRefunds($keys) && $keys['project_id'] === $snapshot['attempt']['project_id']) {
                    $options['allow_full'] = $this->refunds->nativeRefundStatusAvailable();
                    $options['allow_overpayment'] = $snapshot['attempt']['status'] === 'paid_over';
                }

                return $options;
            });
        } catch (\Throwable) {
            return $options;
        }
    }

    /** Only Payments calls this, after signature, scope and event reservation checks. */
    public function callback(WebhookEvent $event, string $environment): void
    {
        $data = $event->toArray()['data'] ?? null;
        if (!str_starts_with($event->type(), 'withdrawal.') || !is_array($data)
            || !is_string($data['withdrawal_id'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $data['withdrawal_id'])
            || !is_string($data['invoice_id'] ?? null) || !is_string($data['project_id'] ?? null)
            || !in_array($environment, ['sandbox', 'live'], true)
            || ($data['is_test'] ?? null) !== ($environment === 'sandbox')) {
            throw new \InvalidArgumentException('refund_callback_invalid');
        }
        $attempt = $this->store->findAttemptByInvoice($data['invoice_id']);
        if ($attempt === null || $attempt['environment'] !== $environment || $attempt['project_id'] !== $data['project_id']) {
            throw new \InvalidArgumentException('refund_callback_scope_conflict');
        }

        $paymentKey = RefundStore::paymentKey($attempt['order_id']);
        $this->refunds->withPaymentLock($paymentKey, function () use ($attempt, $data, $environment, $paymentKey): void {
            $intent = $this->refunds->findByWithdrawalId($data['withdrawal_id']);
            $externalId = $data['external_order_id'] ?? null;
            if ($externalId !== null && (!is_string($externalId) || $externalId === '' || strlen($externalId) > 200)) {
                throw new \InvalidArgumentException('refund_callback_invalid');
            }
            if ($intent === null && $externalId !== null) {
                $intent = $this->refunds->findByExternalId($externalId);
            }
            if ($intent === null) {
                // A separately initiated refund is a review signal, not evidence of a native effect.
                $latest = $this->store->findAttemptByInvoice($data['invoice_id']);
                if ($latest === null || $latest['order_id'] !== $attempt['order_id'] || $latest['method_id'] !== $attempt['method_id']
                    || $latest['environment'] !== $environment || $latest['project_id'] !== $data['project_id']) {
                    throw new \InvalidArgumentException('refund_callback_scope_conflict');
                }
                $this->refunds->transaction(function () use ($latest): void {
                    $current = $this->store->currentAttempt($latest['order_id']);
                    if ($latest['review_reason'] === null || Store::isRecoverableNativeReview($latest['review_reason'])) {
                        $this->store->recordAttemptState($latest['order_id'], $latest['generation'], $latest['status'], 'paymos_refund_unbound');
                    }
                    if ($current !== null && $current['generation'] !== $latest['generation']
                        && ($current['review_reason'] === null || Store::isRecoverableNativeReview($current['review_reason']))) {
                        $this->store->recordAttemptState($current['order_id'], $current['generation'], $current['status'], 'paymos_refund_unbound');
                    }
                });
                return;
            }

            if ($intent->paymentKey() !== $paymentKey || $intent->toArray()['method_id'] !== $attempt['method_id']
                || $intent->invoiceId() !== $data['invoice_id'] || $intent->projectId() !== $data['project_id']
                || $intent->environment() !== $environment
                || ($externalId !== null && $externalId !== $intent->externalOrderId())
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $data['withdrawal_id'])) {
                throw new \InvalidArgumentException('refund_callback_scope_conflict');
            }

            if ($intent->withdrawalId() === null && $intent->cashState() === RefundStates::UNKNOWN) {
                // Recover the ID by replaying our original request. The event never binds an ID.
                $this->coordinator($intent)->submit($intent->operationId());
                $intent = $this->refunds->getIntent($intent->operationId());
                if ($intent->withdrawalId() === null && $intent->cashState() === RefundStates::UNKNOWN) {
                    throw new \RuntimeException('refund_callback_unverified');
                }
                if ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $data['withdrawal_id']) {
                    throw new \InvalidArgumentException('refund_callback_scope_conflict');
                }
            }

            $previousWrites = $this->refunds->observationWrites();
            $checked = $this->reconcile($intent);
            // Cached pending evidence cannot acknowledge a later callback if its reverse read failed.
            if ($this->refunds->observationWrites() === $previousWrites && !in_array($checked->cashState(),
                [RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW], true)) {
                throw new \RuntimeException('refund_callback_unverified');
            }
        });
    }

    private function submit(int $methodId, int $orderId, string $paymentKey, array $input): RefundIntent
    {
        $mode = $input['mode'] ?? null;
        $token = $input['request_token'] ?? null;
        if (array_diff(array_keys($input), ['mode', 'request_token']) !== [] || !in_array($mode, [RefundStates::FULL, RefundStates::OVERPAYMENT], true)
            || !is_string($token) || !preg_match('/\A[a-zA-Z0-9-]{16,64}\z/', $token)) {
            throw new \InvalidArgumentException('refund_mode_and_token_only');
        }

        $installation = $this->store->installationId();
        $intent = $this->refunds->findByRequestToken($installation, $token);
        if ($intent !== null) {
            $this->assertAdminBinding($intent, $methodId, $paymentKey);
            if ($intent->mode() !== $mode) {
                throw new \RuntimeException('refund_request_conflict');
            }
        } else {
            $this->refunds->assertNewActionAllowed($paymentKey);
            $snapshot = $this->nativeSnapshot($methodId, $orderId);
            $attempt = $snapshot['attempt'];
            $intent = new RefundIntent([
                'operation_id' => RefundStore::operationId(), 'installation_id' => $installation,
                'request_token' => $token, 'platform' => 'hikashop', 'native_payment_key' => $paymentKey,
                'method_id' => $methodId, 'native_snapshot' => $this->nativePin($snapshot),
                'invoice_id' => $attempt['invoice_id'], 'project_id' => $attempt['project_id'], 'environment' => $attempt['environment'],
                'mode' => $mode, 'full_draft' => $mode === RefundStates::FULL ? $this->draft($snapshot) : null,
            ]);
            $reason = $this->preflight($intent);
            if ($reason !== null) {
                throw new \RuntimeException($reason);
            }
            $intent = $this->refunds->createIntent($intent);
        }

        // The SDK persists UNKNOWN before POST and replays the same idempotency key.
        $this->coordinator($intent)->submit($intent->operationId());
        $intent = $this->refunds->getIntent($intent->operationId());
        if ($intent->cashState() === RefundStates::COMPLETED) {
            return $this->reconcile($intent);
        }
        $this->refunds->schedule($intent->operationId());
        return $this->refunds->getIntent($intent->operationId());
    }

    private function reconcile(RefundIntent $intent): RefundIntent
    {
        $this->coordinator($intent)->reconcile($intent->operationId());
        $this->refunds->schedule($intent->operationId());
        return $this->refunds->getIntent($intent->operationId());
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $keys = $this->keys($intent);
            if (!$this->supportsNewRefunds($keys)) {
                return 'refund_reconnect_required';
            }
            $snapshot = $this->newProjectionSnapshot($intent, false);
            $invoice = $this->client($keys, $intent->environment())->invoices()->get($intent->invoiceId());
            $this->store->assertOrderLock(RefundStore::orderId($intent->paymentKey()));
            if (!$this->matchesApiInvoice($invoice, $snapshot['attempt'])) {
                return 'refund_invoice_snapshot_conflict';
            }
            $snapshot = $this->newProjectionSnapshot($intent, false);
            if ($intent->mode() === RefundStates::OVERPAYMENT
                && ($snapshot['attempt']['status'] !== 'paid_over' || $invoice['status'] !== 'paid_over')) {
                return 'refund_server_action_unavailable';
            }

            // POST /v1/refunds determines availability, principal and recipient.
            return null;
        } catch (\Throwable) {
            return 'refund_preflight_unavailable';
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->refunds->getIntent($operationId);
        $orderId = RefundStore::orderId($intent->paymentKey());
        try {
            $order = $this->store->nativeOrder($orderId, true);
            $attempt = $this->boundAttempt($intent);
            if (!$this->matchesNativeMoney($order, $attempt, $intent)) {
                return $this->ambiguous($intent, 'refund_native_payment_changed');
            }

            $marker = $this->marker($intent);
            $row = $this->refunds->projectionRow($operationId);
            $histories = $this->refunds->markerHistories($orderId, $marker);
            if ($row === null) {
                if ($histories !== []) {
                    return $this->ambiguous($intent, 'refund_partial_native_write');
                }
                $this->newProjectionSnapshot($intent, true);
                return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
            }

            $refund = $intent->observation();
            $status = $intent->mode() === RefundStates::FULL ? self::REFUNDED_STATUS : $intent->toArray()['native_snapshot']['order_status'];
            $statusMatches = $intent->mode() === RefundStates::FULL
                ? $order['order_status'] === $status : in_array($order['order_status'], self::PAID_NATIVE, true);
            if (!$statusMatches || $refund === null || count($histories) !== 1
                || !$this->matchesHistory($histories[0], $orderId, $status, $marker, $this->historyAmount($intent, $refund))
                || (int) $row['order_id'] !== $orderId || (int) $row['history_id'] !== (int) $histories[0]['history_id']
                || $row['marker'] !== $marker) {
                return $this->ambiguous($intent, 'refund_native_marker_conflict');
            }

            $result = $this->projection($intent, $refund, (string) $row['history_id']);
            if (json_decode($row['result'], true, 64, JSON_THROW_ON_ERROR) !== $result->toArray()) {
                return $this->ambiguous($intent, 'refund_native_marker_conflict');
            }

            return $result;
        } catch (\Throwable) {
            return $this->ambiguous($intent, 'refund_native_projection_unavailable');
        }
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        return $this->refunds->transaction(function () use ($intent, $refund): NativeProjectionResult {
            if ($refund->status() !== 'completed' || $intent->observation()?->toArray() !== $refund->toArray()) {
                return $this->ambiguous($intent, 'refund_completion_unverified');
            }
            $found = $this->inspect($intent->operationId());
            if ($found->kind() !== NativeProjectionResult::ABSENT) {
                return $found;
            }

            $orderId = RefundStore::orderId($intent->paymentKey());
            $snapshot = $this->newProjectionSnapshot($intent, true);
            $marker = $this->marker($intent);
            $amount = $this->historyAmount($intent, $refund);
            $status = $intent->mode() === RefundStates::FULL ? self::REFUNDED_STATUS : $snapshot['order']['order_status'];
            if ($intent->mode() === RefundStates::FULL) {
                ($this->projectFullRefund)($orderId, $status, [
                    'notified' => 0, 'amount' => $amount, 'data' => $marker, 'type' => 'payment',
                ]);
            } else {
                $this->refunds->appendOverpaymentHistory($orderId, $status, $marker, $amount);
            }

            $saved = $this->store->nativeOrder($orderId, true);
            $histories = $this->refunds->markerHistories($orderId, $marker);
            if (!$this->matchesNativeMoney($saved, $snapshot['attempt'], $intent) || $saved['order_status'] !== $status
                || count($histories) !== 1 || !$this->matchesHistory($histories[0], $orderId, $status, $marker, $amount)) {
                throw new \RuntimeException('refund_native_history_unknown');
            }

            $historyId = (int) $histories[0]['history_id'];
            $result = $this->projection($intent, $refund, (string) $historyId);
            $this->refunds->saveNativeProjection($intent, $historyId, $marker, $result);

            return $result;
        });
    }

    private function newProjectionSnapshot(RefundIntent $intent, bool $forUpdate): array
    {
        $this->boundAttempt($intent);
        $snapshot = $this->nativeSnapshot($intent->toArray()['method_id'], RefundStore::orderId($intent->paymentKey()), $forUpdate);
        if ($snapshot['attempt']['invoice_id'] !== $intent->invoiceId() || $this->nativePin($snapshot) !== $intent->toArray()['native_snapshot']
            || ($intent->mode() === RefundStates::FULL
                && (!$this->refunds->nativeRefundStatusAvailable() || $this->draft($snapshot) !== $intent->fullDraft()))) {
            throw new \RuntimeException('refund_native_draft_changed');
        }

        return $snapshot;
    }

    private function nativeSnapshot(int $methodId, int $orderId, bool $forUpdate = false): array
    {
        $this->store->assertOrderLock($orderId);
        $this->refunds->assertTransactionalTables();
        $order = $this->store->nativeOrder($orderId, $forUpdate);
        $attempt = $this->store->currentAttempt($orderId);
        if ($order === null || $attempt === null || $attempt['invoice_id'] === null
            || $order['method_id'] !== $methodId || $attempt['method_id'] !== $methodId
            || $this->store->nativeMethod($methodId) === null || !in_array($order['order_status'], self::PAID_NATIVE, true)
            || !in_array($attempt['status'], self::PAID_INVOICE, true) || $attempt['review_reason'] !== null
            || !AmountGuard::amountsEqual($order['amount'], $attempt['amount']) || $order['currency'] !== $attempt['currency']) {
            throw new \RuntimeException('refund_native_payment_changed');
        }
        $marker = 'paymos:paid:' . hash('sha256', $attempt['invoice_id']);
        $effect = $this->store->findEffect($orderId, $attempt['invoice_id'], 'paid');
        if (($effect['marker'] ?? null) !== $marker || count($this->refunds->markerHistories($orderId, $marker)) !== 1) {
            throw new \RuntimeException('refund_payment_projection_unverified');
        }

        return ['order' => $order, 'attempt' => $attempt];
    }

    private function nativePin(array $snapshot): array
    {
        $order = $snapshot['order'];
        return ['amount_decimal' => $order['amount'], 'currency' => $order['currency'], 'order_status' => $order['order_status']];
    }

    private function draft(array $snapshot): array
    {
        $pin = $this->nativePin($snapshot);
        $scale = $this->refunds->nativeCurrencyScale($pin['currency']);
        [$whole, $fraction] = array_pad(explode('.', $pin['amount_decimal'], 2), 2, '');
        $minor = ltrim($whole . str_pad($fraction, $scale, '0'), '0');
        if (strlen($fraction) > $scale || !preg_match('/\A[1-9][0-9]*\z/', $minor)) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }

        return $pin + ['amount_minor' => $minor, 'currency_scale' => $scale, 'refunded_status' => self::REFUNDED_STATUS,
            'notify' => false, 'stock_policy' => $this->refunds->nativeStockPolicy()];
    }

    private function boundAttempt(RefundIntent $intent): array
    {
        $attempt = $this->store->findAttemptByInvoice($intent->invoiceId());
        if ($attempt === null || $attempt['order_id'] !== RefundStore::orderId($intent->paymentKey())
            || $attempt['method_id'] !== $intent->toArray()['method_id']
            || $attempt['environment'] !== $intent->environment() || $attempt['project_id'] !== $intent->projectId()) {
            throw new \RuntimeException('refund_mapping_conflict');
        }

        return $attempt;
    }

    private function matchesNativeMoney(?array $order, array $attempt, RefundIntent $intent): bool
    {
        $pin = $intent->toArray()['native_snapshot'];
        return $order !== null && $order['order_id'] === $attempt['order_id'] && $order['method_id'] === $attempt['method_id']
            && AmountGuard::amountsEqual($order['amount'], $attempt['amount']) && $order['currency'] === $attempt['currency']
            && AmountGuard::amountsEqual($order['amount'], $pin['amount_decimal']) && $order['currency'] === $pin['currency'];
    }

    private function matchesApiInvoice(array $invoice, array $attempt): bool
    {
        $order = $invoice['order'] ?? null;
        return ($invoice['invoice_id'] ?? null) === $attempt['invoice_id'] && ($invoice['project_id'] ?? null) === $attempt['project_id']
            && ($invoice['is_test'] ?? null) === ($attempt['environment'] === 'sandbox')
            && in_array($invoice['status'] ?? null, self::PAID_INVOICE, true) && ($invoice['paid_late_at'] ?? null) === null
            && is_array($order) && ($order['external_id'] ?? null) === $attempt['external_order_id']
            && is_string($order['amount'] ?? null) && AmountGuard::amountsEqual($order['amount'], $attempt['amount'])
            && ($order['currency'] ?? null) === $attempt['currency'];
    }

    private function assertAdminBinding(RefundIntent $intent, int $methodId, string $paymentKey): void
    {
        if ($intent->paymentKey() !== $paymentKey || $intent->toArray()['method_id'] !== $methodId
            || $intent->toArray()['installation_id'] !== $this->store->installationId()) {
            throw new \RuntimeException('refund_mapping_conflict');
        }
        $this->boundAttempt($intent);
    }

    private function supportsNewRefunds(array $keys): bool
    {
        return CredentialSet::hasCapability($keys, 'invoice_refunds_v1')
            && ($keys['refund_contract_version'] ?? null) === 1 && CredentialSet::refundsEnabled($keys);
    }

    private function keys(RefundIntent $intent): array
    {
        $attempt = $this->boundAttempt($intent);
        $keys = $this->connect->credentials($attempt['method_id'])[$intent->environment()] ?? null;
        if ($keys === null || $keys['project_id'] !== $intent->projectId()) {
            throw new \RuntimeException('refund_connection_scope_changed');
        }

        return $keys;
    }

    private function client(array $keys, string $environment): Client
    {
        return ($this->clientFactory)($keys, $environment);
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->client($this->keys($intent), $intent->environment()), $this->refunds, $this, $this->clock);
    }

    private function marker(RefundIntent $intent): string
    {
        return 'paymos:refund:' . $intent->operationId() . ':' . $intent->mode();
    }

    private function historyAmount(RefundIntent $intent, VerifiedRefund $refund): string
    {
        $draft = $intent->fullDraft();
        if ($draft !== null) {
            return $draft['amount_decimal'] . ' ' . $draft['currency'];
        }
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $refund->currency())
            || !preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $refund->network())) {
            throw new \RuntimeException('refund_native_currency_invalid');
        }

        return $refund->principalDecimal() . ' ' . $refund->currency() . '/' . $refund->network();
    }

    private function matchesHistory(array $history, int $orderId, string $status, string $marker, string $amount): bool
    {
        return (int) $history['history_id'] > 0 && (int) $history['history_order_id'] === $orderId
            && $history['history_new_status'] === $status && (int) $history['history_notified'] === 0
            && $history['history_amount'] === $amount && $history['history_data'] === $marker
            && $history['history_type'] === 'payment' && $history['history_payment_method'] === 'paymos';
    }

    private function projection(RefundIntent $intent, VerifiedRefund $refund, string $historyId): NativeProjectionResult
    {
        $draft = $intent->fullDraft();
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), $historyId,
            $draft['amount_minor'] ?? null, $draft['currency'] ?? null, $draft === null ? $refund->principalDecimal() : null,
            $draft === null ? $refund->currency() . '/' . $refund->network() : null);
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), reason: $reason);
    }

    private function displayStatus(RefundIntent $intent): string
    {
        if ($intent->projectionState() === RefundStates::NATIVE_REVIEW || $intent->cashState() === RefundStates::REVIEW
            || $intent->toArray()['review_reason'] === 'refund_unresolved_24h') {
            return 'review';
        }

        return match ($intent->cashState()) {
            RefundStates::COMPLETED => $intent->projectionState() === RefundStates::PROJECTION_APPLIED ? 'completed' : 'pending',
            RefundStates::FAILED => 'failed',
            RefundStates::CANCELLED => 'cancelled',
            default => 'pending',
        };
    }
}
