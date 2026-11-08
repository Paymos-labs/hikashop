<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop\Tests;

use Joomla\Database\Mysqli\MysqliDriver;
use Joomla\Database\QueryMonitorInterface;
use PaymosHikaShop\Store;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

final class StoreTest extends TestCase
{
    public function testCanonicalOrderDecimalIsReadWithoutFloatOrForeignMethod(): void
    {
        $order = $this->nativeOrder();
        [$db, $fixture] = $this->database(static function (string $sql) use (&$order) {
            return str_contains($sql, 'hikashop_order') ? $order : 'eur';
        });

        $store = new Store($db);
        self::assertSame([
            'order_id' => 42,
            'method_id' => 7,
            'order_payment_method' => 'paymos',
            'order_status' => 'created',
            'amount' => '12.34',
            'currency' => 'EUR',
        ], $store->nativeOrder(42));

        $order['order_full_price'] = 12.34;
        self::assertNull($store->nativeOrder(42));
        $order['order_full_price'] = '12.34000';
        $order['order_payment_id'] = '007';
        self::assertNull($store->nativeOrder(42));
        $order['order_payment_id'] = '7';
        $order['order_payment_method'] = 'paypal';
        self::assertNull($store->nativeOrder(42));

        $currencyQueries = array_filter($fixture->queries, static fn (string $sql): bool => str_contains($sql, 'hikashop_currency'));
        self::assertCount(1, $currencyQueries);
    }

    public function testLockedOrderAndAtomicEffectRecord(): void
    {
        $record = null;
        [$db, $fixture] = $this->database(function (string $sql) use (&$record) {
            if (str_contains($sql, 'hikashop_order')) {
                return $this->nativeOrder();
            }
            if (str_contains($sql, 'hikashop_currency')) {
                return 'EUR';
            }
            if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'paymos_effect')) {
                $record = ['order_id' => 42, 'invoice_id' => 'inv_A', 'effect' => 'paid', 'marker' => 'paymos:inv_A:paid'];
                return true;
            }

            return $record;
        });
        $store = new Store($db);

        $store->withOrderLock(42, fn () => $store->transaction(42, function () use ($store) {
            self::assertSame('12.34', $store->nativeOrder(42, true)['amount']);
            $store->recordEffect(42, 'inv_A', 'paid', 'paymos:inv_A:paid');
            $store->recordEffect(42, 'inv_A', 'paid', 'paymos:inv_A:paid');
        }));
        self::assertSame('paymos:inv_A:paid', $store->findEffect(42, 'inv_A', 'paid')['marker']);

        $queries = implode("\n", $fixture->queries);
        self::assertStringContainsString('WHERE order_id = 42 FOR UPDATE', $queries);
        self::assertSame(1, substr_count($queries, 'INSERT INTO'));
        self::assertStringContainsString('START TRANSACTION', $queries);
        self::assertStringContainsString('COMMIT', $queries);
        self::assertStringContainsString('RELEASE_LOCK', $queries);

        $this->expectExceptionMessage('paymos_order_lock_required');
        $store->nativeOrder(42, true);
    }

    public function testReconnectionDoesNotReplayNativeSqlOutsideTransaction(): void
    {
        $oldMonitor = $this->createMock(QueryMonitorInterface::class);
        [$db, $fixture] = $this->database(static fn () => true, $oldMonitor);
        $store = new Store($db);

        try {
            $store->withOrderLock(42, fn () => $store->transaction(42, static function () use ($db, $fixture) {
                $db->setQuery('UPDATE native_order SET order_status = 1')->execute();
                // Joomla's execute retry enters the monitor again on the replacement handle.
                $fixture->connection = new \stdClass();
                $db->setQuery('UPDATE native_history SET history_order_status = 1')->execute();
            }));
            self::fail('A reconnected driver must reject the second native write.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_order_lock_lost', $error->getMessage());
        }

        self::assertContains('UPDATE native_order SET order_status = 1', $fixture->queries);
        self::assertNotContains('UPDATE native_history SET history_order_status = 1', $fixture->queries);
        self::assertNotContains('COMMIT', $fixture->queries);
        self::assertSame($oldMonitor, $fixture->monitor);
    }

    public function testLostAdvisoryLockOnSameConnectionForcesRollback(): void
    {
        [$db, $fixture] = $this->database(static fn () => true);
        $store = new Store($db);

        try {
            $store->withOrderLock(42, fn () => $store->transaction(42, static function () use ($fixture) {
                $fixture->owned = false;
            }));
            self::fail('An order write must not commit after the server releases the lock.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_order_lock_lost', $error->getMessage());
        }

        self::assertContains('ROLLBACK', $fixture->queries);
        self::assertNotContains('COMMIT', $fixture->queries);
        self::assertNull($fixture->monitor);
    }

    public function testUnknownCreateReusesSnapshotAndCannotOpenAnotherProject(): void
    {
        $pending = [
            'order_id' => '42', 'generation' => '1', 'method_id' => '7',
            'environment' => 'sandbox', 'project_id' => 'prj_A', 'amount' => '12.34000', 'currency' => 'EUR',
            'external_order_id' => 'hikashop_existing', 'invoice_id' => null, 'payment_url' => null, 'status' => 'creating',
        ];
        [$db, $fixture] = $this->database(function (string $sql) use ($pending) {
            if (str_contains($sql, 'paymos_attempt')) {
                return $pending;
            }
            if (str_contains($sql, 'hikashop_order')) {
                return $this->nativeOrder();
            }
            if (str_contains($sql, 'hikashop_payment')) {
                return ['payment_id' => 7, 'payment_type' => 'paymos', 'payment_published' => 1];
            }
            return 'EUR';
        });
        $store = new Store($db);

        $attempt = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        self::assertSame('hikashop_existing', $attempt['external_order_id']);
        self::assertSame('12.34', $attempt['amount']);
        self::assertNull($attempt['invoice_id']);

        try {
            $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_B', '12.34', 'EUR'));
            self::fail('An unresolved request cannot be replaced with a different project.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_attempt_open', $error->getMessage());
        }

        self::assertStringNotContainsString('INSERT INTO', implode("\n", $fixture->queries));
    }

    public function testDefinitiveRejectionRequiresNewSnapshotAndCannotDiscardBoundInvoice(): void
    {
        $order = $this->nativeOrder();
        $order['order_full_price'] = '12.34500';
        $attempt = [
            'order_id' => 42, 'generation' => 1, 'method_id' => 7, 'environment' => 'sandbox',
            'project_id' => 'prj_A', 'amount' => '12.345', 'currency' => 'EUR',
            'external_order_id' => 'hikashop_A', 'invoice_id' => null, 'payment_url' => null, 'status' => 'creating',
        ];
        [$db, $fixture] = $this->database(static function (string $sql) use (&$attempt, &$order) {
            if (str_contains($sql, 'hikashop_order')) {
                return $order;
            }
            if (str_contains($sql, 'hikashop_payment')) {
                return ['payment_id' => 7, 'payment_type' => 'paymos', 'payment_published' => 1];
            }
            if (str_contains($sql, 'hikashop_currency')) {
                return 'EUR';
            }
            if (str_starts_with($sql, 'INSERT INTO')) {
                $attempt['generation'] = 2;
                $attempt['amount'] = '12.34';
                $attempt['external_order_id'] = 'hikashop_B';
                $attempt['status'] = 'creating';
                return true;
            }
            if (str_starts_with($sql, 'UPDATE')) {
                $attempt['status'] = 'rejected';
                return true;
            }

            return $attempt;
        });
        $store = new Store($db);

        $store->withOrderLock(42, fn () => $store->recordAttemptState(42, 1, 'rejected'));
        $unchanged = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.345', 'EUR'));
        self::assertSame(1, $unchanged['generation']);
        self::assertSame('rejected', $unchanged['status']);

        $order['order_full_price'] = '12.34000';
        $corrected = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        self::assertSame(2, $corrected['generation']);
        self::assertSame('creating', $corrected['status']);
        self::assertNotSame($unchanged['external_order_id'], $corrected['external_order_id']);
        self::assertSame(1, substr_count(implode("\n", $fixture->queries), 'INSERT INTO'));

        $attempt['invoice_id'] = 'inv_B';
        $attempt['status'] = 'awaiting_payment';
        $this->expectExceptionMessage('paymos_attempt_rejection_conflict');
        $store->withOrderLock(42, fn () => $store->recordAttemptState(42, 2, 'rejected'));
    }

    public function testPriorInvoiceCannotBeRebound(): void
    {
        [$db, $fixture] = $this->database(static fn () => [
            'order_id' => 42, 'generation' => 1, 'method_id' => 7,
            'environment' => 'sandbox', 'project_id' => 'prj_A', 'amount' => '12.34', 'currency' => 'EUR',
            'external_order_id' => 'hikashop_A', 'invoice_id' => 'inv_A', 'payment_url' => 'https://checkout.paymos.io/inv_A',
            'status' => 'awaiting_payment',
        ]);
        $store = new Store($db);

        try {
            $store->withOrderLock(42, fn () => $store->bindInvoice(42, 1, 'inv_B', 'https://checkout.paymos.io/inv_B', 'awaiting_payment'));
            self::fail('Another invoice cannot overwrite a bound attempt.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_invoice_binding_conflict', $error->getMessage());
        }

        self::assertStringNotContainsString('UPDATE', implode("\n", $fixture->queries));
    }

    public function testInstallAndUninstallTouchOnlyOwnedTables(): void
    {
        $install = (string) file_get_contents(__DIR__ . '/../sql/install.mysql.utf8.sql');
        $uninstall = (string) file_get_contents(__DIR__ . '/../sql/uninstall.mysql.utf8.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS .(#__hikashop_paymos_[a-z_]+)./', $install, $created);
        preg_match_all('/DROP TABLE IF EXISTS .(#__hikashop_paymos_[a-z_]+)./', $uninstall, $dropped);

        self::assertCount(8, $created[1]);
        self::assertEqualsCanonicalizing($created[1], $dropped[1]);
        self::assertSame(count($created[1]), substr_count($install, 'ENGINE=InnoDB'));
        self::assertSame(count($dropped[1]), substr_count($uninstall, 'DROP TABLE'));
        self::assertMatchesRegularExpression('/UNIQUE KEY .request_token. \(.installation_id., .request_token.\)/', $install);
        self::assertMatchesRegularExpression('/UNIQUE KEY .withdrawal. \(.withdrawal_id.\)/', $install);
    }

    private function nativeOrder(): array
    {
        return [
            'order_id' => '42', 'order_payment_id' => '7', 'order_payment_method' => 'paymos',
            'order_status' => 'created', 'order_full_price' => '12.34000', 'order_currency_id' => '2',
        ];
    }

    private function database(callable $answer, ?QueryMonitorInterface $monitor = null): array
    {
        $fixture = (object) [
            'connection' => new \stdClass(),
            'monitor' => $monitor,
            'owned' => true,
            'sql' => '',
            'queries' => [],
        ];
        $db = $this->getMockBuilder(MysqliDriver::class)->disableOriginalConstructor()->onlyMethods([
            'setQuery', 'getMonitor', 'setMonitor', 'getPrefix', 'getConnection', 'quote', 'quoteName',
            'loadResult', 'loadAssoc', 'execute', 'disconnect',
        ])->getMock();
        $db->method('setQuery')->willReturnCallback(static function ($sql) use ($fixture, $db) {
            $fixture->sql = $sql;
            return $db;
        });
        $db->method('getMonitor')->willReturnCallback(static fn () => $fixture->monitor);
        $db->method('setMonitor')->willReturnCallback(static function ($monitor) use ($fixture, $db) {
            $fixture->monitor = $monitor;
            return $db;
        });
        $db->method('getConnection')->willReturnCallback(static fn () => $fixture->connection);
        $db->method('disconnect')->willReturnCallback(static function () use ($fixture): void {
            $fixture->connection = null;
        });
        $db->method('getPrefix')->willReturn('fixture_');
        $db->method('quote')->willReturnCallback(static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'");
        $db->method('quoteName')->willReturnCallback(static fn (string $s): string => chr(96) . $s . chr(96));

        $query = static function () use ($fixture, $answer) {
            $sql = $fixture->sql;
            $fixture->monitor?->startQuery($sql);
            try {
                $fixture->queries[] = $sql;
                return match (true) {
                    str_starts_with($sql, 'SELECT installation_id') => '11111111-2222-4333-8444-555555555555',
                    str_starts_with($sql, 'SELECT IS_USED_LOCK') => $fixture->owned ? 1 : 0,
                    str_starts_with($sql, 'SELECT GET_LOCK'), str_starts_with($sql, 'SELECT RELEASE_LOCK') => 1,
                    in_array($sql, ['START TRANSACTION', 'ROLLBACK', 'COMMIT'], true) => true,
                    default => $answer($sql),
                };
            } finally {
                $fixture->monitor?->stopQuery();
            }
        };
        $db->method('loadResult')->willReturnCallback($query);
        $db->method('loadAssoc')->willReturnCallback($query);
        $db->method('execute')->willReturnCallback($query);

        return [$db, $fixture];
    }
}
