<?php

// Copyright (c) 2026 Paymos Labs
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace PaymosHikaShop\Tests;

use Joomla\Database\Mysqli\MysqliDriver;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Http\TransportInterface;
use PaymosHikaShop\ConnectAdmin;
use PaymosHikaShop\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

final class ConnectAdminTest extends TestCase
{
    private const SOURCE = 'https://store.example/shop';
    private const APPROVAL = 'https://app.paymos.io/connect/cms/verify?user_code=ABCD-1234';
    private const SECRET = 'test-joomla-installation-secret';
    private const CONTEXT = [
        'administrator' => true, 'method' => 'POST', 'user_id' => 23, 'session_id' => 'session-first',
        'csrf' => true, 'can_configure' => true, 'can_manage_orders' => true,
    ];

    #[DataProvider('invalidContexts')]
    public function testUnauthorizedRequestsCannotReachStoreOrTransport(array $overrides): void
    {
        [$admin, $transport, $fixture] = $this->fixture();
        $result = $admin->dispatch(['action' => 'start', 'method_id' => '17'], $overrides + self::CONTEXT);

        self::assertSame(['ok' => false, 'status' => 'forbidden'], $result);
        self::assertSame([], $fixture->queries);
        self::assertSame([], $transport->requests());
    }

    public static function invalidContexts(): iterable
    {
        yield 'site visitor' => [['administrator' => false]];
        yield 'GET' => [['method' => 'GET']];
        yield 'guest' => [['user_id' => 0]];
        yield 'no live session' => [['session_id' => '']];
        yield 'wrong CSRF' => [['csrf' => false]];
        yield 'read-only administrator' => [['can_configure' => false]];
    }

    public function testOnlySavedNativePaymosMethodCanStart(): void
    {
        [$admin, $transport, $fixture] = $this->fixture();
        foreach (['18', '17x', '0', ['17']] as $id) {
            self::assertSame(['ok' => false, 'status' => 'not_found'],
                $admin->dispatch(['action' => 'start', 'method_id' => $id], self::CONTEXT));
        }
        self::assertSame(0, $fixture->writes);
        self::assertSame([], $transport->requests());
    }

    public function testApprovalPersistsBothEnvironmentsAndPinsMethodAndStore(): void
    {
        [$admin, $transport, $fixture, $store] = $this->fixture([
            $this->deviceResponse(),
            new HttpResponse(400, '{"error":"authorization_pending"}', []),
            $this->credentialResponse(),
        ], 'https://STORE.Example:443/shop/');
        $start = $this->dispatch($admin, 'start');
        self::assertSame([
            'ok' => true, 'status' => 'waiting', 'user_code' => 'ABCD-1234',
            'verification_url' => self::APPROVAL . '&cms=hikashop&source_url=https%3A%2F%2Fstore.example%2Fshop',
            'interval' => 5,
        ], $start);
        self::assertStringNotContainsString('hidden-device-code', $fixture->connections[17]['device_envelope']);
        self::assertSame([], $admin->credentials(17));
        self::assertSame('sandbox', $admin->mode(17));
        self::assertCount(1, $transport->requests());

        $fixture->time += 5;
        $writes = $fixture->writes;
        foreach ([['user_id' => 24], ['session_id' => 'another-session']] as $override) {
            self::assertSame(['ok' => false, 'status' => 'forbidden'], $this->dispatch($admin, 'poll', [], $override));
        }
        self::assertSame($writes, $fixture->writes);
        self::assertCount(1, $transport->requests());

        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 16], $this->dispatch($admin, 'poll'));
        $fixture->time += 1;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 15], $this->dispatch($admin, 'poll'));
        self::assertCount(2, $transport->requests());

        $fixture->time += 15;
        self::assertSame(['ok' => true, 'status' => 'connected'], $this->dispatch($admin, 'poll'));
        self::assertNull($fixture->connections[17]['device_envelope']);
        self::assertStringNotContainsString('sk_test_fixture', $fixture->connections[17]['credentials_envelope']);
        self::assertStringNotContainsString('sk_live_fixture', $fixture->connections[17]['credentials_envelope']);
        self::assertSame('sk_test_fixture', $admin->credentials(17)['sandbox']['api_secret']);
        self::assertSame('sk_live_fixture', $admin->credentials(17)['live']['api_secret']);
        self::assertSame([], $admin->credentials(19));
        self::assertSame('sandbox', $admin->mode(17));

        self::assertSame(['ok' => true, 'status' => 'mode_saved'], $this->dispatch($admin, 'mode', ['mode' => 'live']));
        self::assertSame('live', $admin->mode(17));
        self::assertSame('sandbox', $admin->mode(19));
        self::assertCount(3, $transport->requests());
        self::assertSame('https://app.paymos.io/connect/token', $transport->requests()[2]['url']);

        $clone = new ConnectAdmin($store, 'https://clone.example/shop', self::SECRET);
        $this->expectExceptionMessage('paymos_store_binding_changed');
        $clone->credentials(17);
    }

    #[DataProvider('untrustedApprovals')]
    public function testUntrustedApprovalUrlNeverReachesBrowserOrStorage(string $url): void
    {
        [$admin, $transport, $fixture] = $this->fixture([$this->deviceResponse($url)]);
        self::assertSame(['ok' => false, 'status' => 'unavailable'], $this->dispatch($admin, 'start'));
        self::assertSame([], $fixture->connections);
        self::assertSame(0, $fixture->writes);
        self::assertCount(1, $transport->requests());
    }

    public static function untrustedApprovals(): iterable
    {
        yield 'wrong host' => [str_replace('app.paymos.io', 'app.paymos.io.attacker.example', self::APPROVAL)];
        yield 'scheme' => [str_replace('https://', 'http://', self::APPROVAL)];
        yield 'port' => [str_replace('app.paymos.io', 'app.paymos.io:444', self::APPROVAL)];
        yield 'userinfo' => [str_replace('app.paymos.io', 'attacker@app.paymos.io', self::APPROVAL)];
        yield 'wrong path' => [str_replace('/verify', '/elsewhere', self::APPROVAL)];
        yield 'duplicate binding' => [self::APPROVAL . '&cms=woocommerce'];
        yield 'fragment' => [self::APPROVAL . '#fragment'];
    }

    #[DataProvider('wrongScopes')]
    public function testWrongBootstrapScopeCannotStoreCredentials(string $plugin, string $source): void
    {
        [$admin, , $fixture] = $this->fixture([$this->deviceResponse(), $this->credentialResponse($plugin, $source)]);
        self::assertTrue($this->dispatch($admin, 'start')['ok']);
        $fixture->time += 5;
        self::assertSame(['ok' => false, 'status' => 'binding_mismatch'], $this->dispatch($admin, 'poll'));
        self::assertNull($fixture->connections[17]['credentials_envelope']);
        self::assertNull($fixture->connections[17]['device_envelope']);
        self::assertSame([], $admin->credentials(17));
    }

    public static function wrongScopes(): iterable
    {
        yield 'other integration' => ['woocommerce', self::SOURCE];
        yield 'same host, other store path' => ['hikashop', 'https://store.example/other-shop'];
        yield 'HTTP instead of HTTPS' => ['hikashop', 'http://store.example/shop'];
    }

    public function testSlowDownPersistsCadenceAcrossPendingAndDirectPolls(): void
    {
        [$admin, $transport, $fixture] = $this->fixture([
            $this->deviceResponse(),
            new HttpResponse(400, '{"error":"slow_down"}', []),
            new HttpResponse(400, '{"error":"authorization_pending"}', []),
        ]);
        $this->dispatch($admin, 'start');
        $fixture->time += 5;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 21], $this->dispatch($admin, 'poll'));

        $fixture->time += 16;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 5], $this->dispatch($admin, 'poll'));
        self::assertCount(2, $transport->requests());

        $fixture->time += 5;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 21], $this->dispatch($admin, 'poll'));
        $fixture->time += 16;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 5], $this->dispatch($admin, 'poll'));
        self::assertCount(3, $transport->requests());
    }

    public function testConnectionTimeoutPersistsExponentialBackoff(): void
    {
        $requests = 0;
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('request')->willReturnCallback(function () use (&$requests): HttpResponse {
            if (++$requests === 1) {
                return $this->deviceResponse();
            }
            if ($requests <= 3) {
                throw new \RuntimeException('Paymos HTTP error: Operation timed out after 15000 milliseconds');
            }

            return new HttpResponse(400, '{"error":"authorization_pending"}', []);
        });
        [$admin, , $fixture] = $this->fixture($transport);
        $this->dispatch($admin, 'start');
        $fixture->time += 5;

        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 32], $this->dispatch($admin, 'poll'));
        $fixture->time += 16;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 16], $this->dispatch($admin, 'poll'));
        self::assertSame(2, $requests);

        $fixture->time += 16;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 64], $this->dispatch($admin, 'poll'));
        $fixture->time += 32;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 32], $this->dispatch($admin, 'poll'));
        self::assertSame(3, $requests);

        $fixture->time += 32;
        self::assertSame(['ok' => true, 'status' => 'waiting', 'interval' => 64], $this->dispatch($admin, 'poll'));
        self::assertSame(4, $requests);
    }

    public function testOldSlowDownCannotReplaceNewerFlowWithSameUserAndMethod(): void
    {
        $admin = null;
        $requests = 0;
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('request')->willReturnCallback(function () use (&$admin, &$requests): HttpResponse {
            if (++$requests === 2) {
                self::assertTrue($this->dispatch($admin, 'start')['ok']);
                return new HttpResponse(400, '{"error":"slow_down"}', []);
            }

            return $this->deviceResponse();
        });
        [$admin, , $fixture] = $this->fixture($transport);
        $this->dispatch($admin, 'start');
        $oldFlow = $fixture->connections[17]['device_flow_id'];
        $fixture->time += 5;

        self::assertSame(['ok' => false, 'status' => 'device_expired'], $this->dispatch($admin, 'poll'));
        self::assertNotSame($oldFlow, $fixture->connections[17]['device_flow_id']);
        self::assertSame(1010, $fixture->connections[17]['device_poll_at']);
        self::assertSame(3, $requests);
    }

    public function testDeferRequiresUnchangedReservationAndUnexpiredFlow(): void
    {
        [$admin, , $fixture, $store] = $this->fixture([$this->deviceResponse()]);
        $this->dispatch($admin, 'start');
        $row = $fixture->connections[17];
        $flow = $row['device_flow_id'];
        self::assertFalse($store->deferDevicePoll(17, 23, $flow, 1004, 1005, 1026, 'stale-envelope'));
        self::assertFalse($store->deferDevicePoll(17, 23, $flow, 1005, 1600, 1621, 'expired-envelope'));
        self::assertSame($row, $fixture->connections[17]);
    }

    public function testExpiredDeviceIsClearedBeforePolling(): void
    {
        [$admin, $transport, $fixture] = $this->fixture([$this->deviceResponse()]);
        self::assertTrue($this->dispatch($admin, 'start')['ok']);
        $fixture->time += 600;
        self::assertSame(['ok' => false, 'status' => 'device_expired'], $this->dispatch($admin, 'poll'));
        self::assertNull($fixture->connections[17]['device_envelope']);
        self::assertCount(1, $transport->requests());
    }

    public function testRefundDelegationRequiresOrderRightsAndExactMethodBinding(): void
    {
        [$admin, $transport] = $this->fixture();
        $called = [];
        $refund = static function (string $action, int $methodId, int $orderId, array $input) use (&$called): array {
            $called[] = [$action, $methodId, $orderId, $input];
            return ['status' => 'pending', 'operation_id' => '11111111-2222-4333-8444-555555555555',
                'api_secret' => 'never-in-the-response'];
        };
        $request = ['action' => 'refund', 'method_id' => '17', 'order_id' => '42',
            'mode' => 'full', 'request_token' => '1111111122222222', 'recipient' => 'untrusted-wallet'];

        self::assertSame(['ok' => false, 'status' => 'forbidden'],
            $admin->dispatch($request, ['can_manage_orders' => false] + self::CONTEXT, $refund));
        self::assertSame(['ok' => false, 'status' => 'not_found'],
            $admin->dispatch(['order_id' => '43'] + $request, self::CONTEXT, $refund));
        self::assertSame(['ok' => false, 'status' => 'invalid_request'],
            $admin->dispatch(['mode' => 'partial'] + $request, self::CONTEXT, $refund));
        self::assertSame([], $called);

        self::assertSame(['ok' => true, 'status' => 'pending', 'operation_id' => '11111111-2222-4333-8444-555555555555'],
            $admin->dispatch($request, ['can_configure' => false] + self::CONTEXT, $refund));
        self::assertSame([['refund', 17, 42, ['mode' => 'full', 'request_token' => '1111111122222222']]], $called);
        self::assertSame([], $transport->requests());
    }

    private function dispatch(ConnectAdmin $admin, string $action, array $input = [], array $context = []): array
    {
        return $admin->dispatch($input + ['action' => $action, 'method_id' => '17'], $context + self::CONTEXT);
    }

    private function deviceResponse(string $url = self::APPROVAL): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'device_code' => 'hidden-device-code', 'user_code' => 'ABCD-1234',
            'verification_uri_complete' => $url, 'expires_in' => 600, 'interval' => 5,
        ], JSON_THROW_ON_ERROR), []);
    }

    private function credentialResponse(string $plugin = 'hikashop', string $source = self::SOURCE): HttpResponse
    {
        $environments = [];
        foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
            $environments[$environment] = [
                'api_key' => "pk_{$prefix}_fixture", 'api_secret' => "sk_{$prefix}_fixture",
                'project_id' => 'prj_fixture', 'webhook_secret' => "whsec_{$prefix}_fixture",
            ];
        }
        return new HttpResponse(200, json_encode([
            'access_token' => 'never-in-the-response',
            'paymos_config' => ['plugin' => $plugin, 'source_url' => $source, 'environments' => $environments],
        ], JSON_THROW_ON_ERROR), []);
    }

    private function fixture(array|TransportInterface $responses = [], string $source = self::SOURCE): array
    {
        $fixture = (object) ['sql' => '', 'queries' => [], 'writes' => 0, 'affected' => 0, 'time' => 1000, 'connections' => []];
        $db = $this->getMockBuilder(MysqliDriver::class)->disableOriginalConstructor()->onlyMethods([
            'setQuery', 'quote', 'quoteName', 'loadAssoc', 'loadResult', 'execute', 'getAffectedRows',
        ])->getMock();
        $db->method('setQuery')->willReturnCallback(static function ($sql) use ($db, $fixture) {
            $fixture->sql = $sql;
            $fixture->queries[] = $sql;
            return $db;
        });
        $db->method('quote')->willReturnCallback(static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'");
        $db->method('quoteName')->willReturnCallback(static fn (string $name): string => chr(96) . $name . chr(96));
        $db->method('loadAssoc')->willReturnCallback(static function () use ($fixture): ?array {
            $sql = $fixture->sql;
            if (preg_match('/hikashop_payment. WHERE payment_id = (\d+)/', $sql, $m)) {
                $id = (int) $m[1];
                return ['payment_id' => $id, 'payment_type' => $id === 18 ? 'paypal' : 'paymos', 'payment_published' => 1];
            }
            if (preg_match('/hikashop_paymos_connection. WHERE method_id = (\d+)/', $sql, $m)) {
                return $fixture->connections[(int) $m[1]] ?? null;
            }
            if (preg_match('/hikashop_order. WHERE order_id = (42|43)$/', $sql, $m)) {
                return ['order_id' => (int) $m[1], 'order_payment_id' => $m[1] === '42' ? '17' : '19',
                    'order_payment_method' => 'paymos', 'order_status' => 'confirmed', 'order_full_price' => '12.34000',
                    'order_currency_id' => 1];
            }
            throw new \LogicException('Unexpected fixture query');
        });
        $db->method('loadResult')->willReturnCallback(static fn (): string =>
            str_contains($fixture->sql, 'installation_id') ? '11111111-2222-4333-8444-555555555555' : 'EUR');
        $db->method('execute')->willReturnCallback(function () use ($fixture): bool {
            $this->writeFixture($fixture);
            return true;
        });
        $db->method('getAffectedRows')->willReturnCallback(static fn (): int => $fixture->affected);

        $transport = is_array($responses) ? new MockTransport($responses) : $responses;
        $store = new Store($db);
        $client = new DeviceConnectClient('https://app.paymos.io', $transport);
        return [new ConnectAdmin($store, $source, self::SECRET, $client, static fn (): int => $fixture->time),
            $transport, $fixture, $store];
    }

    private function writeFixture(object $fixture): void
    {
        $sql = $fixture->sql;
        $fixture->affected = 0;
        if (preg_match("/device_poll_at\\) VALUES \\((\\d+), (\\d+), '([^']+)', '([^']+)', (\\d+), (\\d+)\\)/", $sql, $m)) {
            $id = (int) $m[1];
            $fixture->connections[$id] = [
                'method_id' => $id, 'device_user_id' => (int) $m[2], 'device_flow_id' => $m[3], 'device_envelope' => $m[4],
                'device_expires_at' => (int) $m[5], 'device_poll_at' => (int) $m[6],
            ] + ($fixture->connections[$id] ?? ['mode' => 'sandbox', 'credentials_envelope' => null]);
        } elseif (preg_match("/WHERE method_id = (\\d+) AND device_user_id = (\\d+) AND device_flow_id = '([^']+)'/", $sql, $m)) {
            $id = (int) $m[1];
            $row = $fixture->connections[$id] ?? null;
            if ($row === null || $row['device_user_id'] !== (int) $m[2] || $row['device_flow_id'] !== $m[3]) {
                return;
            }
            if (preg_match('/AND device_expires_at > (\d+)/', $sql, $expiry) && $row['device_expires_at'] <= (int) $expiry[1]) {
                return;
            }
            if (preg_match('/SET device_poll_at = (\d+)/', $sql, $poll)) {
                if (!preg_match('/AND device_poll_at = (\d+)/', $sql, $expected) || $row['device_poll_at'] !== (int) $expected[1]) {
                    return;
                }
                if (preg_match('/AND device_poll_at <= (\d+)/', $sql, $eligible) && $row['device_poll_at'] > (int) $eligible[1]) {
                    return;
                }
                if (preg_match("/, device_envelope = '([^']+)'/", $sql, $encrypted)) {
                    $row['device_envelope'] = $encrypted[1];
                }
                $row['device_poll_at'] = (int) $poll[1];
            } else {
                if (preg_match("/SET credentials_envelope = '([^']+)'/", $sql, $encrypted)) {
                    $row['credentials_envelope'] = $encrypted[1];
                    $row['mode'] = 'sandbox';
                }
                foreach (['device_user_id', 'device_flow_id', 'device_envelope', 'device_expires_at', 'device_poll_at'] as $field) {
                    $row[$field] = null;
                }
            }
            $fixture->connections[$id] = $row;
        } elseif (preg_match("/SET mode = '(live|sandbox)' WHERE method_id = (\\d+)/", $sql, $m)) {
            $fixture->connections[(int) $m[2]]['mode'] = $m[1];
        } else {
            throw new \LogicException('Unexpected fixture write');
        }

        $fixture->writes++;
        $fixture->affected = 1;
    }
}
