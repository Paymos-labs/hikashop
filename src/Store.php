<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryMonitorInterface;
use Paymos\Plugin\AmountGuard;

defined('_JEXEC') or die;

final class Store
{
    public const EVENTS = '#__hikashop_paymos_event';
    public const REFUNDS = '#__hikashop_paymos_refund';
    public const REFUND_PROJECTIONS = '#__hikashop_paymos_refund_projection';
    public const REFUND_EFFECTS = '#__hikashop_paymos_refund_effect';
    public const NATIVE_ORDER_CHANGED_REVIEW = 'paymos_native_order_changed';
    public const NATIVE_STATUS_CHANGED_REVIEW = 'paymos_native_status_changed';

    private const INSTALLATION = '#__hikashop_paymos_installation';
    private const CONNECTIONS = '#__hikashop_paymos_connection';
    private const ATTEMPTS = '#__hikashop_paymos_attempt';
    private const EFFECTS = '#__hikashop_paymos_effect';
    private const DEVICE_RESET = 'device_flow_id = NULL, device_user_id = NULL, device_envelope = NULL,'
        . ' device_expires_at = NULL, device_poll_at = NULL';

    private ?string $installation = null;
    private ?int $lockedOrder = null;
    private ?string $lockName = null;
    private mixed $lockedConnection = null;
    private bool $inTransaction = false;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function database(): DatabaseInterface
    {
        return $this->db;
    }

    public function installationId(): string
    {
        if ($this->installation !== null) {
            return $this->installation;
        }

        $table = $this->db->quoteName(self::INSTALLATION);
        $value = $this->db->setQuery("SELECT installation_id FROM $table WHERE id = 1")->loadResult();
        if ($value === null) {
            $hex = bin2hex(random_bytes(16));
            $id = implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
                substr($hex, 16, 4), substr($hex, 20)]);
            $this->write("INSERT INTO $table (id, installation_id) VALUES (1, " . $this->db->quote($id)
                . ') ON DUPLICATE KEY UPDATE id = id');
            $value = $this->db->setQuery("SELECT installation_id FROM $table WHERE id = 1")->loadResult();
        }
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $value)) {
            throw new \RuntimeException('paymos_installation_unavailable');
        }

        return $this->installation = $value;
    }

    /** @return array{payment_id:int,payment_type:string,payment_published:int}|null */
    public function nativeMethod(int $methodId): ?array
    {
        if ($methodId < 1) {
            return null;
        }

        $table = $this->db->quoteName('#__hikashop_payment');
        $row = $this->row("SELECT payment_id, payment_type, payment_published FROM $table WHERE payment_id = $methodId");
        if ($row === null || $row['payment_type'] !== 'paymos') {
            return null;
        }

        $row['payment_id'] = (int) $row['payment_id'];
        $row['payment_published'] = (int) $row['payment_published'];
        return $row;
    }

    /** @return array{order_id:int,method_id:int,order_payment_method:string,order_status:string,amount:string,currency:string}|null */
    public function nativeOrder(int $orderId, bool $forUpdate = false): ?array
    {
        if ($orderId < 1) {
            return null;
        }
        if ($forUpdate) {
            $this->assertTransaction($orderId);
        }

        $table = $this->db->quoteName('#__hikashop_order');
        $row = $this->row("SELECT order_id, order_payment_id, order_payment_method, order_status,"
            . " order_full_price, order_currency_id FROM $table WHERE order_id = $orderId"
            . ($forUpdate ? ' FOR UPDATE' : ''));
        if ($row === null || $row['order_payment_method'] !== 'paymos') {
            return null;
        }

        $methodId = $this->positiveId($row['order_payment_id']);
        $currencyId = $this->positiveId($row['order_currency_id']);
        $amount = is_string($row['order_full_price']) ? AmountGuard::normalizeDecimal($row['order_full_price']) : null;
        if ($methodId === null || $currencyId === null || $amount === null) {
            return null;
        }

        // Only the order row is locked. Orders sharing a currency do not lock the currency table.
        $currencies = $this->db->quoteName('#__hikashop_currency');
        $currency = $this->db->setQuery("SELECT currency_code FROM $currencies WHERE currency_id = $currencyId")->loadResult();
        if (!is_string($currency) || !preg_match('/\A[A-Z0-9]{3,12}\z/', strtoupper($currency))) {
            return null;
        }

        return [
            'order_id' => $orderId,
            'method_id' => $methodId,
            'order_payment_method' => $row['order_payment_method'],
            'order_status' => (string) $row['order_status'],
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ];
    }

    public function withOrderLock(int $orderId, callable $action): mixed
    {
        if ($this->lockedOrder !== null) {
            $this->assertOrderLock($orderId);
            $result = $action();
            $this->assertOrderLock($orderId);
            return $result;
        }

        if ($orderId < 1 || !is_callable([$this->db, 'getMonitor']) || !is_callable([$this->db, 'setMonitor'])) {
            throw new \RuntimeException('paymos_order_lock_unavailable');
        }

        $name = 'paymos:hika:' . substr(hash('sha256', $this->installationId() . ':' . $this->db->getPrefix() . ':' . $orderId), 0, 52);
        $quotedName = $this->db->quote($name);
        $previousMonitor = $this->db->getMonitor();
        $acquired = $this->db->setQuery("SELECT GET_LOCK($quotedName, 0)")->loadResult();
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('paymos_order_busy');
        }

        $this->lockedOrder = $orderId;
        $this->lockName = $name;
        $this->lockedConnection = $this->db->getConnection();
        try {
            // Joomla retries failed statements after reconnecting. Reject the replay before
            // a native order/history write can run on a connection without our transaction.
            $monitor = new class ($this->db, $this->lockedConnection, $previousMonitor) implements QueryMonitorInterface {
                public function __construct(
                    private readonly DatabaseInterface $db,
                    private readonly mixed $connection,
                    private readonly ?QueryMonitorInterface $previous
                ) {
                }

                public function startQuery(string $sql, ?array $boundParams = null): void
                {
                    if ($this->connection === null || $this->db->getConnection() !== $this->connection) {
                        throw new \RuntimeException('paymos_order_lock_lost');
                    }
                    $this->previous?->startQuery($sql, $boundParams);
                }

                public function stopQuery(): void
                {
                    $this->previous?->stopQuery();
                }
            };
            $this->db->setMonitor($monitor);
            $this->assertOrderLock($orderId);
            $result = $action();
            $this->assertOrderLock($orderId);
            return $result;
        } finally {
            try {
                if ($this->sameConnection()) {
                    $this->db->setQuery("SELECT RELEASE_LOCK($quotedName)")->loadResult();
                }
            } catch (\Throwable $failure) {
                // Closing the original session releases its advisory lock.
                $this->disconnectLockedConnection();
                throw $failure;
            } finally {
                $this->db->setMonitor($previousMonitor);
                $this->lockedOrder = null;
                $this->lockName = null;
                $this->lockedConnection = null;
            }
        }
    }

    public function assertOrderLock(int $orderId): void
    {
        if ($this->lockedOrder !== $orderId || $this->lockName === null) {
            throw new \RuntimeException('paymos_order_lock_required');
        }
        if (!$this->sameConnection()) {
            throw new \RuntimeException('paymos_order_lock_lost');
        }

        $quotedName = $this->db->quote($this->lockName);
        $owned = $this->db->setQuery("SELECT IS_USED_LOCK($quotedName) = CONNECTION_ID()")->loadResult();
        if ((string) $owned !== '1' || !$this->sameConnection()) {
            throw new \RuntimeException('paymos_order_lock_lost');
        }
    }

    public function transaction(int $orderId, callable $action): mixed
    {
        $this->assertOrderLock($orderId);
        if ($this->inTransaction) {
            return $action();
        }

        // The Mysqli driver's transactionCommit() can swallow a commit exception.
        // Use the monitored SQL path so an uncertain commit cannot produce a success ACK.
        $this->write('START TRANSACTION');
        $this->inTransaction = true;
        try {
            $result = $action();
            $this->assertOrderLock($orderId);
            $this->write('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($this->sameConnection()) {
                    $this->write('ROLLBACK');
                }
            } catch (\Throwable) {
                $this->disconnectLockedConnection();
            }
            throw $error;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function currentAttempt(int $orderId): ?array
    {
        $table = $this->db->quoteName(self::ATTEMPTS);
        return $this->attempt("SELECT * FROM $table WHERE order_id = $orderId ORDER BY generation DESC LIMIT 1");
    }

    public function findAttemptByInvoice(string $invoiceId): ?array
    {
        $table = $this->db->quoteName(self::ATTEMPTS);
        return $this->attempt("SELECT * FROM $table WHERE invoice_id = " . $this->db->quote($invoiceId));
    }

    public function findAttemptByExternalOrderId(string $externalId): ?array
    {
        $table = $this->db->quoteName(self::ATTEMPTS);
        return $this->attempt("SELECT * FROM $table WHERE external_order_id = " . $this->db->quote($externalId));
    }

    public function beginAttempt(
        int $orderId,
        int $methodId,
        string $environment,
        string $projectId,
        string $amount,
        string $currency
    ): array {
        $this->assertOrderLock($orderId);
        if ($this->inTransaction) {
            throw new \RuntimeException('paymos_attempt_requires_commit');
        }
        $amount = AmountGuard::normalizeDecimal($amount);
        if (!in_array($environment, ['sandbox', 'live'], true) || !preg_match('/\Aprj_[a-zA-Z0-9_-]{1,124}\z/', $projectId)
            || $amount === null || !preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,5})?\z/', $amount)
            || $amount === '0' || !preg_match('/\A[A-Z0-9]{3,12}\z/', $currency)) {
            throw new \InvalidArgumentException('paymos_attempt_invalid');
        }

        return $this->transaction($orderId, function () use ($orderId, $methodId, $environment, $projectId, $amount, $currency) {
            $order = $this->nativeOrder($orderId, true);
            if ($order === null || $order['method_id'] !== $methodId || $order['amount'] !== $amount
                || $order['currency'] !== $currency || ($this->nativeMethod($methodId)['payment_published'] ?? 0) !== 1) {
                throw new \RuntimeException('paymos_order_changed');
            }

            $current = $this->currentAttempt($orderId);
            if ($current !== null) {
                $sameSnapshot = $current['method_id'] === $methodId && $current['environment'] === $environment
                    && $current['project_id'] === $projectId && $current['amount'] === $amount
                    && $current['currency'] === $currency;
                if ($current['status'] === 'rejected') {
                    if ($current['invoice_id'] !== null) {
                        throw new \RuntimeException('paymos_attempt_open');
                    }
                    if ($sameSnapshot) {
                        return $current;
                    }
                } elseif (!in_array($current['status'], ['cancelled', 'expired', 'underpaid'], true)) {
                    if ($sameSnapshot) {
                        return $current;
                    }
                    throw new \RuntimeException('paymos_attempt_open');
                }
            }

            $generation = ($current['generation'] ?? 0) + 1;
            $externalId = 'hikashop_' . hash('sha256', $this->installationId() . ':' . $orderId . ':' . $generation);
            $table = $this->db->quoteName(self::ATTEMPTS);
            $values = implode(', ', [$orderId, $generation, $methodId, $this->db->quote($environment), $this->db->quote($projectId),
                $this->db->quote($externalId), $this->db->quote($amount), $this->db->quote($currency)]);
            $this->write("INSERT INTO $table (order_id, generation, method_id, environment, project_id,"
                . " external_order_id, amount, currency) VALUES ($values)");

            return $this->requireAttempt($orderId, $generation);
        });
    }

    public function bindInvoice(int $orderId, int $generation, string $invoiceId, string $paymentUrl, string $status): array
    {
        if (!preg_match('/\Ainv_[a-zA-Z0-9_-]{1,124}\z/', $invoiceId) || $paymentUrl === '' || strlen($paymentUrl) > 2048) {
            throw new \InvalidArgumentException('paymos_invoice_binding_invalid');
        }
        $this->validateState($status);

        return $this->transaction($orderId, function () use ($orderId, $generation, $invoiceId, $paymentUrl, $status) {
            $attempt = $this->currentAttempt($orderId);
            if ($attempt === null || $attempt['generation'] !== $generation || $attempt['status'] === 'rejected') {
                throw new \RuntimeException('paymos_attempt_changed');
            }
            if ($attempt['invoice_id'] !== null) {
                if ($attempt['invoice_id'] !== $invoiceId) {
                    throw new \RuntimeException('paymos_invoice_binding_conflict');
                }
                return $attempt;
            }

            $table = $this->db->quoteName(self::ATTEMPTS);
            $this->write("UPDATE $table SET invoice_id = " . $this->db->quote($invoiceId)
                . ', payment_url = ' . $this->db->quote($paymentUrl) . ', status = ' . $this->db->quote($status)
                . " WHERE order_id = $orderId AND generation = $generation AND invoice_id IS NULL");
            $bound = $this->requireAttempt($orderId, $generation);
            if ($bound['invoice_id'] !== $invoiceId) {
                throw new \RuntimeException('paymos_invoice_binding_conflict');
            }

            return $bound;
        });
    }

    public static function isRecoverableNativeReview(?string $reason): bool
    {
        return in_array($reason, [self::NATIVE_ORDER_CHANGED_REVIEW, self::NATIVE_STATUS_CHANGED_REVIEW], true);
    }

    public function recordAttemptState(
        int $orderId,
        int $generation,
        string $status,
        ?string $reviewReason = null,
        ?string $resolvedReviewReason = null
    ): void {
        $this->validateState($status);
        if ($reviewReason !== null && !preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $reviewReason)) {
            throw new \InvalidArgumentException('paymos_review_reason_invalid');
        }
        if ($resolvedReviewReason !== null && ($reviewReason !== null || !self::isRecoverableNativeReview($resolvedReviewReason)
            || !in_array($status, ['paid', 'paid_over'], true))) {
            throw new \InvalidArgumentException('paymos_review_resolution_invalid');
        }

        $this->transaction($orderId, function () use ($orderId, $generation, $status, $reviewReason, $resolvedReviewReason) {
            $attempt = $this->requireAttempt($orderId, $generation);
            // The caller may use rejected only when the API guarantees validation failed before create.
            if ($status === 'rejected') {
                if ($attempt['invoice_id'] !== null || !in_array($attempt['status'], ['creating', 'rejected'], true)) {
                    throw new \RuntimeException('paymos_attempt_rejection_conflict');
                }
            } elseif ($attempt['status'] === 'rejected' || ($attempt['invoice_id'] === null && $status !== 'creating')) {
                throw new \RuntimeException('paymos_attempt_not_bound');
            }

            $table = $this->db->quoteName(self::ATTEMPTS);
            if (in_array($attempt['status'], ['paid', 'paid_over'], true)
                && ($status !== 'paid_over' || $attempt['status'] === 'paid_over')) {
                $status = $attempt['status'];
            }

            $review = $reviewReason === null ? '' : ', review_reason = ' . $this->db->quote($reviewReason);
            if ($resolvedReviewReason !== null) {
                $review = ', review_reason = CASE WHEN BINARY review_reason = ' . $this->db->quote($resolvedReviewReason)
                    . ' THEN NULL ELSE review_reason END';
            }
            $this->write("UPDATE $table SET status = " . $this->db->quote($status) . $review
                . " WHERE order_id = $orderId AND generation = $generation");
        });
    }

    public function findEffect(int $orderId, string $invoiceId, string $effect): ?array
    {
        $table = $this->db->quoteName(self::EFFECTS);
        return $this->row("SELECT order_id, invoice_id, effect, marker FROM $table WHERE order_id = $orderId AND invoice_id = "
            . $this->db->quote($invoiceId) . ' AND effect = ' . $this->db->quote($effect));
    }

    public function recordEffect(int $orderId, string $invoiceId, string $effect, string $marker): void
    {
        $this->assertTransaction($orderId);
        if (!preg_match('/\Ainv_[a-zA-Z0-9_-]{1,124}\z/', $invoiceId) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,80}\z/', $effect)
            || !preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $marker)) {
            throw new \InvalidArgumentException('paymos_effect_invalid');
        }

        $existing = $this->findEffect($orderId, $invoiceId, $effect);
        if ($existing !== null) {
            if ($existing['marker'] !== $marker) {
                throw new \RuntimeException('paymos_effect_conflict');
            }
            return;
        }

        $table = $this->db->quoteName(self::EFFECTS);
        $values = implode(', ', [$orderId, $this->db->quote($invoiceId), $this->db->quote($effect), $this->db->quote($marker)]);
        $this->write("INSERT INTO $table (order_id, invoice_id, effect, marker) VALUES ($values)");
    }

    public function connection(int $methodId): ?array
    {
        $table = $this->db->quoteName(self::CONNECTIONS);
        $row = $this->row("SELECT * FROM $table WHERE method_id = $methodId");
        if ($row !== null) {
            foreach (['method_id', 'device_user_id', 'device_expires_at', 'device_poll_at'] as $field) {
                if ($row[$field] !== null) {
                    $row[$field] = (int) $row[$field];
                }
            }
        }

        return $row;
    }

    public function startDevice(int $methodId, int $userId, string $flowId, string $envelope, int $expiresAt, int $pollAt): void
    {
        if ($methodId < 1 || $userId < 1 || !preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $flowId)
            || $pollAt < 0 || $expiresAt <= $pollAt || !$this->validEnvelope($envelope)) {
            throw new \InvalidArgumentException('paymos_device_state_invalid');
        }

        $table = $this->db->quoteName(self::CONNECTIONS);
        $values = implode(', ', [$methodId, $userId, $this->db->quote($flowId), $this->db->quote($envelope), $expiresAt, $pollAt]);
        $this->write("INSERT INTO $table (method_id, device_user_id, device_flow_id, device_envelope,"
            . " device_expires_at, device_poll_at) VALUES ($values) ON DUPLICATE KEY UPDATE device_user_id = $userId,"
            . ' device_flow_id = ' . $this->db->quote($flowId) . ', device_envelope = ' . $this->db->quote($envelope)
            . ", device_expires_at = $expiresAt, device_poll_at = $pollAt");
    }

    public function claimDevicePoll(
        int $methodId,
        int $userId,
        string $flowId,
        int $now,
        int $nextPollAt,
        int $expectedPollAt
    ): ?array {
        if ($nextPollAt <= $now) {
            throw new \InvalidArgumentException('paymos_device_poll_invalid');
        }

        $table = $this->db->quoteName(self::CONNECTIONS);
        $where = $this->deviceWhere($methodId, $userId, $flowId);
        $this->write("UPDATE $table SET device_poll_at = $nextPollAt WHERE $where"
            . " AND device_expires_at > $now AND device_poll_at = $expectedPollAt AND device_poll_at <= $now");
        if ($this->db->getAffectedRows() !== 1) {
            return null;
        }

        // Another request may start a new flow or disconnect after the claim.
        $row = $this->connection($methodId);
        return $row !== null && $row['device_user_id'] === $userId && $row['device_flow_id'] === $flowId
            && $row['device_poll_at'] === $nextPollAt ? $row : null;
    }

    public function deferDevicePoll(
        int $methodId,
        int $userId,
        string $flowId,
        int $expectedPollAt,
        int $now,
        int $nextPollAt,
        string $envelope
    ): bool {
        if ($nextPollAt <= $now || $nextPollAt < $expectedPollAt || !$this->validEnvelope($envelope)) {
            throw new \InvalidArgumentException('paymos_device_poll_invalid');
        }

        $table = $this->db->quoteName(self::CONNECTIONS);
        $where = $this->deviceWhere($methodId, $userId, $flowId);
        $this->write("UPDATE $table SET device_poll_at = $nextPollAt, device_envelope = " . $this->db->quote($envelope)
            . " WHERE $where AND device_poll_at = $expectedPollAt AND device_expires_at > $now");
        return $this->db->getAffectedRows() === 1;
    }

    public function completeDevice(int $methodId, int $userId, string $flowId, string $credentialsEnvelope, int $now): bool
    {
        if (!$this->validEnvelope($credentialsEnvelope)) {
            throw new \InvalidArgumentException('paymos_credentials_envelope_invalid');
        }

        $table = $this->db->quoteName(self::CONNECTIONS);
        $where = $this->deviceWhere($methodId, $userId, $flowId);
        $this->write("UPDATE $table SET credentials_envelope = " . $this->db->quote($credentialsEnvelope)
            . ", mode = 'sandbox', " . self::DEVICE_RESET . " WHERE $where AND device_expires_at > $now");
        return $this->db->getAffectedRows() === 1;
    }

    public function clearDevice(int $methodId, int $userId, string $flowId): bool
    {
        $table = $this->db->quoteName(self::CONNECTIONS);
        $this->write("UPDATE $table SET " . self::DEVICE_RESET . ' WHERE ' . $this->deviceWhere($methodId, $userId, $flowId));
        return $this->db->getAffectedRows() === 1;
    }

    public function setMode(int $methodId, string $mode): bool
    {
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('paymos_mode_invalid');
        }

        $table = $this->db->quoteName(self::CONNECTIONS);
        $this->write("UPDATE $table SET mode = " . $this->db->quote($mode)
            . " WHERE method_id = $methodId AND credentials_envelope IS NOT NULL");
        $row = $this->connection($methodId);
        return $row !== null && $row['credentials_envelope'] !== null && $row['mode'] === $mode;
    }

    public function disconnect(int $methodId): void
    {
        $table = $this->db->quoteName(self::CONNECTIONS);
        $this->write("DELETE FROM $table WHERE method_id = $methodId");
    }

    private function sameConnection(): bool
    {
        return $this->lockedConnection !== null && $this->db->getConnection() === $this->lockedConnection;
    }

    private function disconnectLockedConnection(): void
    {
        if ($this->sameConnection()) {
            $this->db->disconnect();
        }
    }

    private function assertTransaction(int $orderId): void
    {
        $this->assertOrderLock($orderId);
        if (!$this->inTransaction) {
            throw new \RuntimeException('paymos_order_transaction_required');
        }
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        return $id === false ? null : $id;
    }

    private function row(string $sql): ?array
    {
        $result = $this->db->setQuery($sql)->loadAssoc();
        return is_array($result) ? $result : null;
    }

    private function write(string $sql): void
    {
        if ($this->db->setQuery($sql)->execute() === false) {
            throw new \RuntimeException('paymos_database_write_failed');
        }
    }

    private function attempt(string $sql): ?array
    {
        $row = $this->row($sql);
        if ($row === null) {
            return null;
        }

        $row['order_id'] = (int) $row['order_id'];
        $row['method_id'] = (int) $row['method_id'];
        $row['generation'] = (int) $row['generation'];
        $row['amount'] = is_string($row['amount']) ? AmountGuard::normalizeDecimal($row['amount']) : null;
        if ($row['amount'] === null || $row['amount'] === '0' || $row['amount'][0] === '-') {
            throw new \RuntimeException('paymos_attempt_amount_invalid');
        }

        return $row;
    }

    private function requireAttempt(int $orderId, int $generation): array
    {
        $table = $this->db->quoteName(self::ATTEMPTS);
        return $this->attempt("SELECT * FROM $table WHERE order_id = $orderId AND generation = $generation")
            ?? throw new \RuntimeException('paymos_attempt_missing');
    }

    private function validateState(string $state): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $state)) {
            throw new \InvalidArgumentException('paymos_attempt_state_invalid');
        }
    }

    private function validEnvelope(string $envelope): bool
    {
        return $envelope !== '' && strlen($envelope) <= 32768;
    }

    private function deviceWhere(int $methodId, int $userId, string $flowId): string
    {
        return "method_id = $methodId AND device_user_id = $userId AND device_flow_id = " . $this->db->quote($flowId);
    }
}
