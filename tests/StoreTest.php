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
            if (str_contains($sql, 'hikashop_order_product')) {
                return [];
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
            if (str_contains($sql, 'hikashop_order_product')) {
                return [];
            }
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
        self::assertMatchesRegularExpression('/`products_json`\s+LONGTEXT\s+NULL/', $install);
    }

    public function testDescriptionSelectionSupportsPublishedMinimumSdkApi(): void
    {
        // Load the real Store in a clean PHP process so the local four-argument
        // SDK cannot replace the published minimum's five-required-argument ABI.
        $script = <<<'PHP'
declare(strict_types=1);
namespace Paymos\Plugin {
    final class InvoicePresentation
    {
        public static array $descriptions = [];

        public static function normalizeProduct(
            ?string $title,
            ?string $description,
            ?string $imageUrl,
            $quantity,
            ?string $unitAmount
        ): ?array {
            self::$descriptions[] = $description;
            $item = ['title' => $title, 'quantity' => $quantity, 'unit_amount' => $unitAmount];
            $text = html_entity_decode(strip_tags($description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/\S/u', $text) === 1) {
                $item['description'] = 'normalized description, not the original HTML';
            }
            return $item;
        }

        public static function normalizeProducts(iterable $candidates): ?array
        {
            $products = [];
            foreach ($candidates as $candidate) {
                $products[] = self::normalizeProduct(
                    $candidate['title'],
                    $candidate['description'],
                    null,
                    $candidate['quantity'],
                    $candidate['unit_amount']
                );
            }
            return $products ?: null;
        }
    }
}
namespace {
    define('_JEXEC', 1);
    require $argv[1];
    $store = (new \ReflectionClass(\PaymosHikaShop\Store::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(\PaymosHikaShop\Store::class, 'resolveProductDescription');
    $cases = [
        [105 => ['product_description' => '<p>&nbsp;</p>'], 100 => ['product_description' => '<p>Archival</p><p>Print</p>']],
        [105 => ['product_description' => '<p>Variant &amp; finish</p>'], 100 => ['product_description' => '<p>Unused parent</p>']],
        [105 => ['product_description' => '<p>&nbsp;</p>'], 100 => ['product_description' => " \t\n"]],
    ];
    $observations = [];
    foreach ($cases as $products) {
        \Paymos\Plugin\InvoicePresentation::$descriptions = [];
        $observations[] = [
            'description' => $method->invoke($store, 105, 100, $products),
            'probed' => \Paymos\Plugin\InvoicePresentation::$descriptions,
        ];
    }
    echo json_encode($observations, JSON_THROW_ON_ERROR);
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, dirname(__DIR__) . '/src/Store.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame([
            [
                'description' => '<p>Archival</p><p>Print</p>',
                'probed' => ['<p>&nbsp;</p>', '<p>Archival</p><p>Print</p>'],
            ],
            [
                'description' => '<p>Variant &amp; finish</p>',
                'probed' => ['<p>Variant &amp; finish</p>'],
            ],
            [
                'description' => null,
                'probed' => ['<p>&nbsp;</p>', " \t\n"],
            ],
        ], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testInvoicePresentationCompatibilityMatrixWithoutSharedAutoload(): void
    {
        $storePath = dirname(__DIR__) . '/src/Store.php';
        $sdkCandidates = [
            dirname(__DIR__, 2) . '/php-sdk/src/Plugin/InvoicePresentation.php',
            dirname(__DIR__) . '/vendor/paymos/php-sdk/src/Plugin/InvoicePresentation.php',
        ];
        $currentSdkPath = null;
        foreach ($sdkCandidates as $candidate) {
            if (is_file($candidate)) {
                $currentSdkPath = $candidate;
                break;
            }
        }
        self::assertNotNull($currentSdkPath, 'Expected Paymos PHP SDK InvoicePresentation.php to exist.');

        $runIsolated = static function (string $script, array $args): array {
            $process = proc_open(
                array_merge([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], $args),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errors);
            return json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        };

        // Matrix cell 1 & 2: Current Store.php against current InvoicePresentation (direct file require, zero autoload).
        $newStoreNewSdkScript = <<<'PHP'
declare(strict_types=1);
define('_JEXEC', 1);
require $argv[1];
require $argv[2];
$store = (new \ReflectionClass(\PaymosHikaShop\Store::class))->newInstanceWithoutConstructor();
$resolveDesc = new \ReflectionMethod(\PaymosHikaShop\Store::class, 'resolveProductDescription');
$descFallback = $resolveDesc->invoke($store, 105, 100, [
    105 => ['product_description' => '<p>&nbsp;</p>'],
    100 => ['product_description' => '<p>Archival</p><p>Print</p>'],
]);
$normalized = \Paymos\Plugin\InvoicePresentation::normalizeProducts([
    [
        'title' => '<b>Canvas</b> &amp; Blue',
        'description' => $descFallback,
        'quantity' => 2,
        'unit_amount' => '15.00',
    ],
]);
echo json_encode([
    'description' => $descFallback,
    'normalized' => $normalized,
], JSON_THROW_ON_ERROR);
PHP;
        $newStoreNewSdk = $runIsolated($newStoreNewSdkScript, [$currentSdkPath, $storePath]);
        self::assertSame('<p>Archival</p><p>Print</p>', $newStoreNewSdk['description']);
        self::assertSame([[
            'title' => 'Canvas & Blue',
            'quantity' => 2,
            'unit_amount' => '15.00',
            'description' => "Archival\nPrint",
        ]], $newStoreNewSdk['normalized']);

        // Matrix cell 3 & 4: Legacy 5-arg (eb025e2ae5) & 4-arg (acaf693c33) callers against current InvoicePresentation
        // in an isolated process without shared composer autoload fallback.
        $legacyCallersCurrentSdkScript = <<<'PHP'
declare(strict_types=1);
require $argv[1];
$fiveArgNull = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', '<p>Line 1</p>', null, 2, '15.00');
$fiveArgUrl = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', '<p>Line 1</p>', 'https://cdn.example/old.png', 3, '15.00');
$fiveArgNumericImg = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', null, '99', 4, '15.00');
$fiveArgNullAmount = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', null, 'https://cdn.example/old.png', 2, null);
$fourArg = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', '<p>Line 1</p>', 2, '15.00');
$fourArgBlankDesc = \Paymos\Plugin\InvoicePresentation::normalizeProduct('Canvas', '<p>&nbsp;</p>', 1, '1.00');
$named = null;
if (PHP_VERSION_ID >= 80000) {
    $named = call_user_func_array(
        [\Paymos\Plugin\InvoicePresentation::class, 'normalizeProduct'],
        [
            'title' => 'Canvas',
            'description' => '<p>Named</p>',
            'imageUrl' => 'https://cdn.example/old.png',
            'quantity' => 5,
            'unitAmount' => '15.00',
        ]
    );
}
echo json_encode([
    'fiveArgNull' => $fiveArgNull,
    'fiveArgUrl' => $fiveArgUrl,
    'fiveArgNumericImg' => $fiveArgNumericImg,
    'fiveArgNullAmount' => $fiveArgNullAmount,
    'fourArg' => $fourArg,
    'fourArgBlankDesc' => $fourArgBlankDesc,
    'named' => $named,
], JSON_THROW_ON_ERROR);
PHP;
        $legacyVsCurrent = $runIsolated($legacyCallersCurrentSdkScript, [$currentSdkPath]);
        self::assertSame([
            'title' => 'Canvas',
            'quantity' => 2,
            'unit_amount' => '15.00',
            'description' => 'Line 1',
        ], $legacyVsCurrent['fiveArgNull']);
        self::assertSame([
            'title' => 'Canvas',
            'quantity' => 3,
            'unit_amount' => '15.00',
            'description' => 'Line 1',
        ], $legacyVsCurrent['fiveArgUrl']);
        self::assertSame([
            'title' => 'Canvas',
            'quantity' => 4,
            'unit_amount' => '15.00',
        ], $legacyVsCurrent['fiveArgNumericImg']);
        self::assertNull($legacyVsCurrent['fiveArgNullAmount']);
        self::assertSame([
            'title' => 'Canvas',
            'quantity' => 2,
            'unit_amount' => '15.00',
            'description' => 'Line 1',
        ], $legacyVsCurrent['fourArg']);
        self::assertSame([
            'title' => 'Canvas',
            'quantity' => 1,
            'unit_amount' => '1.00',
        ], $legacyVsCurrent['fourArgBlankDesc']);
        if (PHP_VERSION_ID >= 80000) {
            self::assertSame([
                'title' => 'Canvas',
                'quantity' => 5,
                'unit_amount' => '15.00',
                'description' => 'Named',
            ], $legacyVsCurrent['named']);
        }

        // Matrix cell 5: Current Store.php against acaf693c33 strict 4-arg InvoicePresentation (zero autoload).
        $newStoreFourArgStubScript = <<<'PHP'
declare(strict_types=1);
namespace Paymos\Plugin {
    final class InvoicePresentation
    {
        public static array $descriptions = [];

        public static function normalizeProduct(
            ?string $title,
            ?string $description,
            $quantity,
            ?string $unitAmount
        ): ?array {
            self::$descriptions[] = $description;
            $item = ['title' => $title, 'quantity' => (int) $quantity, 'unit_amount' => $unitAmount];
            $text = html_entity_decode(strip_tags($description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/\S/u', $text) === 1) {
                $item['description'] = trim($text);
            }
            return $item;
        }

        public static function normalizeProducts(iterable $candidates): ?array
        {
            $products = [];
            foreach ($candidates as $candidate) {
                $products[] = self::normalizeProduct(
                    $candidate['title'],
                    $candidate['description'],
                    $candidate['quantity'],
                    $candidate['unit_amount']
                );
            }
            return $products ?: null;
        }
    }
}
namespace {
    define('_JEXEC', 1);
    require $argv[1];
    $store = (new \ReflectionClass(\PaymosHikaShop\Store::class))->newInstanceWithoutConstructor();
    $resolveDesc = new \ReflectionMethod(\PaymosHikaShop\Store::class, 'resolveProductDescription');
    $desc = $resolveDesc->invoke($store, 105, 100, [
        105 => ['product_description' => '<p>&nbsp;</p>'],
        100 => ['product_description' => '<p>Parent fallback</p>'],
    ]);
    echo json_encode([
        'description' => $desc,
        'probed' => \Paymos\Plugin\InvoicePresentation::$descriptions,
    ], JSON_THROW_ON_ERROR);
}
PHP;
        $newStoreFourArg = $runIsolated($newStoreFourArgStubScript, [$storePath]);
        self::assertSame([
            'description' => '<p>Parent fallback</p>',
            'probed' => ['<p>&nbsp;</p>', '<p>Parent fallback</p>'],
        ], $newStoreFourArg);
    }

    public function testBeginAttemptPersistsProductsJsonAndRejectsSnapshotMismatch(): void
    {
        $order = $this->nativeOrder();
        $orderProducts = [
            [
                'order_product_id' => '1',
                'product_id' => '105',
                'order_product_name' => '<b>Canvas</b> &amp; Blue',
                'order_product_quantity' => '2',
                'order_product_price' => '24.50000',
            ],
            [
                'order_product_id' => '2',
                'product_id' => '200',
                'order_product_name' => 'Notebook',
                'order_product_quantity' => '1',
                'order_product_price' => '12.00000',
            ],
        ];
        $products = [
            ['product_id' => '105', 'product_parent_id' => '100', 'product_description' => '<p>&nbsp;</p>'],
            ['product_id' => '100', 'product_parent_id' => '0', 'product_description' => '<p>Archival</p><p>Print</p>'],
            ['product_id' => '200', 'product_parent_id' => '0', 'product_description' => '   '],
        ];
        $attempts = [];
        [$db, $fixture] = $this->database(function (string $sql) use (&$order, &$orderProducts, &$products, &$attempts) {
            if (str_contains($sql, 'hikashop_paymos_attempt')) {
                if (str_starts_with($sql, 'INSERT INTO')) {
                    $gen = count($attempts) + 1;
                    $jsonPos = strrpos($sql, 'VALUES (');
                    $valuesPart = substr($sql, $jsonPos + 8, -1);
                    $lastComma = strrpos($valuesPart, ', ');
                    $rawProducts = substr($valuesPart, $lastComma + 2);
                    $productsJson = $rawProducts === 'NULL'
                        ? null
                        : str_replace("''", "'", substr($rawProducts, 1, -1));
                    $attempts[$gen] = [
                        'order_id' => '42',
                        'generation' => (string) $gen,
                        'method_id' => '7',
                        'environment' => 'sandbox',
                        'project_id' => 'prj_A',
                        'external_order_id' => 'hikashop_' . $gen,
                        'invoice_id' => null,
                        'amount' => '12.34000',
                        'currency' => 'EUR',
                        'payment_url' => null,
                        'status' => 'creating',
                        'review_reason' => null,
                        'products_json' => $productsJson,
                    ];
                    return true;
                }
                if (str_starts_with($sql, 'UPDATE')) {
                    preg_match('/AND generation = (\d+)/', $sql, $m);
                    $attempts[(int) $m[1]]['status'] = 'rejected';
                    return true;
                }
                if (preg_match('/AND generation = (\d+)/', $sql, $m)) {
                    return $attempts[(int) $m[1]] ?? null;
                }
                return $attempts === [] ? null : end($attempts);
            }
            if (str_contains($sql, 'hikashop_order_product')) {
                return $orderProducts;
            }
            if (str_contains($sql, 'hikashop_product')) {
                preg_match('/WHERE product_id IN \(([^)]+)\)/', $sql, $m);
                $ids = array_map('trim', explode(',', $m[1]));
                return array_values(array_filter($products, static fn (array $p): bool => in_array($p['product_id'], $ids, true)));
            }
            if (str_contains($sql, 'hikashop_order')) {
                return $order;
            }
            if (str_contains($sql, 'hikashop_payment')) {
                return ['payment_id' => 7, 'payment_type' => 'paymos', 'payment_published' => 1];
            }
            return 'EUR';
        });
        $store = new Store($db);

        $first = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        $expectedProducts = [
            [
                'title' => 'Canvas & Blue',
                'quantity' => 2,
                'unit_amount' => '24.5',
                'description' => "Archival\nPrint",
            ],
            [
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12',
            ],
        ];
        self::assertSame($expectedProducts, Store::decodeProductsJson($first['products_json']));
        self::assertStringNotContainsString('hikashop_file', implode("\n", $fixture->queries));
        self::assertSame(1, substr_count(implode("\n", $fixture->queries), 'INSERT INTO'));

        $reused = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        self::assertSame($first['products_json'], $reused['products_json']);
        self::assertSame(1, substr_count(implode("\n", $fixture->queries), 'INSERT INTO'));

        $orderProducts[1]['order_product_name'] = 'Notebook v2';
        try {
            $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
            self::fail('A changed product snapshot must not match an open creating attempt.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_attempt_open', $error->getMessage());
        }

        try {
            $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR', null));
            self::fail('Explicit amount-only snapshot must mismatch persisted products_json.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_attempt_open', $error->getMessage());
        }

        foreach (['', '[]', '{invalid', '[{}]'] as $badJson) {
            try {
                $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR', $badJson));
                self::fail('Invalid products_json must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('paymos_attempt_invalid', $error->getMessage());
            }
        }

        $store->withOrderLock(42, fn () => $store->recordAttemptState(42, 1, 'rejected'));
        $second = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        self::assertSame(2, $second['generation']);
        self::assertSame('Notebook v2', Store::decodeProductsJson($second['products_json'])[1]['title']);
    }

    public function testLegacyProductsProjectionKeepsRawSnapshotAndItsExactValues(): void
    {
        $products = [
            [
                'title' => '  Canvas "Pro" \\ €  ',
                'description' => "Original\nDescription 'quoted' \\ €",
                'image_url' => 'https://legacy.example/original.webp',
                'quantity' => 2,
                'unit_amount' => '12345678901234567890.123456789012345678',
            ],
            ['title' => 'Free Support', 'quantity' => 1, 'unit_amount' => '0.00'],
        ];
        $raw = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $legacy = [
            'order_id' => '42', 'generation' => '1', 'method_id' => '7',
            'environment' => 'sandbox', 'project_id' => 'prj_A', 'amount' => '12.34000', 'currency' => 'EUR',
            'external_order_id' => 'hikashop_legacy', 'invoice_id' => null, 'payment_url' => null,
            'status' => 'creating', 'products_json' => $raw,
        ];
        [$db, $fixture] = $this->database(function (string $sql) use ($legacy) {
            if (str_contains($sql, 'paymos_attempt')) {
                return $legacy;
            }
            if (str_contains($sql, 'hikashop_order_product') || str_contains($sql, 'hikashop_product')
                || str_contains($sql, 'hikashop_file')) {
                throw new \LogicException('Legacy recovery must not read native products or images.');
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
        $reused = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR', $raw));
        self::assertSame($raw, $reused['products_json']);
        self::assertSame('hikashop_legacy', $reused['external_order_id']);
        self::assertSame(1, $reused['generation']);
        $expected = $products;
        unset($expected[0]['image_url']);
        self::assertSame($expected, Store::decodeProductsJson($reused['products_json']));
        self::assertSame($raw, $store->currentAttempt(42)['products_json']);

        // Equality still uses the original bytes, including the historical field.
        $products[0]['image_url'] = 'https://legacy.example/changed.webp';
        $changedRaw = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR', $changedRaw));
            self::fail('Changing only the frozen historical key must still fail the raw snapshot comparison.');
        } catch (\RuntimeException $error) {
            self::assertSame('paymos_attempt_open', $error->getMessage());
        }
        self::assertStringNotContainsString('INSERT INTO', implode("\n", $fixture->queries));
        self::assertStringNotContainsString('UPDATE ', implode("\n", $fixture->queries));

        // Removing the obsolete key does not relax the existing required-field checks.
        foreach (['title' => '', 'quantity' => '2', 'unit_amount' => 2] as $field => $invalid) {
            $invalidProducts = $products;
            $invalidProducts[0][$field] = $invalid;
            try {
                Store::decodeProductsJson(json_encode($invalidProducts, JSON_THROW_ON_ERROR));
                self::fail('Invalid frozen ' . $field . ' must remain rejected.');
            } catch (\RuntimeException $error) {
                self::assertSame('paymos_attempt_products_invalid', $error->getMessage());
            }
        }
    }

    public function testLegacyNullProductsRowCompatibility(): void
    {
        $legacy = [
            'order_id' => '42',
            'generation' => '1',
            'method_id' => '7',
            'environment' => 'sandbox',
            'project_id' => 'prj_A',
            'amount' => '12.34000',
            'currency' => 'EUR',
            'external_order_id' => 'hikashop_legacy',
            'invoice_id' => null,
            'payment_url' => null,
            'status' => 'creating',
        ];
        $orderProducts = [];
        [$db] = $this->database(function (string $sql) use ($legacy, &$orderProducts) {
            if (str_contains($sql, 'paymos_attempt')) {
                return $legacy;
            }
            if (str_contains($sql, 'hikashop_order_product')) {
                return $orderProducts;
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

        $loaded = $store->currentAttempt(42);
        self::assertArrayHasKey('products_json', $loaded);
        self::assertNull($loaded['products_json']);

        $reused = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
        self::assertSame('hikashop_legacy', $reused['external_order_id']);
        self::assertNull($reused['products_json']);

        $orderProducts = [[
            'order_product_id' => '1',
            'product_id' => '0',
            'order_product_name' => 'Manual Item',
            'order_product_quantity' => '1',
            'order_product_price' => '12.34000',
        ]];
        $reusedOnRetry = $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR', null));
        self::assertNull($reusedOnRetry['products_json']);

        $this->expectExceptionMessage('paymos_attempt_open');
        $store->withOrderLock(42, fn () => $store->beginAttempt(42, 7, 'sandbox', 'prj_A', '12.34', 'EUR'));
    }

    public function testPostflightMigrationIsIdempotentViaInformationSchema(): void
    {
        require_once __DIR__ . '/../script.php';
        $columnExists = 0;
        [$db, $fixture] = $this->database(static function (string $sql) use (&$columnExists) {
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return $columnExists;
            }
            if (str_starts_with($sql, 'ALTER TABLE')) {
                $columnExists = 1;
                return true;
            }
            return true;
        });

        self::assertTrue(\PlgHikashoppaymentPaymosInstallerScript::ensureAttemptProductsColumn($db));
        self::assertSame(1, $columnExists);
        self::assertTrue(\PlgHikashoppaymentPaymosInstallerScript::ensureAttemptProductsColumn($db));

        $queries = $fixture->queries;
        self::assertCount(3, $queries);
        self::assertStringContainsString("information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fixture_hikashop_paymos_attempt' AND COLUMN_NAME = 'products_json'", $queries[0]);
        self::assertSame('ALTER TABLE `#__hikashop_paymos_attempt` ADD COLUMN `products_json` LONGTEXT NULL', $queries[1]);
        self::assertStringContainsString('information_schema.COLUMNS', $queries[2]);
    }

    public function testUpdateAddsProductsColumnInPreflightWhereJoomlaCanStillAbort(): void
    {
        // Joomla copies the new files before postflight and ignores its false result. Only preflight runs
        // before the copy, so an update that cannot add products_json must stop there, on the old version.
        $script = <<<'PHP'
declare(strict_types=1);
namespace Joomla\Database {
    interface DatabaseInterface
    {
    }
}
namespace Joomla\CMS {
    final class Factory
    {
        public static object $database;
        public static array $messages = [];

        public static function getContainer(): object
        {
            return new class {
                public function get(string $id): object
                {
                    return Factory::$database;
                }
            };
        }

        public static function getApplication(): object
        {
            return new class {
                public function enqueueMessage(string $message, string $type): void
                {
                    Factory::$messages[] = $message;
                }
            };
        }
    }
}
namespace Joomla\CMS\Component {
    final class ComponentHelper
    {
        public static function isEnabled(string $option): bool
        {
            return $option === 'com_hikashop';
        }
    }
}
namespace Joomla\CMS\Installer {
    final class Installer
    {
        public static function parseXMLInstallFile(string $path): array
        {
            return ['version' => '6.8.1'];
        }
    }
}
namespace {
    define('_JEXEC', 1);
    define('JVERSION', '5.4.10');
    define('JPATH_ADMINISTRATOR', '/fixture/administrator');
    require $argv[1];

    final class FixtureDatabase implements \Joomla\Database\DatabaseInterface
    {
        public array $queries = [];
        public bool $column = false;

        public function __construct(private readonly bool $alterAllowed)
        {
        }

        public function getPrefix(): string { return 'fixture_'; }
        public function getServerType(): string { return 'mysql'; }
        public function quote(string $text): string { return "'" . $text . "'"; }
        public function quoteName(string $name): string { return '`' . $name . '`'; }

        public function setQuery(string $sql): self
        {
            $this->queries[] = $sql;
            return $this;
        }

        public function loadAssocList(string $key, string $column): array
        {
            $tables = ['order', 'history', 'product', 'order_product', 'discount', 'user'];
            return array_fill_keys(array_map(static fn (string $table): string => 'fixture_hikashop_' . $table, $tables), 'InnoDB');
        }

        public function loadResult(): int
        {
            return $this->column ? 1 : 0;
        }

        public function execute(): bool
        {
            if (!$this->alterAllowed) {
                throw new \RuntimeException('ALTER command denied to user');
            }
            $this->column = true;
            return true;
        }
    }

    $observations = [];
    foreach ([['install', true], ['update', false], ['update', true]] as [$route, $alterAllowed]) {
        \Joomla\CMS\Factory::$database = $db = new FixtureDatabase($alterAllowed);
        \Joomla\CMS\Factory::$messages = [];
        $observations[] = [
            'accepted' => (new \PlgHikashoppaymentPaymosInstallerScript())->preflight($route, null),
            'altered' => array_values(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE'))),
            'column' => $db->column,
            'messages' => \Joomla\CMS\Factory::$messages,
        ];
    }
    echo json_encode($observations, JSON_THROW_ON_ERROR);
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, dirname(__DIR__) . '/script.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);

        $alter = 'ALTER TABLE `#__hikashop_paymos_attempt` ADD COLUMN `products_json` LONGTEXT NULL';
        self::assertSame([
            // A fresh install creates the table from install SQL after preflight.
            ['accepted' => true, 'altered' => [], 'column' => false, 'messages' => []],
            ['accepted' => false, 'altered' => [$alter], 'column' => false,
                'messages' => ['Paymos for HikaShop could not update the payment attempt table.']],
            ['accepted' => true, 'altered' => [$alter], 'column' => true, 'messages' => []],
        ], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
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
            'loadResult', 'loadAssoc', 'loadAssocList', 'execute', 'disconnect',
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
        $db->method('loadAssocList')->willReturnCallback($query);
        $db->method('execute')->willReturnCallback($query);

        return [$db, $fixture];
    }
}
