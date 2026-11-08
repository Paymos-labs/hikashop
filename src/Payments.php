<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop;

use Closure;
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Exception\ValidationException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\InvoiceReconciler;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementResult;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;

defined('_JEXEC') or die;

final class Payments
{
    public const MAX_WEBHOOK_BYTES = 65536;

    private const CHECKOUT_HOST = 'checkout.paymos.io';
    private const OPEN = ['awaiting_client', 'awaiting_payment', 'confirming', 'underpaid_waiting'];
    private const PAID = ['paid', 'paid_over'];
    private const CLOSED_UNPAID = ['cancelled', 'expired', 'underpaid'];
    private const NATIVE_PAYABLE = ['created', 'unpaid'];
    private const NATIVE_PAID = 'confirmed';
    private const NATIVE_PAID_STATUSES = [self::NATIVE_PAID, 'shipped', 'delivered'];
    private const EFFECT = 'paid';
    private const REFUND_PAGE_LIMIT = 100;
    private const PROJECTION_UNVERIFIED = 'paymos_native_projection_unverified';
    private const LATE_REVIEW = 'paymos_paid_late';
    private const PRIOR_PAYMENT_REVIEW = 'paymos_previous_attempt_paid';
    private const UNBOUND_REFUND_REVIEW = 'paymos_refund_unbound';
    private const FULL_REFUND_REVIEW = 'paymos_full_refund_before_payment';

    private readonly Closure $projectOrder;
    private readonly ?Closure $onVerifiedWithdrawal;
    private readonly Closure $clientFactory;

    /**
     * $projectOrder writes the HikaShop status and an exact history_data marker through
     * the Store connection, with notifications disabled. Only called inside a transaction.
     */
    public function __construct(
        private readonly Store $store,
        private readonly ConnectAdmin $connect,
        callable $projectOrder,
        ?callable $onVerifiedWithdrawal = null,
        ?callable $clientFactory = null
    ) {
        $this->projectOrder = Closure::fromCallable($projectOrder);
        $this->onVerifiedWithdrawal = $onVerifiedWithdrawal === null ? null : Closure::fromCallable($onVerifiedWithdrawal);
        $this->clientFactory = $clientFactory === null
            ? static fn (array $keys): Client => new Client(new ClientConfig(
                $keys['api_key'], $keys['api_secret'], $keys['base_url'], 15
            ))
            : Closure::fromCallable($clientFactory);
    }

    public function start(int $orderId, int $methodId): string
    {
        return $this->store->withOrderLock($orderId, function () use ($orderId, $methodId): string {
            $order = $this->payableOrder($orderId, $methodId);
            $environment = $this->connect->mode($methodId);
            $keys = $this->connect->credentials($methodId)[$environment] ?? null;
            if ($keys === null) {
                throw new \RuntimeException('paymos_connection_required');
            }

            $current = $this->store->currentAttempt($orderId);
            if ($current !== null && $this->needsPaymentReview($current)) {
                throw new \RuntimeException('paymos_payment_needs_review');
            }

            if ($current !== null && $current['invoice_id'] !== null) {
                $same = $current['environment'] === $environment && $current['project_id'] === $keys['project_id']
                    && $this->matchesOrder($order, $current);
                $url = $this->reuseOrClose($current, $same);
                if ($url !== null) {
                    $this->assertCheckoutSnapshot($current);
                    return $url;
                }
            }

            // beginAttempt commits the original request before HTTP. An unbound row
            // with changed inputs refuses a new generation until the old outcome is known.
            $attempt = $this->store->beginAttempt(
                $orderId, $methodId, $environment, $keys['project_id'], $order['amount'], $order['currency']
            );
            if ($attempt['status'] === 'rejected') {
                throw new \RuntimeException('paymos_invalid_invoice_amount');
            }

            $this->assertCheckoutSnapshot($attempt);
            try {
                $invoice = ($this->clientFactory)($keys, $environment)->invoices()->create([
                    'project_id' => $attempt['project_id'],
                    'external_order_id' => $attempt['external_order_id'],
                    'amount' => $attempt['amount'],
                    'currency' => $attempt['currency'],
                ]);
            } catch (ValidationException $error) {
                if ($error->statusCode() === 400 && $error->errorCode() === 'amount_too_many_decimals'
                    && $error->field() === 'amount') {
                    // This response proves that this POST did not create an invoice.
                    // It does not settle an earlier POST whose result was lost.
                    if (($current['generation'] ?? null) !== $attempt['generation']) {
                        $this->store->recordAttemptState($orderId, $attempt['generation'], 'rejected');
                    }
                    throw new \RuntimeException('paymos_invalid_invoice_amount');
                }
                throw $error;
            }

            $this->store->assertOrderLock($orderId);
            $attempt = $this->bindFromInvoice($attempt, $invoice);
            if (!in_array($attempt['status'], self::OPEN, true) || $this->isLate($invoice)) {
                // A retry may get an already completed invoice. Checkout cannot project it.
                if ($this->isLate($invoice)) {
                    $this->store->recordAttemptState($orderId, $attempt['generation'], $attempt['status'], self::LATE_REVIEW);
                }
                throw new \RuntimeException('paymos_payment_needs_review');
            }

            $this->assertCheckoutSnapshot($attempt);
            return $attempt['payment_url'];
        });
    }

    /** Only the signed HTTP entrypoint calls this method. Browser return does not. */
    public function webhook(string $rawBody, string $signature, ?int $now = null): int
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_WEBHOOK_BYTES) {
            return 400;
        }
        if ($signature === '' || strlen($signature) > 2048) {
            return 401;
        }

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 400;
        }
        if (!is_array($payload) || !EventStore::validId($payload['event_id'] ?? null)
            || !is_string($payload['event_type'] ?? null) || trim($payload['event_type']) === ''
            || !is_int($payload['version'] ?? null) || $payload['version'] < 1
            || !is_int($payload['occurred_at'] ?? null) || $payload['occurred_at'] < 0
            || !is_array($payload['data'] ?? null)) {
            return 400;
        }

        $data = $payload['data'];
        $invoiceId = $data['invoice_id'] ?? null;
        if (!self::validInvoiceId($invoiceId)) {
            return 400;
        }
        $type = $payload['event_type'];
        $withdrawal = str_starts_with($type, 'withdrawal.');
        if (!$withdrawal && !str_starts_with($type, 'invoice.')) {
            return 400;
        }

        $events = null;
        try {
            // Unauthenticated input selects a credential candidate. No mutation or API
            // call is made until the verifier checks the original bytes and reserves the event.
            $attempt = $this->store->findAttemptByInvoice($invoiceId);
            $externalId = is_array($data['order'] ?? null) ? ($data['order']['external_id'] ?? null) : null;
            if ($attempt === null && !$withdrawal && is_string($externalId)
                && preg_match('/\Ahikashop_[a-f0-9]{64}\z/', $externalId)) {
                $attempt = $this->store->findAttemptByExternalOrderId($externalId);
            }
            if ($attempt === null) {
                return 404;
            }

            $credentials = $this->connect->credentials($attempt['method_id']);
            if ($credentials === []) {
                return 503;
            }

            $events = new EventStore($this->store->database(), $attempt['method_id']);
            $secrets = array_map(static fn (array $keys): string => $keys['webhook_secret'], $credentials);
            $verified = (new MultiEnvironmentWebhookVerifier($secrets, $events))->process($signature, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();
            if ($environment !== $attempt['environment'] || ($data['is_test'] ?? null) !== ($environment === 'sandbox')
                || ($data['project_id'] ?? null) !== $attempt['project_id']
                || ($attempt['invoice_id'] !== null && $attempt['invoice_id'] !== $invoiceId)) {
                return 400;
            }

            return $this->store->withOrderLock($attempt['order_id'], function () use (
                $attempt, $data, $event, $events, $withdrawal, $environment, $invoiceId, $type
            ): int {
                $bound = $this->store->findAttemptByExternalOrderId($attempt['external_order_id']);
                if ($bound === null || ($bound['invoice_id'] !== null && $bound['invoice_id'] !== $invoiceId)) {
                    return 404;
                }
                if ($withdrawal) {
                    if ($this->onVerifiedWithdrawal === null) {
                        return 503;
                    }
                    ($this->onVerifiedWithdrawal)($event, $environment);
                    $this->store->transaction($bound['order_id'], static fn () => $events->commit());
                    return 200;
                }

                if (!$this->matchesInvoice($data, $bound, $invoiceId)) {
                    return 400;
                }
                [$client, $checkRefunds] = $this->clientFor($bound);
                $result = (new InvoiceReverseVerifier($client))->verify($event, [
                    'invoice_id' => $invoiceId,
                    'project_id' => $bound['project_id'],
                    'environment' => $bound['environment'],
                    'external_order_id' => $bound['external_order_id'],
                    'amount' => $bound['amount'],
                    'currency' => $bound['currency'],
                ]);
                $this->store->assertOrderLock($bound['order_id']);
                $invoice = $result->invoice();
                if (!$result->isVerified()) {
                    if ($result->reason() !== 'status_mismatch' || !$this->matchesInvoice($invoice, $bound, $invoiceId)) {
                        return 503;
                    }
                    // A newer API state won the race with delivery. Record receipt of
                    // this obsolete notification, but let the matching event/reconcile act.
                    $this->store->transaction($bound['order_id'], static fn () => $events->commit());
                    return 200;
                }

                $this->applyInvoice($bound, $invoice, $type === 'invoice.paid_late' || $this->isLate($invoice),
                    $checkRefunds ? $client : null, $events);
                return 200;
            });
        } catch (DuplicateEventException) {
            return 200;
        } catch (EventInProgressException) {
            return 503;
        } catch (SignatureMismatchException | TimestampSkewException) {
            return 401;
        } catch (\Throwable) {
            return 503;
        } finally {
            try {
                $events?->release();
            } catch (\Throwable) {
                // On storage failure the lease expires, so a later delivery can retry.
            }
        }
    }

    /** The administrator entrypoint checks ACL, CSRF and method ownership first. */
    public function reconcile(int $orderId, int $methodId): string
    {
        return $this->store->withOrderLock($orderId, function () use ($orderId, $methodId): string {
            $order = $this->store->nativeOrder($orderId);
            $attempt = $this->store->currentAttempt($orderId);
            if ($order === null || $order['method_id'] !== $methodId || $attempt === null
                || $attempt['method_id'] !== $methodId) {
                throw new \RuntimeException('paymos_attempt_missing');
            }
            if ($attempt['invoice_id'] === null) {
                return 'review';
            }

            [$client, $checkRefunds] = $this->clientFor($attempt);
            [$result] = (new InvoiceReconciler($client))->reconcile([$attempt['invoice_id']]);
            $this->store->assertOrderLock($orderId);
            $invoice = $result->invoice();
            if (!$this->matchesInvoice($invoice, $attempt, $attempt['invoice_id'])) {
                throw new \RuntimeException('paymos_invoice_binding_conflict');
            }

            return $this->applyInvoice($attempt, $invoice, $this->isLate($invoice), $checkRefunds ? $client : null);
        });
    }

    public static function trustedCheckoutUrl(mixed $url, ?string $invoiceId = null): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== self::CHECKOUT_HOST
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        $path = $parts['path'] ?? '';
        return str_starts_with($path, '/invoice/') && self::validInvoiceId(substr($path, 9))
            && ($invoiceId === null || $path === '/invoice/' . $invoiceId);
    }

    private static function validInvoiceId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Ainv_[a-zA-Z0-9_-]{1,124}\z/', $id) === 1;
    }

    private function payableOrder(int $orderId, int $methodId): array
    {
        $order = $this->store->nativeOrder($orderId);
        if ($order === null || $order['method_id'] !== $methodId || $order['amount'] === '0'
            || $order['amount'][0] === '-' || !in_array($order['order_status'], self::NATIVE_PAYABLE, true)
            || ($this->store->nativeMethod($methodId)['payment_published'] ?? 0) !== 1) {
            throw new \RuntimeException('paymos_order_not_payable');
        }
        return $order;
    }

    private function assertCheckoutSnapshot(array $attempt): void
    {
        $orderId = $attempt['order_id'];
        $this->store->assertOrderLock($orderId);
        $order = $this->payableOrder($orderId, $attempt['method_id']);
        $environment = $this->connect->mode($attempt['method_id']);
        $keys = $this->connect->credentials($attempt['method_id'])[$environment] ?? [];
        if (!$this->matchesOrder($order, $attempt) || $environment !== $attempt['environment']
            || ($keys['project_id'] ?? null) !== $attempt['project_id']) {
            throw new \RuntimeException('paymos_order_changed');
        }
    }

    private function reuseOrClose(array $attempt, bool $same): ?string
    {
        if (in_array($attempt['status'], self::PAID, true)) {
            throw new \RuntimeException('paymos_payment_needs_review');
        }

        $orderId = $attempt['order_id'];
        $invoiceId = $attempt['invoice_id'];
        [$client] = $this->clientFor($attempt);
        $invoice = $client->invoices()->get($invoiceId);
        $this->store->assertOrderLock($orderId);
        if (!$this->matchesInvoice($invoice, $attempt, $invoiceId)) {
            throw new \RuntimeException('paymos_invoice_binding_conflict');
        }

        $status = $invoice['status'];
        if ($this->isLate($invoice) || in_array($status, self::PAID, true)) {
            $reason = $this->isLate($invoice) ? self::LATE_REVIEW : null;
            $this->store->recordAttemptState($orderId, $attempt['generation'], $status, $reason);
            throw new \RuntimeException('paymos_payment_needs_review');
        }
        if ($same && !InvoiceRenewal::isRequired($invoice) && in_array($status, self::OPEN, true)
            && self::trustedCheckoutUrl($attempt['payment_url'], $invoiceId)) {
            return $attempt['payment_url'];
        }

        $closed = (new InvoiceReplacement($client))->close($invoiceId, $status);
        $this->store->assertOrderLock($orderId);
        if (!$closed->isClosed() || !in_array($closed->status(), self::CLOSED_UNPAID, true)) {
            throw new \RuntimeException('paymos_previous_invoice_unresolved');
        }

        if (in_array($status, self::OPEN, true) && $closed->reason() === InvoiceReplacementResult::REASON_ENDED_UNPAID) {
            // The SDK fallback read reports the terminal status, but omits the late-payment marker.
            $terminal = $client->invoices()->get($invoiceId);
            $this->store->assertOrderLock($orderId);
            if (!$this->matchesInvoice($terminal, $attempt, $invoiceId) || $terminal['status'] !== $closed->status()) {
                throw new \RuntimeException('paymos_previous_invoice_unresolved');
            }
            if ($this->isLate($terminal)) {
                $this->store->recordAttemptState($orderId, $attempt['generation'], $terminal['status'], self::LATE_REVIEW);
                throw new \RuntimeException('paymos_payment_needs_review');
            }
        }

        $this->store->recordAttemptState($orderId, $attempt['generation'], $closed->status());
        return null;
    }

    /** @return array{Client, bool} The original client and whether it negotiated refund contract v1. */
    private function clientFor(array $attempt): array
    {
        $environment = $attempt['environment'];
        $keys = $this->connect->credentials($attempt['method_id'])[$environment] ?? null;
        if ($keys === null || $keys['project_id'] !== $attempt['project_id']) {
            throw new \RuntimeException('paymos_original_project_unavailable');
        }
        return [
            ($this->clientFactory)($keys, $environment),
            CredentialSet::hasCapability($keys, 'invoice_refunds_v1') && ($keys['refund_contract_version'] ?? null) === 1,
        ];
    }

    private function bindFromInvoice(array $attempt, array $invoice): array
    {
        $id = $invoice['invoice_id'] ?? null;
        if (!self::validInvoiceId($id) || !$this->matchesInvoice($invoice, $attempt, $id)
            || !self::trustedCheckoutUrl($invoice['payment_url'] ?? null, $id)) {
            throw new \RuntimeException('paymos_invalid_invoice_response');
        }
        return $this->store->bindInvoice($attempt['order_id'], $attempt['generation'], $id, $invoice['payment_url'], $invoice['status']);
    }

    private function matchesInvoice(array $invoice, array $attempt, string $invoiceId): bool
    {
        $order = $invoice['order'] ?? null;
        return is_array($order) && ($invoice['invoice_id'] ?? null) === $invoiceId
            && ($attempt['invoice_id'] === null || $attempt['invoice_id'] === $invoiceId)
            && ($invoice['project_id'] ?? null) === $attempt['project_id']
            && ($invoice['is_test'] ?? null) === ($attempt['environment'] === 'sandbox')
            && in_array($invoice['status'] ?? null, [...self::OPEN, ...self::PAID, ...self::CLOSED_UNPAID], true)
            && ($order['external_id'] ?? null) === $attempt['external_order_id']
            && is_string($order['amount'] ?? null) && AmountGuard::amountsEqual($order['amount'], $attempt['amount'])
            && is_string($order['currency'] ?? null) && strtoupper($order['currency']) === $attempt['currency'];
    }

    private function matchesOrder(array $order, array $attempt): bool
    {
        return $order['method_id'] === $attempt['method_id'] && $order['currency'] === $attempt['currency']
            && AmountGuard::amountsEqual($order['amount'], $attempt['amount']);
    }

    private function isLate(array $invoice): bool
    {
        return ($invoice['paid_late_at'] ?? null) !== null;
    }

    private function needsPaymentReview(array $attempt): bool
    {
        return in_array($attempt['review_reason'], [
            self::LATE_REVIEW, self::PRIOR_PAYMENT_REVIEW, self::PROJECTION_UNVERIFIED, self::UNBOUND_REFUND_REVIEW,
            self::FULL_REFUND_REVIEW,
        ], true);
    }

    private function applyInvoice(
        array $attempt,
        array $invoice,
        bool $late,
        ?Client $refundClient,
        ?EventStore $events = null
    ): string {
        $orderId = $attempt['order_id'];
        $status = $invoice['status'];
        $invoiceId = $invoice['invoice_id'];
        $fullRefunded = false;
        // A signed but locally unbound withdrawal already requires review regardless of its mode.
        // Read the refund snapshot under the order lock before starting the SQL transaction.
        if ($refundClient !== null && !$late && in_array($status, self::PAID, true) && !$this->needsPaymentReview($attempt)
            && ($this->store->currentAttempt($orderId)['generation'] ?? null) === $attempt['generation']
            && $this->store->findEffect($orderId, $invoiceId, self::EFFECT) === null) {
            $fullRefunded = $this->hasCompletedFullRefund($refundClient, $attempt, $invoiceId);
            $this->store->assertOrderLock($orderId);
        }

        try {
            return $this->store->transaction($orderId, function () use ($orderId, $attempt, $invoice, $status, $late, $events, $fullRefunded): string {
                $order = $this->store->nativeOrder($orderId, true);
                $current = $this->store->currentAttempt($orderId);
                if ($attempt['invoice_id'] === null) {
                    $attempt = $this->bindFromInvoice($attempt, $invoice);
                    $current = $this->store->currentAttempt($orderId);
                }

                $review = $fullRefunded ? self::FULL_REFUND_REVIEW : $this->projectVerifiedPayment($attempt, $current, $order, $status, $late);
                $resolvedReview = null;
                if ($review === null && in_array($status, self::PAID, true)
                    && ($current['generation'] ?? null) === $attempt['generation']
                    && Store::isRecoverableNativeReview($attempt['review_reason'])) {
                    // The verified effect and its native history marker are already checked above.
                    $savedStatus = $this->store->nativeOrder($orderId, true)['order_status'] ?? null;
                    if (in_array($savedStatus, self::NATIVE_PAID_STATUSES, true)) {
                        $resolvedReview = $attempt['review_reason'];
                    } else {
                        $review = $attempt['review_reason'];
                    }
                }
                $this->store->recordAttemptState($orderId, $attempt['generation'], $status, $review, $resolvedReview);
                $events?->commit();
                return $review === null ? $status : 'review';
            });
        } catch (\UnexpectedValueException $error) {
            if ($error->getMessage() !== self::PROJECTION_UNVERIFIED) {
                throw $error;
            }
            // A missing native postcondition rolls back the write first. Record the
            // ambiguity separately and stop automatically projecting this attempt.
            return $this->store->transaction($orderId, function () use ($orderId, $attempt, $invoice, $status, $events): string {
                $this->store->nativeOrder($orderId, true);
                if ($attempt['invoice_id'] === null) {
                    $attempt = $this->bindFromInvoice($attempt, $invoice);
                }
                $this->store->recordAttemptState($orderId, $attempt['generation'], $status, self::PROJECTION_UNVERIFIED);
                $events?->commit();
                return 'review';
            });
        }
    }

    private function hasCompletedFullRefund(Client $client, array $attempt, string $invoiceId): bool
    {
        $page = $client->refunds()->listPage([
            'invoice_id' => $invoiceId, 'status' => ['completed'], 'limit' => self::REFUND_PAGE_LIMIT,
        ]);
        $items = $page['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > self::REFUND_PAGE_LIMIT
            || !array_key_exists('next_cursor', $page) || $page['next_cursor'] !== null) {
            throw new \RuntimeException('paymos_refund_snapshot_invalid');
        }

        $seen = [];
        $fullRefunded = false;
        foreach ($items as $refund) {
            if (!is_array($refund)) {
                throw new \RuntimeException('paymos_refund_snapshot_invalid');
            }
            $id = $refund['withdrawal_id'] ?? null;
            $externalId = $refund['external_order_id'] ?? null;
            $mode = $refund['mode'] ?? null;
            if (!is_string($id) || !preg_match('/\Awdr_[a-zA-Z0-9]{1,46}\z/', $id) || isset($seen[$id])
                || !is_string($externalId) || trim($externalId) === '' || ($refund['type'] ?? null) !== 'refund'
                || ($refund['invoice_id'] ?? null) !== $invoiceId || ($refund['project_id'] ?? null) !== $attempt['project_id']
                || ($refund['is_test'] ?? null) !== ($attempt['environment'] === 'sandbox')
                || ($refund['status'] ?? null) !== 'completed' || ($refund['is_final'] ?? null) !== true
                || !in_array($mode, ['full', 'overpayment'], true)) {
                throw new \RuntimeException('paymos_refund_snapshot_invalid');
            }
            $seen[$id] = true;
            $fullRefunded = $fullRefunded || $mode === 'full';
        }

        return $fullRefunded;
    }

    private function projectVerifiedPayment(array $attempt, ?array $current, ?array $order, string $status, bool $late): ?string
    {
        $orderId = $attempt['order_id'];
        $isCurrent = ($current['generation'] ?? null) === $attempt['generation'];
        if ($late || (!$isCurrent && in_array($status, self::PAID, true))) {
            if (!$isCurrent && $current !== null) {
                $this->store->recordAttemptState(
                    $orderId, $current['generation'], $current['status'], self::PRIOR_PAYMENT_REVIEW
                );
            }
            return $late ? self::LATE_REVIEW : self::PRIOR_PAYMENT_REVIEW;
        }
        if ($this->needsPaymentReview($attempt)) {
            return $attempt['review_reason'];
        }
        if (!in_array($status, self::PAID, true)) {
            return null;
        }
        if ($order === null || !$this->matchesOrder($order, $attempt) || $this->store->nativeMethod($attempt['method_id']) === null) {
            return Store::NATIVE_ORDER_CHANGED_REVIEW;
        }

        $invoiceId = $attempt['invoice_id'];
        $marker = 'paymos:paid:' . hash('sha256', $invoiceId);
        $effect = $this->store->findEffect($orderId, $invoiceId, self::EFFECT);
        $history = $this->countNativeMarkers($orderId, $marker);
        if ($effect !== null || $history !== 0) {
            return !in_array($order['order_status'], self::NATIVE_PAYABLE, true) && ($effect['marker'] ?? null) === $marker && $history === 1
                ? null : self::PROJECTION_UNVERIFIED;
        }
        if (!in_array($order['order_status'], self::NATIVE_PAYABLE, true)) {
            return Store::NATIVE_STATUS_CHANGED_REVIEW;
        }

        ($this->projectOrder)($orderId, $marker);
        $saved = $this->store->nativeOrder($orderId, true);
        if ($saved === null || !$this->matchesOrder($saved, $attempt)
            || $saved['order_status'] !== self::NATIVE_PAID || $this->countNativeMarkers($orderId, $marker) !== 1) {
            throw new \UnexpectedValueException(self::PROJECTION_UNVERIFIED);
        }
        $this->store->recordEffect($orderId, $invoiceId, self::EFFECT, $marker);
        return null;
    }

    private function countNativeMarkers(int $orderId, string $marker): int
    {
        $db = $this->store->database();
        $table = $db->quoteName('#__hikashop_history');
        $data = $db->quote($marker);
        $count = $db->setQuery("SELECT COUNT(*) FROM $table WHERE history_order_id = $orderId"
            . " AND history_payment_method = 'paymos' AND history_new_status = 'confirmed' AND BINARY history_data = $data")->loadResult();
        if (!is_numeric($count)) {
            throw new \RuntimeException('paymos_history_unavailable');
        }
        return (int) $count;
    }
}
