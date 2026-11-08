<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

declare(strict_types=1);

namespace PaymosHikaShop;

use Joomla\Database\DatabaseInterface;
use Paymos\Webhook\CommitAwareEventStoreInterface;

defined('_JEXEC') or die;

/** The callback's reservation. A committed row acknowledges delivery; a lease does not. */
final class EventStore implements CommitAwareEventStoreInterface
{
    private const LEASE_SECONDS = 120;

    private ?string $eventId = null;
    private ?string $token = null;

    public function __construct(private readonly DatabaseInterface $db, private readonly int $methodId)
    {
        if ($methodId < 1) {
            throw new \InvalidArgumentException('paymos_event_method_invalid');
        }
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $id) === 1;
    }

    public function remember($eventId, $ttlSeconds): bool
    {
        if (!self::validId($eventId) || !is_int($ttlSeconds) || $ttlSeconds <= self::LEASE_SECONDS
            || $this->token !== null) {
            throw new \InvalidArgumentException('paymos_event_reservation_invalid');
        }

        $table = $this->db->quoteName(Store::EVENTS);
        $id = $this->db->quote($eventId);
        $token = bin2hex(random_bytes(16));
        $quotedToken = $this->db->quote($token);
        $now = time();
        $lease = $now + self::LEASE_SECONDS;
        $expiry = $now + $ttlSeconds;
        $where = "method_id = $this->methodId AND event_id = $id";

        // Bound pruning via the expiry index. A live lease always expires before its row.
        $this->write("DELETE FROM $table WHERE expires_at <= $now ORDER BY expires_at LIMIT 32");
        $this->write("INSERT INTO $table (method_id, event_id, token, lease_until, expires_at, committed)"
            . " VALUES ($this->methodId, $id, $quotedToken, $lease, $expiry, 0)"
            . ' ON DUPLICATE KEY UPDATE event_id = event_id');
        $this->write("UPDATE $table SET token = $quotedToken, lease_until = $lease, expires_at = $expiry, committed = 0"
            . " WHERE $where AND (expires_at <= $now OR (committed = 0 AND lease_until <= $now))");

        $owner = $this->db->setQuery("SELECT token FROM $table WHERE $where AND committed = 0")->loadResult();
        if ($owner !== $token) {
            return false;
        }

        $this->eventId = $eventId;
        $this->token = $token;
        return true;
    }

    public function isCommitted($eventId): bool
    {
        if (!self::validId($eventId)) {
            return false;
        }

        $table = $this->db->quoteName(Store::EVENTS);
        $id = $this->db->quote($eventId);
        $now = time();
        $result = $this->db->setQuery("SELECT committed FROM $table WHERE method_id = $this->methodId AND event_id = $id"
            . " AND committed = 1 AND expires_at > $now")->loadResult();
        return (string) $result === '1';
    }

    /** Call inside the invoice projection transaction. Keep the token for rollback cleanup. */
    public function commit(): void
    {
        if ($this->token === null) {
            throw new \RuntimeException('paymos_event_reservation_required');
        }

        $table = $this->db->quoteName(Store::EVENTS);
        $where = $this->ownedReservation();
        $now = time();
        $this->write("UPDATE $table SET committed = 1, lease_until = 0 WHERE $where AND lease_until > $now");
        if ($this->db->getAffectedRows() !== 1) {
            throw new \RuntimeException('paymos_event_reservation_lost');
        }
    }

    public function release(): void
    {
        if ($this->token !== null) {
            $table = $this->db->quoteName(Store::EVENTS);
            $this->write("DELETE FROM $table WHERE " . $this->ownedReservation());
        }
    }

    private function ownedReservation(): string
    {
        return "method_id = $this->methodId AND event_id = " . $this->db->quote($this->eventId)
            . ' AND token = ' . $this->db->quote($this->token) . ' AND committed = 0';
    }

    private function write(string $sql): void
    {
        if ($this->db->setQuery($sql)->execute() === false) {
            throw new \RuntimeException('paymos_event_write_failed');
        }
    }
}
