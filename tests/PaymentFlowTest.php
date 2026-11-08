<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop\Tests;

use Joomla\Database\Mysqli\MysqliDriver;
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Webhook\WebhookEvent;
use PaymosHikaShop\ConnectAdmin;
use PaymosHikaShop\Payments;
use PaymosHikaShop\RefundAdapter;
use PaymosHikaShop\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

final class PaymentFlowTest extends TestCase
{
    public function testUnknownCreateRetryKeepsCommittedIntentAndExactSavedAmount(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $transport->queue(function (array $request) use ($fixture): never {
            self::assertNull($fixture->transaction);
            self::assertCount(1, $fixture->attempts);
            self::assertSame('creating', $fixture->attempts[1]['status']);
            self::assertSame('12.34', $request['amount']);
            self::assertSame('EUR', $request['currency']);
            self::assertSame($fixture->attempts[1]['external_order_id'], $request['external_order_id']);
            throw new \RuntimeException('fixture timeout');
        });
        $this->assertFailure('fixture timeout', fn () => $payments->start(42, 17));

        $fixture->respond($transport, 'awaiting_client');
        $url = $payments->start(42, 17);
        self::assertSame('https://checkout.paymos.io/invoice/inv_fixture_1', $url);
        self::assertSame($transport->requests[0]['body'], $transport->requests[1]['body']);

        $fixture->respond($transport, 'awaiting_client');
        self::assertSame($url, $payments->start(42, 17));
        self::assertSame(['POST', 'POST', 'GET'], array_column($transport->requests, 'method'));
        self::assertCount(1, $fixture->attempts);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame([], $fixture->history);
    }

    public function testDefinitiveMinorUnitRejectionDoesNotReuseOrRoundTheBadAmount(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->order['order_full_price'] = '12.34500';
        $transport->queue(self::minorUnitError());
        $this->assertFailure('paymos_invalid_invoice_amount', fn () => $payments->start(42, 17));

        self::assertSame('12.345', $transport->requests[0]['body']['amount']);
        self::assertSame('rejected', $fixture->attempts[1]['status']);
        self::assertNull($fixture->attempts[1]['invoice_id']);
        $this->assertFailure('paymos_invalid_invoice_amount', fn () => $payments->start(42, 17));
        self::assertCount(1, $transport->requests);

        $fixture->order['order_full_price'] = '12.34000';
        $fixture->respond($transport, 'awaiting_client', 2);
        self::assertSame('https://checkout.paymos.io/invoice/inv_fixture_2', $payments->start(42, 17));
        self::assertSame('12.34', $transport->requests[1]['body']['amount']);
        self::assertNotSame($transport->requests[0]['body']['external_order_id'], $transport->requests[1]['body']['external_order_id']);
    }

    public function testLostCreateOutcomeCannotBeClearedByARejectedRetryOrChangedSnapshot(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $transport->queue(static function (): never {
            throw new \RuntimeException('fixture timeout');
        });
        $this->assertFailure('fixture timeout', fn () => $payments->start(42, 17));
        $transport->queue(self::minorUnitError());
        $this->assertFailure('paymos_invalid_invoice_amount', fn () => $payments->start(42, 17));
        self::assertSame('creating', $fixture->attempts[1]['status']);

        $fixture->order['order_full_price'] = '12.35000';
        $this->assertFailure('paymos_attempt_open', fn () => $payments->start(42, 17));
        self::assertCount(2, $transport->requests);
        self::assertCount(1, $fixture->attempts);
        self::assertNull($fixture->attempts[1]['invoice_id']);
    }

    public function testReplacementUsesOriginalEnvironmentAndClosesOldInvoiceBeforeTheNewPost(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->mode = 'live';
        $fixture->respond($transport, 'awaiting_client');
        $transport->queue(PaymentTransportFixture::response(['status' => 'cancelled']));
        $fixture->respond($transport, 'awaiting_client', 2);

        self::assertSame('https://checkout.paymos.io/invoice/inv_fixture_2', $payments->start(42, 17));
        self::assertSame('cancelled', $fixture->attempts[1]['status']);
        self::assertSame('live', $fixture->attempts[2]['environment']);
        self::assertSame(
            ['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/invoices/inv_fixture_1/cancel', '/v1/invoices'],
            array_column($transport->requests, 'path')
        );
        self::assertSame(
            ['pk_test_fixture', 'pk_test_fixture', 'pk_test_fixture', 'pk_live_fixture'],
            array_column($transport->requests, 'key')
        );
    }

    public function testAnOpenPreviousInvoiceBlocksAChangedAmount(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->order['order_full_price'] = '12.35000';
        $fixture->respond($transport, 'awaiting_payment');
        $transport->queue(new HttpResponse(409, '{}', []));
        $fixture->respond($transport, 'awaiting_payment');

        $this->assertFailure('paymos_previous_invoice_unresolved', fn () => $payments->start(42, 17));
        self::assertCount(1, $fixture->attempts);
        self::assertCount(4, $transport->requests);
    }

    public function testLateMoneyOnAnInvoiceThatExpiresDuringReplacementBlocksTheNextCharge(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->order['order_full_price'] = '12.35000';
        $fixture->respond($transport, 'awaiting_payment');
        $transport->queue(new HttpResponse(409, '{}', []));
        $fixture->respond($transport, 'expired', 1, ['paid_late_at' => time()]);
        $fixture->respond($transport, 'expired', 1, ['paid_late_at' => time()]);

        $this->assertFailure('paymos_payment_needs_review', fn () => $payments->start(42, 17));
        self::assertCount(1, $fixture->attempts);
        self::assertSame('expired', $fixture->attempts[1]['status']);
        self::assertSame('paymos_paid_late', $fixture->attempts[1]['review_reason']);
        self::assertSame(['POST', 'GET', 'POST', 'GET', 'GET'], array_column($transport->requests, 'method'));
        self::assertSame('created', $fixture->order['order_status']);
    }

    public function testUntrustedHostedUrlNeverReachesTheOrderOrBuyer(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client', 1, ['payment_url' => 'https://evil.example/invoice/inv_fixture_1']);

        $this->assertFailure('paymos_invalid_invoice_response', fn () => $payments->start(42, 17));
        self::assertNull($fixture->attempts[1]['invoice_id']);
        self::assertNull($fixture->attempts[1]['payment_url']);
        self::assertFalse(Payments::trustedCheckoutUrl('https://checkout.paymos.io@evil.example/invoice/inv_fixture_1'));
        self::assertFalse(Payments::trustedCheckoutUrl('https://checkout.paymos.io:444/invoice/inv_fixture_1'));
        self::assertFalse(Payments::trustedCheckoutUrl('https://checkout.paymos.io/login'));
        self::assertFalse(Payments::trustedCheckoutUrl('https://checkout.paymos.io/invoice/inv_other', 'inv_fixture_1'));
    }

    public function testSignedPaidEventAndTwoKindsOfReplayProjectExactlyOneOrderAndHistory(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        self::assertSame('created', $fixture->order['order_status']);

        $fixture->respond($transport, 'paid');
        $event = $this->event($fixture, 'evt_paid', 'paid');
        self::assertSame(200, $payments->webhook(...$event));
        self::assertSame(200, $payments->webhook(...$event));
        self::assertCount(2, $transport->requests);

        // HikaShop can advance the confirmed order independently after fulfilment.
        $fixture->order['order_status'] = 'shipped';
        $fixture->respond($transport, 'paid_over');
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_over', 'paid_over')));
        self::assertSame('shipped', $fixture->order['order_status']);
        self::assertSame('paid_over', $fixture->attempts[1]['status']);
        self::assertNull($fixture->attempts[1]['review_reason']);
        self::assertSame(1, $fixture->projections);
        self::assertCount(1, $fixture->history);
        self::assertCount(1, $fixture->effects);
        self::assertSame(1, $fixture->events['evt_paid']['committed']);
        self::assertSame(1, $fixture->events['evt_over']['committed']);
    }

    public function testForgedWrongProjectAndWrongEnvironmentEventsMakeNoReverseCalls(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);

        [$raw, $signature, $now] = $this->event($fixture, 'evt_forged', 'paid');
        self::assertSame(401, $payments->webhook($raw . ' ', $signature, $now));
        self::assertSame(400, $payments->webhook(...$this->event($fixture, 'evt_wrong_project', 'paid', ['project_id' => 'prj_other'])));
        self::assertSame(400, $payments->webhook(...$this->event($fixture, 'evt_wrong_environment', 'paid', [], 'live')));
        self::assertSame(400, $payments->webhook(...$this->event($fixture, 'evt_false_test', 'paid', ['is_test' => 'true'])));
        self::assertSame(400, $payments->webhook(str_repeat('x', Payments::MAX_WEBHOOK_BYTES + 1), $signature, $now));
        self::assertSame(401, $payments->webhook($raw, $signature, $now + 301));
        self::assertCount(1, $transport->requests);
        self::assertSame([], $fixture->events);
        self::assertSame('created', $fixture->order['order_status']);
    }

    public function testUnderpaymentAndOutOfOrderPaidNoticeDoNotFulfill(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'underpaid');
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_under', 'underpaid')));
        $fixture->respond($transport, 'underpaid');
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_stale_paid', 'paid')));
        self::assertSame('underpaid', $fixture->attempts[1]['status']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame(0, $fixture->projections);
        self::assertSame(['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/invoices/inv_fixture_1'],
            array_column($transport->requests, 'path'));
    }

    public function testReverseReadWithForeignBindingDoesNotAcknowledgeOrFulfill(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid', 1, ['project_id' => 'prj_other']);

        self::assertSame(503, $payments->webhook(...$this->event($fixture, 'evt_wrong_reverse', 'paid')));
        self::assertSame([], $fixture->events);
        self::assertSame(0, $fixture->projections);
    }

    #[TestWith(['order_full_price', '12.35000', 'paymos_native_order_changed', 'paid', true])]
    #[TestWith(['order_status', 'cancelled', 'paymos_native_status_changed', 'paid_over', false])]
    public function testRestoredNativeOrderClearsItsTransientReviewAfterVerifiedPayment(
        string $field,
        string $mismatch,
        string $reason,
        string $paidStatus,
        bool $reconcile
    ): void {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $original = $fixture->order[$field];
        $fixture->order[$field] = $mismatch;
        $fixture->respond($transport, $paidStatus);
        $review = $this->event($fixture, 'evt_changed_order', $paidStatus);

        self::assertSame(200, $payments->webhook(...$review));
        self::assertSame($reason, $fixture->attempts[1]['review_reason']);
        self::assertSame($mismatch, $fixture->order[$field]);
        self::assertSame(0, $fixture->projections);
        self::assertSame([], $fixture->effects);

        $fixture->order[$field] = $original;
        self::assertSame(200, $payments->webhook(...$review));
        self::assertSame($reason, $fixture->attempts[1]['review_reason']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertCount(2, $transport->requests);

        $fixture->respond($transport, $paidStatus);
        if ($reconcile) {
            self::assertSame($paidStatus, $payments->reconcile(42, 17));
        } else {
            self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_recovered_payment', $paidStatus)));
        }
        self::assertSame('confirmed', $fixture->order['order_status']);
        self::assertNull($fixture->attempts[1]['review_reason']);

        $fixture->respond($transport, $paidStatus);
        $duplicate = $this->event($fixture, 'evt_duplicate_recovered_payment', $paidStatus);
        self::assertSame(200, $payments->webhook(...$duplicate));
        self::assertSame(200, $payments->webhook(...$duplicate));
        self::assertSame($paidStatus, $fixture->attempts[1]['status']);
        self::assertNull($fixture->attempts[1]['review_reason']);
        self::assertSame(1, $fixture->projections);
        self::assertCount(1, $fixture->effects);
        self::assertCount(1, $fixture->history);
    }

    #[TestWith(['shipped', true])]
    #[TestWith(['cancelled', false])]
    #[TestWith(['refunded', false])]
    public function testExistingPaidEffectClearsTransientReviewOnlyForAPaidNativeStatus(string $nativeStatus, bool $expectClear): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid');
        self::assertSame('paid', $payments->reconcile(42, 17));

        $fixture->order['order_status'] = $nativeStatus;
        $fixture->order['order_full_price'] = '12.35000';
        $fixture->respond($transport, 'paid');
        self::assertSame('review', $payments->reconcile(42, 17));
        self::assertSame('paymos_native_order_changed', $fixture->attempts[1]['review_reason']);

        $fixture->order['order_full_price'] = '12.34000';
        $fixture->respond($transport, 'paid');
        self::assertSame($expectClear ? 'paid' : 'review', $payments->reconcile(42, 17));
        self::assertSame($expectClear ? null : 'paymos_native_order_changed', $fixture->attempts[1]['review_reason']);
        self::assertSame($nativeStatus, $fixture->order['order_status']);
        self::assertSame(1, $fixture->projections);
        self::assertCount(1, $fixture->effects);
        self::assertCount(1, $fixture->history);
    }

    public function testLateMoneyOnPreviousInvoiceOnlyFlagsTheOrderForReview(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'expired');
        $fixture->respond($transport, 'awaiting_client', 2);
        $payments->start(42, 17);
        $fixture->respond($transport, 'expired', 1, ['paid_late_at' => time()]);

        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_paid_late', 'expired', [], 'sandbox', 'invoice.paid_late')));
        self::assertSame('paymos_paid_late', $fixture->attempts[1]['review_reason']);
        self::assertSame('paymos_previous_attempt_paid', $fixture->attempts[2]['review_reason']);
        self::assertSame('awaiting_client', $fixture->attempts[2]['status']);
        self::assertSame('created', $fixture->order['order_status']);
        $this->assertFailure('paymos_payment_needs_review', fn () => $payments->start(42, 17));
    }

    public function testFailedEventCommitRollsBackNativeStatusAndHistoryThenRetriesSafely(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid');
        $fixture->respond($transport, 'paid');
        $fixture->failNextEventCommit = true;
        $event = $this->event($fixture, 'evt_retry', 'paid');

        self::assertSame(503, $payments->webhook(...$event));
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame('awaiting_client', $fixture->attempts[1]['status']);
        self::assertSame([], $fixture->history);
        self::assertSame([], $fixture->effects);
        self::assertSame([], $fixture->events);

        self::assertSame(200, $payments->webhook(...$event));
        self::assertSame('confirmed', $fixture->order['order_status']);
        self::assertCount(1, $fixture->history);
        self::assertCount(1, $fixture->effects);
        self::assertSame(1, $fixture->events['evt_retry']['committed']);
    }

    public function testMissingNativeMarkerRollsBackAndStopsAutomaticProjection(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->omitHistory = true;
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid');
        $fixture->respond($transport, 'paid');

        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_missing_history', 'paid')));
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_review_again', 'paid')));
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame('paymos_native_projection_unverified', $fixture->attempts[1]['review_reason']);
        self::assertSame(1, $fixture->projections);
        self::assertSame([], $fixture->effects);
    }

    public function testKnownInvoiceCanBeReconciledThroughTheSameProjection(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $this->assertFailure('paymos_attempt_missing', fn () => $payments->reconcile(42, 18));
        self::assertCount(1, $transport->requests);
        $fixture->respond($transport, 'paid');
        self::assertSame('paid', $payments->reconcile(42, 17));
        self::assertSame('confirmed', $fixture->order['order_status']);
        self::assertCount(1, $fixture->history);
    }

    public function testUnboundRefundReviewBlocksDelayedPaymentAndReconciliation(): void
    {
        [$payments, $fixture, $transport, $store] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $store->withOrderLock(42, static fn () => $store->recordAttemptState(42, 1, 'awaiting_client', 'paymos_refund_unbound'));

        $fixture->respond($transport, 'paid');
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_paid_after_refund', 'paid')));
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame(1, $fixture->events['evt_paid_after_refund']['committed']);
        self::assertSame('paymos_refund_unbound', $fixture->attempts[1]['review_reason']);

        $fixture->respond($transport, 'paid');
        self::assertSame('review', $payments->reconcile(42, 17));
        $this->assertFailure('paymos_payment_needs_review', fn () => $payments->start(42, 17));
        self::assertSame('paymos_refund_unbound', $fixture->attempts[1]['review_reason']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame(0, $fixture->projections);
        self::assertSame([], $fixture->history);
        self::assertSame([], $fixture->effects);
        self::assertSame(['POST', 'GET', 'GET'], array_column($transport->requests, 'method'));
    }

    public function testSignedUnboundOverpaymentCreationPromotesReviewBeforeRestoredOrderCanFulfill(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true, wireRefundAdapter: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->order['order_full_price'] = '12.35000';
        $fixture->respond($transport, 'paid_over');
        $fixture->respondRefunds($transport, []);
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_initial_paid_over', 'paid_over')));
        self::assertSame('paymos_native_order_changed', $fixture->attempts[1]['review_reason']);
        self::assertSame('created', $fixture->order['order_status']);

        $withdrawal = $this->unboundOverpaymentEvent($fixture);
        self::assertSame(401, $payments->webhook($withdrawal[0] . "\n", $withdrawal[1], $withdrawal[2]));
        self::assertSame('paymos_native_order_changed', $fixture->attempts[1]['review_reason']);
        self::assertSame(200, $payments->webhook(...$withdrawal));
        self::assertSame(1, $fixture->events['evt_unbound_overpayment']['committed']);
        self::assertCount(3, $transport->requests);

        $fixture->order['order_full_price'] = '12.34000';
        $fixture->respond($transport, 'paid_over');
        $fixture->respondRefunds($transport, []);
        $result = $payments->reconcile(42, 17);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame('review', $result);
        self::assertSame('paymos_refund_unbound', $fixture->attempts[1]['review_reason']);
        self::assertSame(0, $fixture->projections);
        self::assertSame([], $fixture->effects);
        self::assertSame(['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/refunds', '/v1/invoices/inv_fixture_1'],
            array_column($transport->requests, 'path'));
    }

    public function testUnboundWithdrawalPreservesHardProjectionReview(): void
    {
        [$payments, $fixture, $transport, $store] = $this->scenario(wireRefundAdapter: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $store->withOrderLock(42, static fn () => $store->recordAttemptState(42, 1, 'paid_over', 'paymos_native_projection_unverified'));

        self::assertSame(200, $payments->webhook(...$this->unboundOverpaymentEvent($fixture)));
        self::assertSame('paymos_native_projection_unverified', $fixture->attempts[1]['review_reason']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame(0, $fixture->projections);
        self::assertSame(['/v1/invoices'], array_column($transport->requests, 'path'));
    }

    public function testCompletedFullRefundIsCheckedAgainstTheVerifiedInvoiceAfterLostCreate(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $transport->queue(static function (): never {
            throw new \RuntimeException('fixture timeout');
        });
        $this->assertFailure('fixture timeout', fn () => $payments->start(42, 17));
        self::assertNull($fixture->attempts[1]['invoice_id']);
        $fixture->mode = 'live';
        $paid = $this->event($fixture, 'evt_completed_full_refund', 'paid');

        $fixture->failNextEventCommit = true;
        $fixture->respond($transport, 'paid');
        $fixture->respondRefunds($transport, [PaymentDatabaseFixture::refund('full')]);
        self::assertSame(503, $payments->webhook(...$paid));
        self::assertNull($fixture->attempts[1]['invoice_id']);
        self::assertNull($fixture->attempts[1]['review_reason']);
        self::assertArrayNotHasKey('evt_completed_full_refund', $fixture->events);

        $fixture->respond($transport, 'paid');
        $fixture->respondRefunds($transport, [PaymentDatabaseFixture::refund('full')]);
        self::assertSame(200, $payments->webhook(...$paid));
        self::assertSame(200, $payments->webhook(...$paid));
        self::assertSame('inv_fixture_1', $fixture->attempts[1]['invoice_id']);
        self::assertSame('paymos_full_refund_before_payment', $fixture->attempts[1]['review_reason']);
        self::assertSame(1, $fixture->events['evt_completed_full_refund']['committed']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame(0, $fixture->projections);
        self::assertSame([], $fixture->history);
        self::assertSame([], $fixture->effects);
        self::assertSame(['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/refunds', '/v1/invoices/inv_fixture_1', '/v1/refunds'],
            array_column($transport->requests, 'path'));
        self::assertSame('pk_test_fixture', $transport->requests[4]['key']);
        self::assertSame('invoice_id=inv_fixture_1&limit=100&status=completed', $transport->requests[4]['query']);
    }

    public function testManualReconciliationBlocksFullRefundAndKeepsItsReviewReason(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid_over');
        $fixture->respondRefunds($transport, [PaymentDatabaseFixture::refund('full'), PaymentDatabaseFixture::refund('overpayment')]);
        self::assertSame('review', $payments->reconcile(42, 17));
        self::assertSame('paymos_full_refund_before_payment', $fixture->attempts[1]['review_reason']);

        $fixture->respond($transport, 'paid_over');
        self::assertSame('review', $payments->reconcile(42, 17));
        $this->assertFailure('paymos_payment_needs_review', fn () => $payments->start(42, 17));
        self::assertSame('paymos_full_refund_before_payment', $fixture->attempts[1]['review_reason']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame([], $fixture->effects);
        self::assertSame(['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/refunds', '/v1/invoices/inv_fixture_1'],
            array_column($transport->requests, 'path'));
    }

    public function testCompletedOverpaymentAllowsFirstNativePaymentAndReplayNeedsNoRefundSnapshot(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid_over');
        $fixture->respondRefunds($transport, [PaymentDatabaseFixture::refund('overpayment')]);
        self::assertSame('paid_over', $payments->reconcile(42, 17));

        $fixture->respond($transport, 'paid_over');
        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_paid_over_after_reconcile', 'paid_over')));
        self::assertSame('confirmed', $fixture->order['order_status']);
        self::assertSame(1, $fixture->projections);
        self::assertNull($fixture->attempts[1]['review_reason']);
        self::assertSame(['/v1/invoices', '/v1/invoices/inv_fixture_1', '/v1/refunds', '/v1/invoices/inv_fixture_1'],
            array_column($transport->requests, 'path'));
    }

    #[DataProvider('invalidRefundSnapshots')]
    public function testUnusableRefundSnapshotReleasesCallbackForRetry(?array $page): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $event = $this->event($fixture, 'evt_refund_read_retry', 'paid');
        $fixture->respond($transport, 'paid');
        $transport->queue($page === null ? new HttpResponse(503, '{}', []) : PaymentTransportFixture::response($page));
        self::assertSame(503, $payments->webhook(...$event));
        self::assertArrayNotHasKey('evt_refund_read_retry', $fixture->events);
        self::assertSame('awaiting_client', $fixture->attempts[1]['status']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame([], $fixture->effects);

        $fixture->respond($transport, 'paid');
        $fixture->respondRefunds($transport, []);
        self::assertSame(200, $payments->webhook(...$event));
        self::assertSame(1, $fixture->projections);
        self::assertSame('confirmed', $fixture->order['order_status']);
    }

    public static function invalidRefundSnapshots(): iterable
    {
        $full = PaymentDatabaseFixture::refund('full');
        $overpayment = PaymentDatabaseFixture::refund('overpayment');
        foreach ([
            'foreign invoice' => ['invoice_id' => 'inv_other'],
            'foreign project' => ['project_id' => 'prj_other'],
            'foreign environment' => ['is_test' => false],
            'non-boolean environment' => ['is_test' => 'true'],
            'non-completed status' => ['status' => 'failed'],
            'non-final status' => ['is_final' => false],
            'unknown refund mode' => ['mode' => 'partial'],
            'invalid withdrawal type' => ['type' => 'withdrawal'],
            'invalid withdrawal id' => ['withdrawal_id' => 'inv_fixture_1'],
            'duplicate withdrawal id' => ['withdrawal_id' => $full['withdrawal_id']],
            'invalid external id' => ['external_order_id' => ''],
        ] as $case => $change) {
            // A valid full refund earlier on the page cannot hide a malformed later row.
            yield $case => [['items' => [$full, array_replace($overpayment, $change)], 'next_cursor' => null]];
        }
        yield 'unavailable' => [null];
        yield 'cursor missing' => [['items' => []]];
        yield 'paginated' => [['items' => [$full], 'next_cursor' => 'other-page']];
        yield 'items missing' => [['next_cursor' => null]];
        yield 'items map' => [['items' => ['refund' => $full], 'next_cursor' => null]];
        $oversized = array_map(static fn (int $i): array => array_replace($overpayment, [
            'withdrawal_id' => 'wdr_Fixture' . $i, 'external_order_id' => 'refund-fixture-' . $i,
        ]), range(1, 101));
        yield 'oversized page' => [['items' => $oversized, 'next_cursor' => null]];
    }

    public function testIncompleteRefundSnapshotStopsManualReconciliation(): void
    {
        [$payments, $fixture, $transport] = $this->scenario(refundContract: true);
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $fixture->respond($transport, 'paid');
        $transport->queue(PaymentTransportFixture::response(['items' => [], 'next_cursor' => 'other-page']));
        $this->assertFailure('paymos_refund_snapshot_invalid', fn () => $payments->reconcile(42, 17));
        self::assertSame('awaiting_client', $fixture->attempts[1]['status']);
        self::assertSame('created', $fixture->order['order_status']);
        self::assertSame([], $fixture->history);
        self::assertSame([], $fixture->effects);
    }

    public function testSignedEventCanBindAnInvoiceAfterTheCreateResponseWasLost(): void
    {
        [$payments, $fixture, $transport] = $this->scenario();
        $transport->queue(static function (): never {
            throw new \RuntimeException('fixture timeout');
        });
        $this->assertFailure('fixture timeout', fn () => $payments->start(42, 17));
        self::assertNull($fixture->attempts[1]['invoice_id']);
        $fixture->respond($transport, 'paid');

        self::assertSame(200, $payments->webhook(...$this->event($fixture, 'evt_before_binding', 'paid')));
        self::assertSame('inv_fixture_1', $fixture->attempts[1]['invoice_id']);
        self::assertSame('confirmed', $fixture->order['order_status']);
        self::assertCount(1, $fixture->effects);
    }

    public function testWithdrawalUsesTheSameReservationAndAnActiveLeaseIsNeverAcknowledged(): void
    {
        $calls = [];
        [$payments, $fixture, $transport] = $this->scenario(static function (WebhookEvent $event, string $environment) use (&$calls): void {
            $calls[] = [$event->id(), $environment];
        });
        $fixture->respond($transport, 'awaiting_client');
        $payments->start(42, 17);
        $event = $this->event($fixture, 'evt_refund', 'awaiting_client', [], 'sandbox', 'withdrawal.completed');
        $fixture->events['evt_refund'] = [
            'token' => 'another_worker', 'lease_until' => time() + 120, 'expires_at' => time() + 10000, 'committed' => 0,
        ];

        self::assertSame(503, $payments->webhook(...$event));
        self::assertSame([], $calls);
        self::assertSame('another_worker', $fixture->events['evt_refund']['token']);
        $fixture->events['evt_refund']['lease_until'] = time() - 1;

        self::assertSame(200, $payments->webhook(...$event));
        self::assertSame(200, $payments->webhook(...$event));
        self::assertSame([['evt_refund', 'sandbox']], $calls);
        self::assertSame(1, $fixture->events['evt_refund']['committed']);
        self::assertCount(1, $transport->requests);
        self::assertSame('created', $fixture->order['order_status']);
    }

    private static function minorUnitError(): HttpResponse
    {
        return new HttpResponse(400, json_encode([
            'type' => 'https://paymos.io/docs/errors/codes#amount_too_many_decimals',
            'status' => 400, 'title' => 'Bad Request', 'detail' => 'Invalid amount precision.',
            'code' => 'amount_too_many_decimals', 'field' => 'amount',
        ], JSON_THROW_ON_ERROR), []);
    }

    private function assertFailure(string $message, callable $call): void
    {
        try {
            $call();
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
            return;
        }
        self::fail('Expected payment to refuse: ' . $message);
    }

    private function event(
        PaymentDatabaseFixture $fixture,
        string $eventId,
        string $status,
        array $override = [],
        string $signWith = 'sandbox',
        ?string $type = null
    ): array {
        $now = time();
        $data = array_replace($fixture->invoice($status), $override);
        unset($data['payment_url']);
        $raw = json_encode(['event_id' => $eventId, 'event_type' => $type ?? 'invoice.' . $status,
            'version' => 1, 'occurred_at' => $now, 'data' => $data], JSON_THROW_ON_ERROR);
        $secret = $signWith === 'live' ? 'whsec_live_fixture' : 'whsec_test_fixture';
        return [$raw, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $raw, $secret), $now];
    }

    private function unboundOverpaymentEvent(PaymentDatabaseFixture $fixture): array
    {
        return $this->event($fixture, 'evt_unbound_overpayment', 'created', [
            'withdrawal_id' => 'wdr_OutsideFixture', 'external_order_id' => 'outside-refund',
            'type' => 'refund', 'mode' => 'overpayment', 'is_final' => false,
        ], 'sandbox', 'withdrawal.created');
    }

    private function scenario(?callable $onWithdrawal = null, bool $refundContract = false, bool $wireRefundAdapter = false): array
    {
        $fixture = new PaymentDatabaseFixture($refundContract);
        $db = $this->getMockBuilder(MysqliDriver::class)->disableOriginalConstructor()->onlyMethods([
            'setQuery', 'getMonitor', 'setMonitor', 'getPrefix', 'getConnection', 'quote', 'quoteName',
            'loadResult', 'loadAssoc', 'execute', 'getAffectedRows',
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
        $db->method('loadResult')->willReturnCallback($fixture->query(...));
        $db->method('loadAssoc')->willReturnCallback($fixture->query(...));
        $db->method('execute')->willReturnCallback($fixture->query(...));
        $db->method('getAffectedRows')->willReturnCallback(static fn () => $fixture->affected);

        $transport = new PaymentTransportFixture();
        $store = new Store($db);
        $admin = new ConnectAdmin($store, PaymentDatabaseFixture::SITE, PaymentDatabaseFixture::SECRET);
        if ($wireRefundAdapter) {
            $refunds = new RefundAdapter($store, $admin, static fn (): never => throw new \LogicException('Unexpected native refund'));
            $onWithdrawal = $refunds->callback(...);
        }
        $payments = new Payments($store, $admin, $fixture->project(...), $onWithdrawal,
            static fn (array $keys) => new Client(new ClientConfig($keys['api_key'], $keys['api_secret']), $transport));
        return [$payments, $fixture, $transport, $store];
    }
}

/** Keeps the SDK signing/request/response stack real while controlling the API answers. */
final class PaymentTransportFixture implements TransportInterface
{
    public array $requests = [];
    private array $responses = [];

    public static function response(array $data): HttpResponse
    {
        return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR), []);
    }

    public function queue(HttpResponse|callable $response): void
    {
        $this->responses[] = $response;
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
    {
        $decoded = $body === null || $body === '' ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        preg_match('~\AHMAC-SHA256 ([^:]+):[a-zA-Z0-9+/]+={0,2}\z~', $headers['Authorization'] ?? '', $authorization);
        $this->requests[] = ['method' => $method, 'path' => parse_url($url, PHP_URL_PATH), 'query' => parse_url($url, PHP_URL_QUERY),
            'key' => $authorization[1] ?? null, 'body' => $decoded];
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('Unplanned request to the mock Paymos API');
        }
        return $response instanceof HttpResponse ? $response : $response($decoded);
    }
}

/** Transactional SQL seam. All reads/writes go through the real Store and EventStore. */
final class PaymentDatabaseFixture
{
    public const SITE = 'https://shop.example';
    public const SECRET = 'payment-fixture-secret';
    private const INSTALLATION = '11111111-2222-4333-8444-555555555555';

    public readonly object $connection;
    public mixed $monitor = null;
    public string $sql = '';
    public string $mode = 'sandbox';
    public int $affected = 0;
    public int $projections = 0;
    public bool $failNextEventCommit = false;
    public bool $omitHistory = false;
    public ?array $transaction = null;
    public array $attempts = [];
    public array $events = [];
    public array $history = [];
    public array $effects = [];
    public array $order = [
        'order_id' => 42, 'order_payment_id' => '17', 'order_payment_method' => 'paymos',
        'order_status' => 'created', 'order_full_price' => '12.34000', 'order_currency_id' => 1,
    ];
    private string $credentialEnvelope;

    public function __construct(bool $refundContract = false)
    {
        $this->connection = new \stdClass();
        $environments = [];
        foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
            $environments[$environment] = ['api_key' => "pk_{$prefix}_fixture", 'api_secret' => "sk_{$prefix}_fixture",
                'project_id' => 'prj_fixture', 'webhook_secret' => "whsec_{$prefix}_fixture"];
        }
        if ($refundContract) {
            $environments['sandbox'] += ['capabilities' => ['invoice_refunds_v1'], 'refund_contract_version' => 1, 'refunds_enabled' => false];
        }
        $this->credentialEnvelope = AesGcmEnvelope::seal([
            'plugin' => 'hikashop', 'source_url' => self::SITE, 'environments' => $environments,
        ], self::SECRET . "\n" . self::INSTALLATION, 'paymos-hikashop-v1:17:credentials');
    }

    public function project(int $orderId, string $marker): void
    {
        TestCase::assertSame(42, $orderId);
        TestCase::assertNotNull($this->transaction);
        ++$this->projections;
        $this->order['order_status'] = 'confirmed';
        if (!$this->omitHistory) {
            $this->history[] = $marker;
        }
    }

    public function respond(PaymentTransportFixture $transport, string $status, int $generation = 1, array $override = []): void
    {
        $transport->queue(fn () => PaymentTransportFixture::response(array_replace($this->invoice($status, $generation), $override)));
    }

    public function respondRefunds(PaymentTransportFixture $transport, array $items): void
    {
        $transport->queue(function () use ($items): HttpResponse {
            TestCase::assertNull($this->transaction);
            TestCase::assertNotNull($this->monitor);
            return PaymentTransportFixture::response(['items' => $items, 'next_cursor' => null]);
        });
    }

    public static function refund(string $mode): array
    {
        return [
            'withdrawal_id' => 'wdr_Fixture' . ucfirst($mode), 'invoice_id' => 'inv_fixture_1', 'project_id' => 'prj_fixture',
            'type' => 'refund', 'mode' => $mode, 'status' => 'completed', 'is_final' => true, 'is_test' => true,
            'external_order_id' => 'refund-fixture-' . $mode,
        ];
    }

    public function invoice(string $status, int $generation = 1): array
    {
        $row = $this->attempts[$generation];
        return [
            'invoice_id' => 'inv_fixture_' . $generation, 'project_id' => $row['project_id'],
            'status' => $status, 'is_test' => $row['environment'] === 'sandbox',
            'payment_url' => 'https://checkout.paymos.io/invoice/inv_fixture_' . $generation,
            'order' => ['external_id' => $row['external_order_id'], 'amount' => $row['amount'], 'currency' => $row['currency']],
        ];
    }

    public function query(): mixed
    {
        $sql = $this->sql;
        $this->monitor?->startQuery($sql);
        $this->affected = 0;
        try {
            return match (true) {
                $sql === 'START TRANSACTION' => $this->begin(),
                $sql === 'ROLLBACK' => $this->rollback(),
                $sql === 'COMMIT' => $this->commit(),
                str_starts_with($sql, 'SELECT installation_id') => self::INSTALLATION,
                preg_match('/\ASELECT (?:GET_LOCK|RELEASE_LOCK|IS_USED_LOCK)\(/', $sql) === 1 => 1,
                str_contains($sql, 'hikashop_paymos_connection') => [
                    'method_id' => 17, 'mode' => $this->mode, 'credentials_envelope' => $this->credentialEnvelope,
                    'device_user_id' => null, 'device_expires_at' => null, 'device_poll_at' => null,
                ],
                str_contains($sql, 'hikashop_paymos_attempt') => $this->attempt($sql),
                str_contains($sql, 'hikashop_paymos_event') => $this->event($sql),
                str_contains($sql, 'hikashop_paymos_effect') => $this->effect($sql),
                str_starts_with($sql, 'SELECT ') && str_contains($sql, '`#__hikashop_paymos_refund`') => null,
                str_contains($sql, 'hikashop_order') => $this->order,
                str_contains($sql, 'hikashop_payment') => ['payment_id' => 17, 'payment_type' => 'paymos', 'payment_published' => 1],
                str_contains($sql, 'hikashop_currency') => 'EUR',
                str_contains($sql, 'hikashop_history') => $this->countHistory($sql),
                default => throw new \LogicException('Unplanned database operation: ' . $sql),
            };
        } finally {
            $this->monitor?->stopQuery();
        }
    }

    private function begin(): bool
    {
        TestCase::assertNull($this->transaction);
        $this->transaction = [$this->attempts, $this->events, $this->effects, $this->order, $this->history];
        return true;
    }

    private function rollback(): bool
    {
        [$this->attempts, $this->events, $this->effects, $this->order, $this->history] = $this->transaction;
        $this->transaction = null;
        return true;
    }

    private function commit(): bool
    {
        $this->transaction = null;
        return true;
    }

    private function attempt(string $sql): mixed
    {
        if (str_starts_with($sql, 'SELECT')) {
            if (preg_match("/(?:external_order_id|invoice_id) = '([^']+)'/", $sql, $match)) {
                foreach ($this->attempts as $row) {
                    if (in_array($match[1], [$row['external_order_id'], $row['invoice_id']], true)) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/AND generation = (\d+)/', $sql, $match)) {
                return $this->attempts[(int) $match[1]] ?? null;
            }
            return $this->attempts === [] ? null : end($this->attempts);
        }
        if (str_starts_with($sql, 'INSERT')) {
            [, $generation, $method, $environment, $project, $externalId, $amount, $currency] = self::insertValues($sql);
            $this->attempts[(int) $generation] = [
                'order_id' => 42, 'generation' => $generation, 'method_id' => $method, 'environment' => $environment,
                'project_id' => $project, 'external_order_id' => $externalId, 'amount' => $amount,
                'currency' => $currency, 'invoice_id' => null, 'payment_url' => null, 'status' => 'creating', 'review_reason' => null,
            ];
            return true;
        }

        preg_match('/AND generation = (\d+)/', $sql, $match);
        $generation = (int) $match[1];
        foreach (['invoice_id', 'payment_url', 'status'] as $field) {
            if (preg_match("/\b$field = '([^']+)'/", $sql, $value)) {
                $this->attempts[$generation][$field] = $value[1];
            }
        }
        if (preg_match("/review_reason = CASE WHEN BINARY review_reason = '([^']+)' THEN NULL ELSE review_reason END/", $sql, $value)) {
            TestCase::assertNotNull($this->transaction);
            if ($this->attempts[$generation]['review_reason'] === $value[1]) {
                $this->attempts[$generation]['review_reason'] = null;
            }
        } elseif (preg_match("/review_reason = '([^']+)'/", $sql, $value)) {
            $this->attempts[$generation]['review_reason'] = $value[1];
        }
        return true;
    }

    private function event(string $sql): mixed
    {
        if (str_starts_with($sql, 'INSERT')) {
            [, $id, $token, $lease, $expiry] = self::insertValues($sql);
            $this->events[$id] ??= ['token' => $token, 'lease_until' => (int) $lease, 'expires_at' => (int) $expiry, 'committed' => 0];
            return true;
        }
        if (!preg_match("/event_id = '([^']+)'/", $sql, $id)) {
            return true; // Bounded expiry pruning is independent of the selected row.
        }
        $id = $id[1];
        $row = $this->events[$id] ?? null;
        if (str_starts_with($sql, 'SELECT token')) {
            return $row['committed'] === 0 ? $row['token'] : null;
        }
        if (str_starts_with($sql, 'SELECT committed')) {
            return ($row['committed'] ?? null) === 1 && $row['expires_at'] > time() ? 1 : null;
        }
        if (str_contains($sql, 'SET committed = 1')) {
            TestCase::assertNotNull($this->transaction);
            if ($this->failNextEventCommit) {
                $this->failNextEventCommit = false;
                throw new \RuntimeException('fixture event commit failed');
            }
            if (self::ownedEvent($sql, $row) && $row['lease_until'] > time()) {
                $this->events[$id]['committed'] = 1;
                $this->affected = 1;
            }
            return true;
        }
        if (str_starts_with($sql, 'DELETE')) {
            if (self::ownedEvent($sql, $row)) {
                unset($this->events[$id]);
            }
            return true;
        }
        if ($row !== null && ($row['expires_at'] <= time() || ($row['committed'] === 0 && $row['lease_until'] <= time()))) {
            preg_match("/SET token = '([^']+)', lease_until = (\d+), expires_at = (\d+)/", $sql, $claim);
            $this->events[$id] = ['token' => $claim[1], 'lease_until' => (int) $claim[2], 'expires_at' => (int) $claim[3], 'committed' => 0];
        }
        return true;
    }

    private function effect(string $sql): mixed
    {
        if (str_starts_with($sql, 'INSERT')) {
            [, $invoiceId, , $marker] = self::insertValues($sql);
            $this->effects[$invoiceId] = ['marker' => $marker];
            return true;
        }
        preg_match("/invoice_id = '([^']+)'/", $sql, $match);
        return $this->effects[$match[1]] ?? null;
    }

    private function countHistory(string $sql): int
    {
        preg_match("/history_data = '([^']+)'/", $sql, $match);
        return count(array_filter($this->history, static fn ($marker) => $marker === $match[1]));
    }

    private static function insertValues(string $sql): array
    {
        preg_match('/\bVALUES \(([^)]+)\)/', $sql, $match);
        return array_map(trim(...), str_getcsv($match[1], ',', "'", ''));
    }

    private static function ownedEvent(string $sql, ?array $row): bool
    {
        return $row !== null && $row['committed'] === 0 && str_contains($sql, "token = '" . $row['token'] . "'");
    }
}
