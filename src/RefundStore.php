<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop;

use Closure;
use DateTimeImmutable;
use Joomla\Database\DatabaseInterface;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundRecipientIdentity;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

defined('_JEXEC') or die;

final class RefundStore implements RefundIntentStoreInterface
{
    private const TERMINAL = [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED];

    private readonly DatabaseInterface $db;
    private readonly Closure $clock;
    private ?int $lockedOrder = null;
    private int $transactionDepth = 0;
    private int $observationWrites = 0;

    public function __construct(private readonly Store $store, ?Closure $clock = null)
    {
        $this->db = $store->database();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public static function paymentKey(int $orderId): string
    {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('refund_order_invalid');
        }

        return 'hikashop:' . $orderId;
    }

    public static function orderId(string $paymentKey): int
    {
        if (!preg_match('/\Ahikashop:([1-9][0-9]{0,9})\z/', $paymentKey, $matches)) {
            throw new \RuntimeException('refund_mapping_conflict');
        }
        $id = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => min(PHP_INT_MAX, 4294967295)]]);
        return $id !== false ? $id : throw new \RuntimeException('refund_mapping_conflict');
    }

    public static function operationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function validOperationId(string $id): bool
    {
        return (bool) preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/', $id);
    }

    public function withPaymentLock(string $paymentKey, callable $action): mixed
    {
        $id = self::orderId($paymentKey);
        if ($this->lockedOrder !== null && $this->lockedOrder !== $id) {
            throw new \RuntimeException('refund_payment_lock_conflict');
        }

        return $this->store->withOrderLock($id, function () use ($id, $action): mixed {
            $previous = $this->lockedOrder;
            $this->lockedOrder = $id;
            try {
                return $action();
            } finally {
                $this->lockedOrder = $previous;
            }
        });
    }

    public function transaction(callable $action): mixed
    {
        $id = $this->lockedOrder ?? throw new \RuntimeException('refund_payment_lock_required');

        return $this->store->transaction($id, function () use ($action): mixed {
            ++$this->transactionDepth;
            try {
                return $action();
            } finally {
                --$this->transactionDepth;
            }
        });
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        if ($this->transactionDepth !== 0) {
            throw new \RuntimeException('refund_intent_requires_commit');
        }
        $this->assertLock($intent);

        return $this->transaction(function () use ($intent): RefundIntent {
            $data = $intent->toArray();
            $id = self::orderId($intent->paymentKey());
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                $previous = $existing->toArray();
                foreach (['platform', 'native_payment_key', 'method_id', 'invoice_id', 'project_id', 'environment',
                    'mode', 'full_draft', 'native_snapshot'] as $field) {
                    if (($previous[$field] ?? null) !== ($data[$field] ?? null)) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }

                return $existing;
            }

            if ($data['platform'] !== 'hikashop' || $data['installation_id'] !== $this->store->installationId()
                || !self::validOperationId($intent->operationId()) || !preg_match('/\A[a-zA-Z0-9-]{16,64}\z/', $data['request_token'])
                || !is_int($data['method_id'] ?? null) || $data['method_id'] < 1 || !is_array($data['native_snapshot'] ?? null)
                || $intent->withdrawalId() !== null || $intent->cashState() !== RefundStates::NOT_SUBMITTED
                || $intent->projectionState() !== RefundStates::PROJECTION_NONE || $intent->observation() !== null
                || $data['projection'] !== null || $data['effects'] !== []) {
                throw new \RuntimeException('refund_invalid_new_intent');
            }
            $this->assertNewActionAllowed($intent->paymentKey());

            $data['created_at'] = ($this->clock)()->getTimestamp();
            $table = $this->table(Store::REFUNDS);
            $this->write("INSERT INTO $table SET operation_id = " . $this->q($intent->operationId())
                . ', installation_id = ' . $this->q($data['installation_id']) . ', request_token = ' . $this->q($data['request_token'])
                . ', payment_key = ' . $this->q($intent->paymentKey()) . ', external_order_id = ' . $this->q($intent->externalOrderId())
                . ', invoice_id = ' . $this->q($intent->invoiceId()) . ", order_id = $id, method_id = " . $data['method_id']
                . ', payload = ' . $this->q($this->encode($data)) . ', created_at = ' . $data['created_at']
                . ', next_due = ' . $data['created_at']);

            return $this->getIntent($intent->operationId());
        });
    }

    public function assertNewActionAllowed(string $paymentKey): void
    {
        if ($this->lockedOrder !== self::orderId($paymentKey)) {
            throw new \RuntimeException('refund_payment_lock_required');
        }
        $this->store->assertOrderLock($this->lockedOrder);
        foreach ($this->forPayment($paymentKey) as $intent) {
            if (!in_array($intent->cashState(), self::TERMINAL, true) || $intent->projectionState() === RefundStates::NATIVE_REVIEW
                || ($intent->cashState() === RefundStates::COMPLETED
                    && ($intent->mode() === RefundStates::FULL || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED))) {
                throw new \RuntimeException('refund_payment_unresolved_or_refunded');
            }
        }
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        return $this->find('installation_id = ' . $this->q($installationId) . ' AND request_token = ' . $this->q($token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        return $this->find('operation_id = ' . $this->q($operationId)) ?? throw new \RuntimeException('refund_intent_missing');
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        return $this->find('external_order_id = ' . $this->q($key));
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        return $this->find('withdrawal_id = ' . $this->q($id));
    }

    /** @return list<RefundIntent> */
    public function forPayment(string $key): array
    {
        $table = $this->table(Store::REFUNDS);
        return array_map(fn (array $row): RefundIntent => $this->intent($row),
            $this->rows("SELECT * FROM $table WHERE payment_key = " . $this->q($key) . ' ORDER BY created_at DESC, operation_id DESC'));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id): string {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent);
            if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $id)) {
                return 'conflict';
            }
            if ($intent->withdrawalId() !== null) {
                return $intent->withdrawalId() === $id ? 'same' : 'conflict';
            }
            $other = $this->findByWithdrawalId($id);
            if ($other !== null) {
                return 'conflict';
            }

            $table = $this->table(Store::REFUNDS);
            $payload = array_replace($intent->toArray(), ['withdrawal_id' => $id]);
            try {
                $this->write("UPDATE $table SET withdrawal_id = " . $this->q($id) . ', payload = ' . $this->q($this->encode($payload))
                    . ' WHERE operation_id = ' . $this->q($operationId) . ' AND withdrawal_id IS NULL');
            } catch (\RuntimeException $error) {
                // An independent order can race for the globally unique withdrawal ID.
                $owner = $this->find('withdrawal_id = ' . $this->q($id), true);
                if ($owner !== null && $owner->operationId() !== $operationId) {
                    return 'conflict';
                }
                throw $error;
            }

            return $this->db->getAffectedRows() === 1 ? 'bound' : 'conflict';
        });
    }

    public function observationWrites(): int
    {
        return $this->observationWrites;
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot): void {
            $intent = $this->getIntent($operationId);
            $previous = $intent->observation();
            if ($snapshot->withdrawalId() !== $intent->withdrawalId() || $snapshot->invoiceId() !== $intent->invoiceId()
                || $snapshot->environment() !== $intent->environment() || $snapshot->projectId() !== $intent->projectId()
                || $snapshot->mode() !== $intent->mode() || $snapshot->externalOrderId() !== $intent->externalOrderId()
                || ($previous !== null && (!AmountGuard::amountsEqual($previous->principalDecimal(), $snapshot->principalDecimal())
                    || $previous->currency() !== $snapshot->currency() || $previous->network() !== $snapshot->network()
                    || !RefundRecipientIdentity::same($previous, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->update($intent, ['observation' => $snapshot->toArray()]);
        });
        ++$this->observationWrites;
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result): void {
            $intent = $this->getIntent($operationId);
            $saved = $intent->toArray()['projection'];
            $row = $this->projectionRow($operationId);
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
                || $row === null || $this->decode($row['result']) !== $result->toArray()
                || ($saved !== null && $saved !== $result->toArray())) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->update($intent, ['projection' => $result->toArray()]);
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason): void {
            if (!in_array($cashState, [RefundStates::NOT_SUBMITTED, RefundStates::UNKNOWN, RefundStates::CREATED,
                RefundStates::PROCESSING, ...self::TERMINAL, RefundStates::REVIEW], true)
                || !in_array($projectionState, [RefundStates::PROJECTION_NONE, RefundStates::PROJECTION_PENDING,
                    RefundStates::PROJECTION_APPLIED, RefundStates::NATIVE_REVIEW], true)) {
                throw new \InvalidArgumentException('refund_invalid_state');
            }
            if ($reason !== null && !preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $reason)) {
                $reason = 'refund_review_required';
            }
            $this->update($this->getIntent($operationId), [
                'cash_state' => $cashState, 'projection_state' => $projectionState, 'review_reason' => $reason,
            ]);
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($this->transactionDepth !== 0) {
            throw new \RuntimeException('refund_effect_requires_commit');
        }
        $where = $this->effectWhere($operationId, $kind, $generation);

        return $this->transaction(function () use ($operationId, $kind, $generation, $where): EffectClaim {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent);
            $table = $this->table(Store::REFUND_EFFECTS);
            $saved = $this->row("SELECT state FROM $table WHERE $where");
            if ($saved !== null && $saved['state'] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            if ($intent->cashState() !== RefundStates::COMPLETED || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED) {
                throw new \RuntimeException('refund_effect_not_ready');
            }
            if ($saved === null) {
                $this->write("INSERT INTO $table SET operation_id = " . $this->q($operationId) . ', kind = ' . $this->q($kind)
                    . ", generation = $generation, state = " . $this->q(RefundStates::EFFECT_STARTED));
            } else {
                $this->write("UPDATE $table SET state = " . $this->q(RefundStates::EFFECT_STARTED) . " WHERE $where");
            }

            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if (!in_array($state, [RefundStates::EFFECT_DELIVERED, RefundStates::EFFECT_FAILED_NO_EFFECT,
            RefundStates::EFFECT_UNKNOWN, RefundStates::EFFECT_SKIPPED], true)) {
            throw new \InvalidArgumentException('refund_effect_conflict');
        }
        $where = $this->effectWhere($operationId, $kind, $generation);

        $this->transaction(function () use ($operationId, $state, $where): void {
            $this->assertLock($this->getIntent($operationId));
            $table = $this->table(Store::REFUND_EFFECTS);
            $this->write("UPDATE $table SET state = " . $this->q($state) . " WHERE $where AND state = "
                . $this->q(RefundStates::EFFECT_STARTED));
            if ($this->db->getAffectedRows() !== 1) {
                throw new \RuntimeException('refund_effect_conflict');
            }
        });
    }

    public function listDue(DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $limit = max(1, min(100, $limit));
        $after = '';
        if ($cursor !== null) {
            $value = json_decode((string) base64_decode($cursor, true), true);
            if (!is_array($value) || count($value) !== 2 || !is_int($value[0] ?? null)
                || !is_string($value[1] ?? null) || !self::validOperationId($value[1])) {
                throw new \InvalidArgumentException('refund_invalid_cursor');
            }
            $after = " AND (next_due > {$value[0]} OR (next_due = {$value[0]} AND operation_id > " . $this->q($value[1]) . '))';
        }
        $table = $this->table(Store::REFUNDS);
        $rows = $this->rows("SELECT * FROM $table WHERE next_due <= " . $now->getTimestamp() . $after
            . ' ORDER BY next_due, operation_id LIMIT ' . ($limit + 1));
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);

        return new RefundIntentPage(array_map(fn (array $row): RefundIntent => $this->intent($row), $rows),
            $more ? base64_encode($this->encode([(int) $last['next_due'], $last['operation_id']])) : null);
    }

    public function schedule(string $operationId): void
    {
        $this->transaction(function () use ($operationId): void {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent);
            $data = $intent->toArray();
            $table = $this->table(Store::REFUNDS);
            $where = 'operation_id = ' . $this->q($operationId);
            $row = $this->row("SELECT poll_count FROM $table WHERE $where");
            $now = ($this->clock)()->getTimestamp();
            $states = [RefundStates::NOT_SUBMITTED, RefundStates::UNKNOWN, RefundStates::CREATED, RefundStates::PROCESSING];
            $overdue = $now - (int) $data['created_at'] >= 86400 && in_array($intent->cashState(), $states, true);
            if ($overdue && $data['review_reason'] === null) {
                // Preserve the recoverable cash state and its original idempotency key.
                $this->recordState($operationId, $intent->cashState(), $intent->projectionState(), 'refund_unresolved_24h');
            }

            $delays = [60, 120, 300, 900, 3600];
            $delay = $overdue || in_array($intent->cashState(), [...self::TERMINAL, RefundStates::REVIEW], true)
                ? 86400 : $delays[min((int) ($row['poll_count'] ?? 0), count($delays) - 1)];
            if ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() === RefundStates::PROJECTION_PENDING) {
                $delay = 60;
            }
            $this->write("UPDATE $table SET next_due = " . ($now + $delay) . ", poll_count = poll_count + 1 WHERE $where");
        });
    }

    public function assertTransactionalTables(): void
    {
        $native = ['order', 'history', 'product', 'order_product', 'discount', 'user'];
        $tables = array_map(fn (string $name): string => $this->q($this->db->getPrefix() . 'hikashop_' . $name), $native);
        $count = $this->db->setQuery('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            . " AND ENGINE = 'InnoDB' AND TABLE_NAME IN (" . implode(', ', $tables) . ')')->loadResult();
        if ((int) $count !== count($native)) {
            throw new \RuntimeException('refund_requires_innodb');
        }
    }

    public function nativeCurrencyScale(string $currency): int
    {
        $table = $this->table('#__hikashop_currency');
        $raw = $this->db->setQuery("SELECT currency_locale FROM $table WHERE currency_code = " . $this->q($currency))->loadResult();
        if (!is_string($raw)) {
            throw new \RuntimeException('refund_native_currency_unavailable');
        }
        // HikaShop's native default for an empty locale is two fraction digits.
        $locale = $raw === '' ? ['int_frac_digits' => 2] : @unserialize($raw, ['allowed_classes' => false]);
        $scale = is_array($locale) ? filter_var($locale['int_frac_digits'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 12]]) : false;
        return $scale !== false ? $scale : throw new \RuntimeException('refund_native_currency_unavailable');
    }

    public function nativeRefundStatusAvailable(): bool
    {
        $table = $this->table('#__hikashop_orderstatus');
        return (int) $this->db->setQuery("SELECT COUNT(*) FROM $table WHERE orderstatus_namekey = 'refunded'"
            . ' AND orderstatus_published = 1')->loadResult() === 1;
    }

    public function nativeStockPolicy(): array
    {
        $table = $this->table('#__hikashop_config');
        $names = ['update_stock_after_confirm', 'order_created_status', 'cancelled_order_status',
            'stock_order_statuses', 'invoice_order_statuses'];
        $rows = $this->rows("SELECT config_namekey, config_value FROM $table WHERE config_namekey IN ("
            . implode(', ', array_map(fn (string $name): string => $this->q($name), $names)) . ')');
        $policy = array_fill_keys($names, null);
        foreach ($rows as $row) {
            $policy[$row['config_namekey']] = (string) $row['config_value'];
        }
        return $policy;
    }

    public function projectionRow(string $operationId): ?array
    {
        $table = $this->table(Store::REFUND_PROJECTIONS);
        return $this->row("SELECT * FROM $table WHERE operation_id = " . $this->q($operationId));
    }

    public function markerHistories(int $orderId, string $marker): array
    {
        $table = $this->table('#__hikashop_history');
        return $this->rows("SELECT history_id, history_order_id, history_new_status, history_notified,"
            . " history_amount, history_payment_method, history_type, history_data FROM $table"
            . " WHERE history_order_id = $orderId AND BINARY history_data = " . $this->q($marker));
    }

    public function appendOverpaymentHistory(int $orderId, string $status, string $marker, string $amount): void
    {
        $this->assertNativeWrite($orderId);
        $table = $this->table('#__hikashop_history');
        $time = ($this->clock)()->getTimestamp();
        // A history-only write intentionally leaves the order, fulfilment and stock untouched.
        $this->write("INSERT INTO $table SET history_order_id = $orderId, history_created = $time, history_new_status = "
            . $this->q($status) . ', history_notified = 0, history_amount = ' . $this->q($amount)
            . ", history_payment_method = 'paymos', history_type = 'payment',"
            . " history_reason = 'Paymos overpayment refund completed.', history_data = " . $this->q($marker));
    }

    public function saveNativeProjection(RefundIntent $intent, int $historyId, string $marker, NativeProjectionResult $result): void
    {
        $this->assertLock($intent);
        $id = self::orderId($intent->paymentKey());
        $this->assertNativeWrite($id);
        $table = $this->table(Store::REFUND_PROJECTIONS);
        $this->write("INSERT INTO $table SET operation_id = " . $this->q($intent->operationId()) . ", order_id = $id,"
            . " history_id = $historyId, marker = " . $this->q($marker) . ', result = ' . $this->q($this->encode($result->toArray())));
    }

    private function assertNativeWrite(int $orderId): void
    {
        if ($this->transactionDepth === 0 || $this->lockedOrder !== $orderId) {
            throw new \RuntimeException('refund_native_transaction_required');
        }
        $this->store->assertOrderLock($orderId);
    }

    private function assertLock(RefundIntent $intent): void
    {
        $orderId = self::orderId($intent->paymentKey());
        if ($this->lockedOrder !== $orderId) {
            throw new \RuntimeException('refund_payment_lock_required');
        }
        $this->store->assertOrderLock($orderId);
    }

    private function find(string $where, bool $currentRead = false): ?RefundIntent
    {
        $table = $this->table(Store::REFUNDS);
        $row = $this->row("SELECT * FROM $table WHERE $where LIMIT 1" . ($currentRead ? ' FOR UPDATE' : ''));
        return $row === null ? null : $this->intent($row);
    }

    private function intent(array $row): RefundIntent
    {
        $data = $this->decode($row['payload']);
        foreach (['operation_id', 'installation_id', 'request_token', 'external_order_id', 'withdrawal_id', 'invoice_id'] as $field) {
            if (($data[$field] ?? null) !== $row[$field]) {
                throw new \RuntimeException('refund_storage_binding_conflict');
            }
        }
        if ($data['native_payment_key'] !== $row['payment_key'] || $data['platform'] !== 'hikashop'
            || self::orderId($row['payment_key']) !== (int) $row['order_id'] || $data['method_id'] !== (int) $row['method_id']) {
            throw new \RuntimeException('refund_storage_binding_conflict');
        }
        return new RefundIntent($data);
    }

    private function update(RefundIntent $intent, array $changes): void
    {
        $this->assertLock($intent);
        $table = $this->table(Store::REFUNDS);
        $this->write("UPDATE $table SET payload = " . $this->q($this->encode(array_replace($intent->toArray(), $changes)))
            . ' WHERE operation_id = ' . $this->q($intent->operationId()));
    }

    private function effectWhere(string $operationId, string $kind, int $generation): string
    {
        if (!self::validOperationId($operationId) || !preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $kind) || $generation < 1) {
            throw new \InvalidArgumentException('refund_effect_conflict');
        }
        return 'operation_id = ' . $this->q($operationId) . ' AND kind = ' . $this->q($kind) . " AND generation = $generation";
    }

    private function table(string $name): string
    {
        return $this->db->quoteName($name);
    }

    private function q(string $value): string
    {
        return $this->db->quote($value);
    }

    private function row(string $sql): ?array
    {
        $value = $this->db->setQuery($sql)->loadAssoc();
        return is_array($value) ? $value : null;
    }

    private function rows(string $sql): array
    {
        return $this->db->setQuery($sql)->loadAssocList() ?: [];
    }

    private function write(string $sql): void
    {
        if ($this->db->setQuery($sql)->execute() === false) {
            throw new \RuntimeException('refund_database_write_failed');
        }
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(string $data): array
    {
        $value = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : throw new \RuntimeException('refund_storage_invalid');
    }
}
