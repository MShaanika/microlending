<?php

namespace App\Core;

/**
 * Server-side duplicate-submission guard for financial writes, independent
 * of any frontend protection. A caller supplies a client-generated key
 * (crypto.randomUUID(), one per form instance) plus a stable operation-type
 * string; begin() either lets the caller proceed (first time seeing this
 * key+operation pair) or throws so the caller can hand back the exact same
 * response the original request already produced -- a resubmit (double
 * click, network retry, browser-back-then-resubmit) never re-executes the
 * business write.
 *
 * Usage inside a transaction-wrapped write (the common case for Tier 1):
 *   $this->loans->transaction(function () use ($key, $userId) {
 *       Idempotency::begin($key, 'loan.release', $userId);
 *       ... locked read, validation, writes ...
 *       Idempotency::complete($key, 'loan.release', 'REDIRECT', [...]);
 *   });
 * If the closure throws for any reason, the whole transaction (including
 * the PENDING row begin() just inserted) rolls back together -- no
 * explicit fail() call is needed in that case, the row simply never
 * existed as far as any later request can see.
 *
 * Usage OUTSIDE a transaction (e.g. Payment::recordAndAllocate() already
 * owns its own internal transaction) -- call fail() explicitly on the
 * catch path, since begin()'s insert already autocommitted and won't be
 * rolled back by the caller's own transaction:
 *   Idempotency::begin($key, 'payment.store', $userId);
 *   try {
 *       ... call the already-transactional write ...
 *       Idempotency::complete($key, 'payment.store', 'REDIRECT', [...]);
 *   } catch (\Throwable $e) {
 *       Idempotency::fail($key, 'payment.store');
 *       throw $e;
 *   }
 */
class Idempotency
{
    /** A PENDING row older than this is treated as an abandoned/crashed request, not a live one. */
    private const STUCK_PENDING_SECONDS = 120;

    /**
     * @throws IdempotencyReplayException if this exact operation already completed -- caller should
     *         replay the stored response rather than doing any work.
     * @throws IdempotencyBusyException if a concurrent request for the same key is still in flight.
     */
    public static function begin(string $key, string $operationType, ?int $userId): void
    {
        $db = Database::connection();
        $expiresAt = date('Y-m-d H:i:s', time() + self::ttlHours() * 3600);

        try {
            $stmt = $db->prepare(
                "INSERT INTO idempotency_keys (idempotency_key, operation_type, user_id, status, locked_at, expires_at)
                 VALUES (?, ?, ?, 'PENDING', NOW(), ?)"
            );
            $stmt->execute([$key, $operationType, $userId, $expiresAt]);
            return; // proceed -- first time seeing this key+operation pair
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e; // not a duplicate-key violation -- a real DB error, let it propagate
            }
        }

        $row = $db->prepare('SELECT * FROM idempotency_keys WHERE idempotency_key = ? AND operation_type = ?');
        $row->execute([$key, $operationType]);
        $existing = $row->fetch();

        if (!$existing) {
            // Vanished between the failed insert and this select (another
            // request's rollback raced us) -- safe to just proceed.
            return;
        }

        if ($existing['status'] === 'COMPLETED') {
            if (strtotime($existing['expires_at']) > time()) {
                throw new IdempotencyReplayException(
                    (string) $existing['response_type'],
                    (array) json_decode((string) $existing['response_payload'], true)
                );
            }
            self::deleteRow($db, (int) $existing['id']);
            self::begin($key, $operationType, $userId);
            return;
        }

        if ($existing['status'] === 'PENDING') {
            $lockedAt = strtotime($existing['locked_at'] ?: $existing['created_at']);
            if (time() - $lockedAt > self::STUCK_PENDING_SECONDS) {
                self::deleteRow($db, (int) $existing['id']);
                self::begin($key, $operationType, $userId);
                return;
            }
            // A genuinely concurrent collision (not a stale/stuck row) --
            // visibility only in Phase 1, no rule attached. A single benign
            // double-click replay is NOT logged here (see the COMPLETED
            // branch above) since that's the expected, common case this
            // whole mechanism exists to handle gracefully -- logging it as a
            // signal would just be noise.
            SecurityEvent::record('REPLAY_ABUSE_SUSPECTED', 'Low', [
                'user_id' => $userId,
                'description' => 'Concurrent duplicate request for ' . $operationType,
            ]);
            throw new IdempotencyBusyException();
        }

        // FAILED rows are always deleted by fail(), so this branch is only
        // reached defensively (e.g. a row left behind by a bug) -- treat as
        // abandoned and let the request through.
        self::deleteRow($db, (int) $existing['id']);
        self::begin($key, $operationType, $userId);
    }

    public static function complete(string $key, string $operationType, string $responseType, array $responsePayload): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE idempotency_keys SET status = 'COMPLETED', response_type = ?, response_payload = ?, completed_at = NOW()
             WHERE idempotency_key = ? AND operation_type = ?"
        );
        $stmt->execute([$responseType, json_encode($responsePayload), $key, $operationType]);
    }

    /** Deletes the PENDING row so a genuine retry after a transient failure can run again immediately. */
    public static function fail(string $key, string $operationType): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ? AND operation_type = ?');
        $stmt->execute([$key, $operationType]);
    }

    private static function deleteRow(\PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM idempotency_keys WHERE id = ?')->execute([$id]);
    }

    private static function ttlHours(): int
    {
        $value = Database::connection()
            ->query("SELECT setting_value FROM system_settings WHERE setting_key = 'idempotency_ttl_hours'")
            ->fetchColumn();
        return $value !== false ? max(1, (int) $value) : 24;
    }
}

/** Thrown by Idempotency::begin() when the operation already completed -- carries the original response to replay. */
class IdempotencyReplayException extends \RuntimeException
{
    public function __construct(public string $responseType, public array $payload)
    {
        parent::__construct('Idempotent replay');
    }
}

/** Thrown by Idempotency::begin() when a concurrent request for the same key is still being processed. */
class IdempotencyBusyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A request for this operation is already being processed.');
    }
}
