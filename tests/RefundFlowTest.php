<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop\Tests;

use DateTimeImmutable;
use Joomla\Database\Mysqli\MysqliDriver;
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Webhook\WebhookEvent;
use PaymosHikaShop\ConnectAdmin;
use PaymosHikaShop\RefundAdapter;
use PaymosHikaShop\RefundStore;
use PaymosHikaShop\Store;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

final class RefundFlowTest extends TestCase
{
    private const TOKEN = 'refund-token-fixture-0001';
    private const OTHER_TOKEN = 'refund-token-fixture-0002';

    public function testFullRefundNeedsVerifiedCompletionAndProjectsExactlyOnce(): void
    {
        [$adapter, $fixture] = $this->scenario();
        self::assertTrue($adapter->panel(17, 42)['allow_full']);
        self::assertSame([], $fixture->requests);

        $pending = $this->submit($adapter, 'full');
        self::assertSame('pending', $pending['status']);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame(0, $fixture->nativeWrites);
        self::assertCount(1, $fixture->histories);
        self::assertSame('wd_fixture', $fixture->payload()['withdrawal_id']);
        self::assertSame('sandbox', $fixture->payload()['environment']);
        self::assertSame('1234', $fixture->payload()['full_draft']['amount_minor']);
        self::assertSame(['pk_test_fixture'], array_values(array_unique(array_column($fixture->requests, 'key'))));

        $fixture->refundStatus = 'completed';
        $event = $fixture->event();
        $adapter->callback($event, 'sandbox');
        $adapter->callback($event, 'sandbox');
        $completed = $this->submit($adapter, 'full');
        self::assertSame(['status' => 'completed', 'operation_id' => $pending['operation_id']], $completed);
        self::assertSame('refunded', $fixture->orders[42]['order_status']);
        self::assertSame(1, $fixture->nativeWrites);
        self::assertSame(4, $fixture->stock);
        self::assertCount(1, $fixture->posts);
        self::assertCount(1, $fixture->projections);
        self::assertCount(2, $fixture->histories);
        self::assertSame('12.34 EUR', $fixture->histories[2]['history_amount']);
        self::assertSame(0, $fixture->histories[2]['history_notified']);
        self::assertSame('applied', $fixture->payload()['projection_state']);
        $this->reject('refund_payment_unresolved_or_refunded', fn () => $this->submit($adapter, 'full', self::OTHER_TOKEN));
    }

    public function testOverpaymentOnlyWritesCryptoHistoryAndManualReconcileRecoversMissedCallback(): void
    {
        [$adapter, $fixture] = $this->scenario('paid_over');
        $fixture->orders[42]['order_status'] = 'shipped';
        $fixture->mode = 'sandbox';
        self::assertTrue($adapter->panel(17, 42)['allow_overpayment']);
        $pending = $this->submit($adapter, 'overpayment');
        self::assertSame('pending', $pending['status']);
        self::assertNull($fixture->payload()['full_draft']);

        $fixture->mode = 'live';
        $fixture->setRefundsEnabled(false);
        $fixture->refundStatus = 'completed';
        $checked = $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $pending['operation_id']]);
        self::assertSame('completed', $checked['status']);
        self::assertSame('shipped', $fixture->orders[42]['order_status']);
        self::assertSame(3, $fixture->stock);
        self::assertSame(0, $fixture->nativeWrites);
        self::assertSame('1.1 USDT/TRON', $fixture->histories[2]['history_amount']);
        self::assertSame('shipped', $fixture->histories[2]['history_new_status']);
        self::assertSame('USDT/TRON', $fixture->payload()['projection']['cryptoAsset']);
        self::assertSame(['pk_test_fixture'], array_values(array_unique(array_column($fixture->requests, 'key'))));
        self::assertCount(1, $fixture->posts);
    }

    #[TestWith(['paid', 'paid_over'])]
    #[TestWith(['paid_over', 'paid'])]
    public function testOverpaymentRequiresBothTheLocalAttemptAndTheApiToBePaidOver(string $localStatus, string $apiStatus): void
    {
        [$adapter, $fixture] = $this->scenario($localStatus);
        $fixture->invoiceStatus = $apiStatus;

        $this->reject('refund_server_action_unavailable', fn () => $this->submit($adapter, 'overpayment'));
        self::assertSame(['GET'], array_column($fixture->requests, 'method'));
        self::assertSame([], $fixture->posts);
        self::assertSame([], $fixture->intents);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
    }

    public function testRefundPostDecidesEligibilityWithoutChangingTheNativeOrderOnDenial(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->invoiceStatus = 'paid_over';
        $fixture->refundWindowExpired = true;

        $failed = $this->submit($adapter, 'full');
        self::assertSame('failed', $failed['status']);
        self::assertSame('failed', $fixture->payload()['cash_state']);
        self::assertSame('none', $fixture->payload()['projection_state']);
        self::assertSame('refund_window_expired', $fixture->payload()['review_reason']);
        self::assertNull($fixture->payload()['withdrawal_id']);

        self::assertSame($failed, $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $failed['operation_id']]));
        self::assertSame($failed, $this->submit($adapter, 'full'));
        self::assertSame(['GET', 'GET', 'POST'], array_column($fixture->requests, 'method'));
        self::assertSame(['pk_test_fixture'], array_values(array_unique(array_column($fixture->requests, 'key'))));
        self::assertCount(1, $fixture->posts);
        self::assertSame([], $fixture->withdrawals);
        self::assertSame([], $fixture->projections);
        self::assertSame(0, $fixture->nativeWrites);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame(3, $fixture->stock);
        self::assertCount(1, $fixture->histories);
    }

    public function testDeliveredOrderCanRefundOnlyWithAnExactPaidEffect(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->orders[42]['order_status'] = 'delivered';
        $paidHistory = $fixture->histories;
        $fixture->histories = [];
        self::assertFalse($adapter->panel(17, 42)['allow_full']);
        $this->reject('refund_payment_projection_unverified', fn () => $this->submit($adapter, 'full'));
        self::assertSame([], $fixture->requests);

        $fixture->histories = $paidHistory;
        self::assertTrue($adapter->panel(17, 42)['allow_full']);
        $this->submit($adapter, 'full');
        $fixture->refundStatus = 'completed';
        $adapter->callback($fixture->event(), 'sandbox');
        self::assertSame('refunded', $fixture->orders[42]['order_status']);
        self::assertCount(1, $fixture->posts);
    }

    public function testAnExternalRefundMarksThePaidOrderForReview(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $adapter->callback($fixture->event([
            'withdrawal_id' => 'wd_external', 'external_order_id' => 'an-outside-refund',
        ]), 'sandbox');

        $panel = $adapter->panel(17, 42);
        self::assertSame('review', $panel['status']);
        self::assertFalse($panel['allow_full']);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame([], $fixture->requests);
        self::assertSame([], $fixture->intents);
    }

    public function testLostPostReplaysOnlyTheOriginalWithdrawalAndRejectsASecondToken(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->loseNextPost = true;

        $pending = $this->submit($adapter, 'full');
        self::assertSame('pending', $pending['status']);
        self::assertSame('unknown', $fixture->payload()['cash_state']);
        self::assertNull($fixture->payload()['withdrawal_id']);
        self::assertCount(1, $fixture->withdrawals);
        $this->reject('refund_payment_unresolved_or_refunded', fn () => $this->submit($adapter, 'full', self::OTHER_TOKEN));

        $fixture->refundStatus = 'completed';
        $checked = $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $pending['operation_id']]);
        self::assertSame('completed', $checked['status']);
        self::assertCount(2, $fixture->posts);
        self::assertSame($fixture->posts[0], $fixture->posts[1]);
        self::assertCount(1, $fixture->withdrawals);
        self::assertSame(1, $fixture->nativeWrites);
    }

    public function testAgedUnknownRefundRemainsRecoverableWithTheOriginalKey(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->loseNextPost = true;
        $started = $this->submit($adapter, 'full');
        $fixture->now = $fixture->now->modify('+2 days');
        $fixture->loseNextPost = true;

        $review = $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $started['operation_id']]);
        self::assertSame('review', $review['status']);
        self::assertSame('refund_unresolved_24h', $fixture->payload()['review_reason']);
        self::assertSame('unknown', $fixture->payload()['cash_state']);
        self::assertSame(0, $fixture->nativeWrites);

        $fixture->refundStatus = 'completed';
        $adapter->callback($fixture->event(), 'sandbox');
        self::assertSame('refunded', $fixture->orders[42]['order_status']);
        self::assertSame(1, $fixture->nativeWrites);
        self::assertCount(3, $fixture->posts);
        self::assertCount(1, $fixture->withdrawals);
        self::assertNull($fixture->payload()['review_reason']);
    }

    public function testUnknownPostCannotAdoptAWithdrawalIdFromTheEvent(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->loseNextPost = true;
        $this->submit($adapter, 'full');
        $fixture->refundStatus = 'completed';
        $wrongId = $fixture->event(['withdrawal_id' => 'wd_wrong']);

        $this->reject('refund_callback_scope_conflict', fn () => $adapter->callback($wrongId, 'sandbox'));
        self::assertSame('wd_fixture', $fixture->payload()['withdrawal_id']);
        self::assertSame('completed', $fixture->payload()['cash_state']);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame(0, $fixture->nativeWrites);

        $adapter->callback($fixture->event(), 'sandbox');
        self::assertSame('refunded', $fixture->orders[42]['order_status']);
        self::assertSame(1, $fixture->nativeWrites);
        self::assertCount(1, $fixture->withdrawals);
    }

    public function testCallbackReadTimeoutCannotAcknowledgeAnOldPendingSnapshot(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $this->submit($adapter, 'full');
        $fixture->refundStatus = 'completed';
        $fixture->loseNextGet = true;

        $event = $fixture->event();
        $this->reject('refund_callback_unverified', fn () => $adapter->callback($event, 'sandbox'));
        self::assertSame('created', $fixture->payload()['observation']['status']);
        self::assertSame(0, $fixture->nativeWrites);

        $adapter->callback($event, 'sandbox');
        self::assertSame(1, $fixture->nativeWrites);
    }

    public function testOrderTokenProjectAndDestinationAreCheckedBeforeRefundPost(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $this->reject('refund_mode_and_token_only', fn () => $adapter->adminAction('refund', 17, 42,
            ['mode' => 'full', 'request_token' => self::TOKEN, 'destination_address' => 'untrusted-wallet']));
        $this->reject('refund_mapping_conflict', fn () => $adapter->adminAction('refund', 18, 42,
            ['mode' => 'full', 'request_token' => self::TOKEN]));
        self::assertSame([], $fixture->requests);

        $fixture->invoiceProject = 'prj_other';
        $this->reject('refund_invoice_snapshot_conflict', fn () => $this->submit($adapter, 'full'));
        self::assertSame([], $fixture->intents);
        self::assertSame([], $fixture->posts);

        $fixture->invoiceProject = 'prj_fixture';
        $pending = $this->submit($adapter, 'full');
        $this->reject('refund_mapping_conflict', fn () => $adapter->adminAction('reconcile', 17, 43,
            ['operation_id' => $pending['operation_id']]));
        $this->reject('refund_mapping_conflict', fn () => $adapter->adminAction('refund', 17, 43,
            ['mode' => 'full', 'request_token' => self::TOKEN]));
        $before = count($fixture->requests);
        $this->reject('refund_callback_scope_conflict', fn () => $adapter->callback(
            $fixture->event(['project_id' => 'prj_other']), 'sandbox'));
        $this->reject('refund_callback_scope_conflict', fn () => $adapter->callback(
            $fixture->event(['is_test' => false]), 'live'));
        self::assertCount($before, $fixture->requests);
        self::assertCount(1, $fixture->posts);
        self::assertSame(0, $fixture->nativeWrites);
    }

    public function testProjectionFailureRollsBackNativeStatusStockAndHistoryBeforeRecovery(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $pending = $this->submit($adapter, 'full');
        $fixture->refundStatus = 'completed';
        $fixture->failNativeWrite = true;
        $adapter->callback($fixture->event(), 'sandbox');

        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame(3, $fixture->stock);
        self::assertSame(0, $fixture->nativeWrites);
        self::assertCount(1, $fixture->histories);
        self::assertSame([], $fixture->projections);
        self::assertSame('completed', $fixture->payload()['observation']['status']);
        self::assertSame('native_projection_unknown', $fixture->payload()['review_reason']);

        $checked = $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $pending['operation_id']]);
        self::assertSame('completed', $checked['status']);
        self::assertSame(1, $fixture->nativeWrites);
        self::assertSame(4, $fixture->stock);
        self::assertCount(2, $fixture->histories);
        self::assertCount(1, $fixture->posts);
    }

    public function testOrphanNativeMarkerTriggersReviewAndNeverProjectsAgain(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $pending = $this->submit($adapter, 'full');
        $marker = 'paymos:refund:' . $pending['operation_id'] . ':full';
        $fixture->histories[2] = array_replace($fixture->histories[1], ['history_id' => 2, 'history_data' => $marker]);
        $fixture->refundStatus = 'completed';

        $adapter->callback($fixture->event(), 'sandbox');
        self::assertSame('review', $adapter->panel(17, 42)['status']);
        self::assertSame('refund_partial_native_write', $fixture->payload()['review_reason']);
        self::assertSame('confirmed', $fixture->orders[42]['order_status']);
        self::assertSame(0, $fixture->nativeWrites);
        self::assertCount(2, $fixture->histories);
        self::assertCount(1, $fixture->posts);
    }

    public function testComposerManifestRequiresPaymosPhpSdkOneTen(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('^1.11.0', $composer['require']['paymos/php-sdk'] ?? null);
        self::assertSame('refund_preflight_retry', NativeRefundProjectorInterface::PREFLIGHT_RETRY);
    }

    public function testDisabledRefundsSnapshotAllowsPanelPreflightAndSubmitWhenContractValid(): void
    {
        [$adapter, $fixture] = $this->scenario('paid_over');
        $fixture->setCredentials(false, 1);

        $panel = $adapter->panel(17, 42);
        self::assertTrue($panel['allow_full']);
        self::assertTrue($panel['allow_overpayment']);

        $pending = $this->submit($adapter, 'overpayment');
        self::assertSame('pending', $pending['status']);
        self::assertCount(1, $fixture->posts);

        [$adapterMissingContract, $fixtureMissingContract] = $this->scenario();
        $fixtureMissingContract->setCredentials(true, null);
        self::assertFalse($adapterMissingContract->panel(17, 42)['allow_full']);
        $this->reject('refund_reconnect_required', fn () => $this->submit($adapterMissingContract, 'full'));
        self::assertSame([], $fixtureMissingContract->requests);
    }

    public function testPreflightReturnsRetryOnTransientInvoiceErrorsAndMapsPreCreateToUnavailable(): void
    {
        [$adapter, $fixture] = $this->scenario();
        $fixture->invoiceResponse = new HttpResponse(429, '{"code":"rate_limited"}', ['Content-Type' => 'application/problem+json']);
        $this->reject('refund_preflight_unavailable', fn () => $this->submit($adapter, 'full', self::TOKEN));
        self::assertSame([], $fixture->intents);

        $fixture->invoiceResponse = new HttpResponse(502, '{"code":"bad_gateway"}', ['Content-Type' => 'application/problem+json']);
        $this->reject('refund_preflight_unavailable', fn () => $this->submit($adapter, 'full', self::TOKEN));
        self::assertSame([], $fixture->intents);

        $fixture->invoiceResponse = null;
        $fixture->loseNextInvoiceGet = true;
        $this->reject('refund_preflight_unavailable', fn () => $this->submit($adapter, 'full', self::TOKEN));
        self::assertSame([], $fixture->intents);

        $fixture->invoiceResponse = new HttpResponse(404, json_encode([
            'type' => 'https://paymos.io/docs/errors/codes#invoice_not_found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Invoice not found.',
            'code' => 'invoice_not_found',
        ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/problem+json']);
        $this->reject('invoice_not_found', fn () => $this->submit($adapter, 'full', self::TOKEN));
        self::assertSame([], $fixture->intents);

        [$coordAdapter, $coordFixture, $refunds, $connect, $clientFactory, $store] = $this->scenarioWithInternals('paid_over');
        $operationId = RefundStore::operationId();
        $coordFixture->now = new DateTimeImmutable('2026-11-07T14:00:00.000000+00:00');
        $intent = new RefundIntent([
            'operation_id' => $operationId,
            'installation_id' => $store->installationId(),
            'request_token' => self::TOKEN,
            'platform' => 'hikashop',
            'native_payment_key' => 'hikashop:42',
            'method_id' => 17,
            'native_snapshot' => ['amount_decimal' => '12.34', 'currency' => 'EUR', 'order_status' => 'confirmed'],
            'invoice_id' => 'inv_fixture',
            'project_id' => 'prj_fixture',
            'environment' => 'sandbox',
            'mode' => RefundStates::OVERPAYMENT,
            'full_draft' => null,
        ]);

        $refunds->withPaymentLock('hikashop:42', function () use ($coordAdapter, $coordFixture, $refunds, $connect, $clientFactory, $intent): void {
            $saved = $refunds->createIntent($intent);
            $coordFixture->invoiceResponse = new HttpResponse(404, json_encode([
                'type' => 'https://paymos.io/docs/errors/codes#invoice_not_found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'Invoice not found.',
                'code' => 'invoice_not_found',
            ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/problem+json']);
            self::assertSame('invoice_not_found', $coordAdapter->preflight($saved));

            $coordFixture->invoiceResponse = new HttpResponse(429, '{"code":"rate_limited"}', []);
            self::assertSame(NativeRefundProjectorInterface::PREFLIGHT_RETRY, $coordAdapter->preflight($saved));

            $coordFixture->invoiceResponse = new HttpResponse(503, '{"code":"unavailable"}', []);
            self::assertSame(NativeRefundProjectorInterface::PREFLIGHT_RETRY, $coordAdapter->preflight($saved));

            $coordFixture->invoiceResponse = null;
            $coordFixture->loseNextInvoiceGet = true;
            self::assertSame(NativeRefundProjectorInterface::PREFLIGHT_RETRY, $coordAdapter->preflight($saved));

            $coordFixture->invoiceResponse = new HttpResponse(429, '{"code":"rate_limited"}', []);
            $keys = $connect->credentials(17)['sandbox'];
            $coordinator = new RefundCoordinator($clientFactory($keys), $refunds, $coordAdapter, static fn (): DateTimeImmutable => $coordFixture->now);
            $coordinator->submit($saved->operationId());

            $retryPending = $refunds->getIntent($saved->operationId());
            self::assertSame(RefundStates::NOT_SUBMITTED, $retryPending->cashState());
            self::assertSame(NativeRefundProjectorInterface::PREFLIGHT_RETRY, $retryPending->toArray()['review_reason']);
            self::assertSame([], $coordFixture->posts);

            $coordFixture->invoiceResponse = null;
            $coordinator->submit($saved->operationId());
            $submitted = $refunds->getIntent($saved->operationId());
            self::assertSame(RefundStates::CREATED, $submitted->cashState());
            self::assertCount(1, $coordFixture->posts);
        });
    }

    public function testMissingRefundSummaryAllowsRefundsAndSuppressesCompletedOverpayment(): void
    {
        [$adapter, $fixture] = $this->scenario('paid_over');
        $panel = $adapter->panel(17, 42);
        self::assertTrue($panel['allow_overpayment']);
        self::assertTrue($panel['allow_full']);

        $pending = $this->submit($adapter, 'overpayment');
        $fixture->refundStatus = 'completed';
        $completed = $adapter->adminAction('reconcile', 17, 42, ['operation_id' => $pending['operation_id']]);
        self::assertSame('completed', $completed['status']);

        $afterPanel = $adapter->panel(17, 42);
        self::assertFalse($afterPanel['allow_overpayment']);
        self::assertTrue($afterPanel['allow_full']);

        $this->reject('refund_server_action_unavailable', fn () => $this->submit($adapter, 'overpayment', self::OTHER_TOKEN));
        self::assertCount(1, $fixture->posts);

        $fixture->refundSummary = ['can_refund_full' => false, 'can_refund_overpayment' => false];
        $this->reject('refund_server_action_unavailable', fn () => $this->submit($adapter, 'full', self::OTHER_TOKEN));
        self::assertCount(1, $fixture->posts);

        $fixture->refundSummary = null;
        $fixture->refundStatus = 'created';
        $fullPending = $this->submit($adapter, 'full', self::OTHER_TOKEN);
        self::assertSame('pending', $fullPending['status']);
        self::assertCount(2, $fixture->posts);
    }

    private function scenario(string $invoiceStatus = 'paid'): array
    {
        [$adapter, $fixture] = $this->scenarioWithInternals($invoiceStatus);
        return [$adapter, $fixture];
    }

    private function scenarioWithInternals(string $invoiceStatus = 'paid'): array
    {
        $fixture = new RefundFlowFixture($invoiceStatus);
        $db = $this->getMockBuilder(MysqliDriver::class)->disableOriginalConstructor()->onlyMethods([
            'setQuery', 'getMonitor', 'setMonitor', 'getPrefix', 'getConnection', 'quote', 'quoteName',
            'loadResult', 'loadAssoc', 'loadAssocList', 'execute', 'getAffectedRows',
        ])->getMock();
        $db->method('setQuery')->willReturnCallback(static function ($sql) use ($db, $fixture) {
            $fixture->sql = $sql;
            return $db;
        });
        $db->method('getMonitor')->willReturnCallback(static fn () => $fixture->monitor);
        $db->method('setMonitor')->willReturnCallback(static function ($monitor) use ($db, $fixture) {
            $fixture->monitor = $monitor;
            return $db;
        });
        $db->method('getPrefix')->willReturn('fixture_');
        $db->method('getConnection')->willReturn($fixture->connection);
        $db->method('quote')->willReturnCallback(static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'");
        $db->method('quoteName')->willReturnCallback(static fn (string $s): string => chr(96) . $s . chr(96));
        foreach (['loadResult', 'loadAssoc', 'loadAssocList', 'execute'] as $method) {
            $db->method($method)->willReturnCallback($fixture->query(...));
        }
        $db->method('getAffectedRows')->willReturnCallback(static fn () => $fixture->affected);

        $store = new Store($db);
        $connect = new ConnectAdmin($store, RefundFlowFixture::SITE, RefundFlowFixture::SECRET);
        $clock = static fn (): DateTimeImmutable => $fixture->now;
        $refunds = new RefundStore($store, $clock);
        $clientFactory = static fn (array $keys): Client => new Client(new ClientConfig($keys['api_key'], $keys['api_secret']), $fixture);
        $adapter = new RefundAdapter($store, $connect, $fixture->project(...), $clientFactory, $clock);

        return [$adapter, $fixture, $refunds, $connect, $clientFactory, $store];
    }

    private function submit(RefundAdapter $adapter, string $mode, string $token = self::TOKEN): array
    {
        return $adapter->adminAction('refund', 17, 42, ['mode' => $mode, 'request_token' => $token]);
    }

    private function reject(string $message, callable $action): void
    {
        try {
            $action();
        } catch (\RuntimeException | \InvalidArgumentException $failure) {
            self::assertSame($message, $failure->getMessage());
            return;
        }

        self::fail('Expected refund operation to refuse: ' . $message);
    }
}

/** Transactional DB and mock API seam. The production Store, SDK and adapter execute together. */
final class RefundFlowFixture implements TransportInterface
{
    public const SITE = 'https://shop.example/shop';
    public const SECRET = 'refund-fixture-secret';
    private const INSTALLATION = '11111111-2222-4333-8444-555555555555';
    private const INVOICE = 'inv_fixture';
    private const INVOICE_ORDER = 'hikashop_fixture';
    private const RECIPIENT = 'TRON-fixture-originating-sender';

    public readonly object $connection;
    public DateTimeImmutable $now;
    public mixed $monitor = null;
    public string $sql = '';
    public int $affected = 0;
    public array $orders;
    public array $intents = [];
    public array $histories = [];
    public array $projections = [];
    public array $requests = [];
    public array $posts = [];
    public array $withdrawals = [];
    public string $invoiceProject = 'prj_fixture';
    public string $invoiceStatus;
    public string $refundStatus = 'created';
    public string $mode = 'live';
    public int $stock = 3;
    public int $nativeWrites = 0;
    public bool $loseNextPost = false;
    public bool $loseNextGet = false;
    public bool $loseNextInvoiceGet = false;
    public bool $failNativeWrite = false;
    public bool $refundWindowExpired = false;
    public ?HttpResponse $invoiceResponse = null;
    public ?array $refundSummary = null;

    private ?array $transaction = null;
    private string $credentials;
    private array $attempt;
    private string $paymentMarker;

    public function __construct(string $invoiceStatus)
    {
        $this->invoiceStatus = $invoiceStatus;
        $this->connection = new \stdClass();
        $this->now = new DateTimeImmutable('2026-11-07T14:00:00.000000+00:00');
        $order = ['order_payment_id' => '17', 'order_payment_method' => 'paymos', 'order_status' => 'confirmed',
            'order_full_price' => '12.34000', 'order_currency_id' => 1];
        $this->orders = [42 => ['order_id' => 42] + $order, 43 => ['order_id' => 43] + $order];
        $this->attempt = [
            'order_id' => 42, 'generation' => 1, 'method_id' => 17, 'environment' => 'sandbox', 'project_id' => 'prj_fixture',
            'external_order_id' => self::INVOICE_ORDER, 'invoice_id' => self::INVOICE,
            'status' => $invoiceStatus, 'review_reason' => null, 'amount' => '12.34000', 'currency' => 'EUR',
            'payment_url' => 'https://checkout.paymos.io/invoice/' . self::INVOICE,
        ];
        $this->paymentMarker = 'paymos:paid:' . hash('sha256', self::INVOICE);
        $this->history(42, 'confirmed', ['notified' => 0, 'amount' => '', 'data' => $this->paymentMarker, 'type' => 'payment']);
        $this->setRefundsEnabled(true);
    }

    public function setRefundsEnabled(bool $enabled): void
    {
        $this->setCredentials($enabled, $enabled ? 1 : null);
    }

    public function setCredentials(bool $refundsEnabled, ?int $contractVersion = 1, array $capabilities = ['invoice_refunds_v1']): void
    {
        $environments = [];
        foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
            $environments[$environment] = [
                'api_key' => "pk_{$prefix}_fixture", 'api_secret' => "sk_{$prefix}_fixture", 'project_id' => 'prj_fixture',
                'webhook_secret' => "whsec_{$prefix}_fixture", 'capabilities' => $capabilities,
                'refund_contract_version' => $contractVersion, 'refunds_enabled' => $refundsEnabled,
            ];
        }
        $this->credentials = AesGcmEnvelope::seal(['plugin' => 'hikashop', 'source_url' => self::SITE, 'environments' => $environments],
            self::SECRET . "\n" . self::INSTALLATION, 'paymos-hikashop-v1:17:credentials');
    }

    public function payload(): array
    {
        $intent = end($this->intents);
        return json_decode($intent['payload'], true, 64, JSON_THROW_ON_ERROR);
    }

    public function event(array $overrides = []): WebhookEvent
    {
        $request = $this->posts[0] ?? ['external_order_id' => null];
        return new WebhookEvent(['event_id' => 'evt_fixture', 'event_type' => 'withdrawal.completed', 'version' => 1,
            'data' => array_replace(['invoice_id' => self::INVOICE, 'withdrawal_id' => 'wd_fixture', 'project_id' => 'prj_fixture',
                'is_test' => true, 'external_order_id' => $request['external_order_id']], $overrides)]);
    }

    public function project(int $orderId, string $status, array $history): void
    {
        TestCase::assertSame(42, $orderId);
        TestCase::assertNotNull($this->transaction);
        TestCase::assertSame(0, $history['notified']);
        $this->orders[$orderId]['order_status'] = $status;
        ++$this->stock;
        ++$this->nativeWrites;
        $this->history($orderId, $status, $history);
        if ($this->failNativeWrite) {
            $this->failNativeWrite = false;
            throw new \RuntimeException('fixture failure after native write');
        }
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
    {
        TestCase::assertNull($this->transaction);
        $path = parse_url($url, PHP_URL_PATH);
        preg_match('~\AHMAC-SHA256 ([^:]+):~', $headers['Authorization'] ?? '', $authorization);
        $this->requests[] = ['method' => $method, 'path' => $path, 'key' => $authorization[1] ?? null];
        if ($method === 'POST' && $path === '/v1/refunds') {
            TestCase::assertSame('unknown', $this->payload()['cash_state']);
            $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            TestCase::assertSame(['invoice_id', 'mode', 'external_order_id'], array_keys($request));
            TestCase::assertSame(self::INVOICE, $request['invoice_id']);
            $this->posts[] = $request;
            if ($this->refundWindowExpired) {
                return new HttpResponse(409, json_encode([
                    'type' => 'https://paymos.io/docs/errors/codes#refund_window_expired', 'title' => 'Conflict',
                    'status' => 409, 'detail' => 'The invoice refund window has closed.', 'code' => 'refund_window_expired',
                ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/problem+json']);
            }
            $withdrawalId = $this->withdrawals[$request['external_order_id']]
                ?? ($this->withdrawals === [] ? 'wd_fixture' : 'wd_fixture_' . (count($this->withdrawals) + 1));
            $this->withdrawals[$request['external_order_id']] = $withdrawalId;
            if ($this->loseNextPost) {
                $this->loseNextPost = false;
                throw new \RuntimeException('fixture lost POST response');
            }

            return self::response(['withdrawal_id' => $withdrawalId]);
        }
        if ($method === 'GET' && preg_match('~\A/v1/refunds/(wd_fixture(?:_[0-9]+)?)\z~', (string) $path, $matches) === 1) {
            if ($this->loseNextGet) {
                $this->loseNextGet = false;
                throw new \RuntimeException('fixture lost GET response');
            }

            $withdrawalId = $matches[1];
            $request = null;
            foreach ($this->posts as $candidate) {
                if (($this->withdrawals[$candidate['external_order_id']] ?? null) === $withdrawalId) {
                    $request = $candidate;
                }
            }
            $request ??= end($this->posts);
            return self::response($request + ['withdrawal_id' => $withdrawalId, 'project_id' => 'prj_fixture',
                'status' => $this->refundStatus, 'amount' => $request['mode'] === 'overpayment' ? '1.1' : '7.5',
                'is_test' => true, 'currency' => 'USDT', 'network' => 'TRON',
                'first_sender' => self::RECIPIENT, 'destination_address' => self::RECIPIENT]);
        }
        if ($method === 'GET' && $path === '/v1/invoices/' . self::INVOICE) {
            if ($this->loseNextInvoiceGet) {
                $this->loseNextInvoiceGet = false;
                throw new \RuntimeException('fixture lost invoice GET response');
            }
            if ($this->invoiceResponse !== null) {
                return $this->invoiceResponse;
            }
            $invoice = ['invoice_id' => self::INVOICE, 'project_id' => $this->invoiceProject,
                'is_test' => true, 'is_final' => true, 'status' => $this->invoiceStatus,
                'order' => ['external_id' => self::INVOICE_ORDER, 'amount' => '12.34', 'currency' => 'EUR'],
                'payment' => ['currency' => 'USDT', 'network' => 'TRON', 'chain_id' => 728126428,
                    'expected' => '7.5', 'paid' => $this->invoiceStatus === 'paid_over' ? '8.6' : '7.5', 'remaining' => '0']];
            if ($this->refundSummary !== null) {
                $invoice['refund_summary'] = $this->refundSummary;
            }

            return self::response($invoice);
        }

        throw new \LogicException('Unexpected mock Paymos request: ' . $method . ' ' . $path);
    }

    public function query(): mixed
    {
        $sql = $this->sql;
        $this->monitor?->startQuery($sql);
        $this->affected = 0;
        try {
            return match (true) {
                $sql === 'START TRANSACTION' => $this->begin(),
                $sql === 'COMMIT' => $this->commit(),
                $sql === 'ROLLBACK' => $this->rollback(),
                preg_match('/\ASELECT (?:GET_LOCK|RELEASE_LOCK|IS_USED_LOCK)\(/', $sql) === 1 => 1,
                str_contains($sql, 'information_schema.TABLES') => 6,
                str_contains($sql, 'hikashop_paymos_installation') => self::INSTALLATION,
                str_contains($sql, 'hikashop_paymos_connection') => [
                    'method_id' => 17, 'mode' => $this->mode, 'credentials_envelope' => $this->credentials,
                    'device_user_id' => null, 'device_expires_at' => null, 'device_poll_at' => null,
                ],
                str_contains($sql, 'hikashop_paymos_attempt') => $this->attemptQuery($sql),
                str_contains($sql, 'hikashop_paymos_effect') => ['marker' => $this->paymentMarker],
                str_contains($sql, 'hikashop_paymos_refund_projection') => $this->projectionQuery($sql),
                str_contains($sql, 'hikashop_paymos_refund') => $this->intentQuery($sql),
                str_contains($sql, 'hikashop_history') => $this->historyQuery($sql),
                str_contains($sql, 'hikashop_orderstatus') => 1,
                str_contains($sql, 'hikashop_order') => $this->orders[(int) self::field($sql, 'order_id')] ?? null,
                str_contains($sql, 'hikashop_payment') => ['payment_id' => 17, 'payment_type' => 'paymos', 'payment_published' => 1],
                str_starts_with($sql, 'SELECT currency_code') => 'EUR',
                str_starts_with($sql, 'SELECT currency_locale') => serialize(['int_frac_digits' => 2]),
                str_contains($sql, 'hikashop_config') => [
                    ['config_namekey' => 'cancelled_order_status', 'config_value' => 'cancelled,refunded'],
                ],
                default => throw new \LogicException('Unexpected database query: ' . $sql),
            };
        } finally {
            $this->monitor?->stopQuery();
        }
    }

    private function begin(): bool
    {
        TestCase::assertNull($this->transaction);
        $this->transaction = [$this->orders, $this->intents, $this->projections, $this->histories, $this->attempt,
            $this->nativeWrites, $this->stock];
        return true;
    }

    private function commit(): bool
    {
        $this->transaction = null;
        return true;
    }

    private function rollback(): bool
    {
        [$this->orders, $this->intents, $this->projections, $this->histories, $this->attempt,
            $this->nativeWrites, $this->stock] = $this->transaction;
        return $this->commit();
    }

    private function attemptQuery(string $sql): mixed
    {
        if (str_starts_with($sql, 'UPDATE')) {
            $this->attempt['review_reason'] = self::field($sql, 'review_reason');
            return true;
        }
        $where = self::fields(explode(' WHERE ', $sql, 2)[1]);
        foreach ($where as $key => $value) {
            if ((string) $this->attempt[$key] !== (string) $value) {
                return null;
            }
        }

        return $this->attempt;
    }

    private function intentQuery(string $sql): mixed
    {
        if (str_starts_with($sql, 'INSERT')) {
            $row = self::fields($sql) + ['withdrawal_id' => null, 'poll_count' => 0];
            $this->intents[$row['operation_id']] = $row;
            return true;
        }
        [$statement, $where] = explode(' WHERE ', $sql, 2);
        $match = self::fields($where);
        $rows = array_values(array_filter($this->intents, static function (array $row) use ($match): bool {
            return array_intersect_assoc($row, $match) === $match;
        }));
        if (str_starts_with($sql, 'UPDATE')) {
            foreach ($rows as $row) {
                if (str_contains($where, 'withdrawal_id IS NULL') && $row['withdrawal_id'] !== null) {
                    continue;
                }
                $row = array_replace($row, self::fields($statement));
                if (str_contains($statement, 'poll_count = poll_count + 1')) {
                    ++$row['poll_count'];
                }
                $this->intents[$row['operation_id']] = $row;
                ++$this->affected;
            }
            return true;
        }

        return str_contains($sql, ' LIMIT ') || str_starts_with($sql, 'SELECT poll_count') ? ($rows[0] ?? null) : $rows;
    }

    private function projectionQuery(string $sql): mixed
    {
        $operation = self::field($sql, 'operation_id');
        if (str_starts_with($sql, 'INSERT')) {
            TestCase::assertNotNull($this->transaction);
            TestCase::assertArrayNotHasKey($operation, $this->projections);
            $this->projections[$operation] = self::fields($sql);
            return true;
        }

        return $this->projections[$operation] ?? null;
    }

    private function historyQuery(string $sql): mixed
    {
        $fields = self::fields($sql);
        $orderId = $fields['history_order_id'];
        $marker = $fields['history_data'];
        if (str_starts_with($sql, 'INSERT')) {
            TestCase::assertNotNull($this->transaction);
            $this->history($orderId, $fields['history_new_status'], ['amount' => $fields['history_amount'],
                'notified' => $fields['history_notified'], 'data' => $marker, 'type' => $fields['history_type']]);
            return true;
        }

        return array_values(array_filter($this->histories, static fn (array $history): bool =>
            $history['history_order_id'] === $orderId && $history['history_data'] === $marker));
    }

    private function history(int $orderId, string $status, array $history): void
    {
        $id = count($this->histories) + 1;
        $this->histories[$id] = ['history_id' => $id, 'history_order_id' => $orderId, 'history_new_status' => $status,
            'history_notified' => $history['notified'], 'history_amount' => $history['amount'], 'history_data' => $history['data'],
            'history_type' => $history['type'], 'history_payment_method' => 'paymos'];
    }

    private static function field(string $sql, string $name): int|string|null
    {
        return self::fields($sql)[$name] ?? null;
    }

    private static function fields(string $sql): array
    {
        preg_match_all("/\b([a-z_]+) = ('(?:''|[^'])*'|[0-9]+)/i", $sql, $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as [, $key, $value]) {
            $fields[$key] = $value[0] === "'" ? str_replace("''", "'", substr($value, 1, -1)) : (int) $value;
        }

        return $fields;
    }

    private static function response(array $payload): HttpResponse
    {
        return new HttpResponse(200, json_encode($payload, JSON_THROW_ON_ERROR), []);
    }
}
