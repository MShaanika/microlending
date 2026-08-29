<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Correlation;
use App\Core\Events;
use App\Models\ExceptionRecord;

/**
 * Central operational-problem queue (Part 22-27) -- every module's
 * failures land here instead of staff hunting through separate screens.
 * Deliberately thin: creating/resolving an exception never modifies the
 * record it's about (Part 33 -- "Do not allow the Data Quality Engine
 * to silently modify financial history" applies just as much here).
 */
class ExceptionService
{
    public static function create(
        string $exceptionType,
        string $category,
        string $module,
        string $severity,
        string $description,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?int $ownerUserId = null,
        array $metadata = []
    ): int {
        $model = new ExceptionRecord();
        $id = $model->create([
            'exception_uuid' => self::uuid(),
            'correlation_id' => Correlation::id(),
            'exception_type' => $exceptionType,
            'category' => $category,
            'module' => $module,
            'severity' => $severity,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'owner_user_id' => $ownerUserId,
            'status' => $ownerUserId ? 'ASSIGNED' : 'OPEN',
            'description' => $description,
            'metadata' => $metadata ? json_encode($metadata) : null,
        ]);

        Audit::log('Create', 'Operations', "Exception #$id opened: $description", ['exception_id' => $id]);
        Events::fire('ExceptionCreated', ['exception_id' => $id, 'severity' => $severity]);

        return $id;
    }

    public static function assign(int $id, int $ownerUserId, int $actorUserId): void
    {
        $model = new ExceptionRecord();
        $model->assign($id, $ownerUserId);
        Audit::log('Assign', 'Operations', "Exception #$id assigned", ['exception_id' => $id, 'owner_user_id' => $ownerUserId]);
    }

    public static function investigate(int $id, int $actorUserId): void
    {
        (new ExceptionRecord())->updateStatus($id, 'INVESTIGATING');
        Audit::log('Investigate', 'Operations', "Exception #$id under investigation", ['exception_id' => $id]);
    }

    public static function addNote(int $id, int $actorUserId, string $note): void
    {
        (new ExceptionRecord())->addNote($id, $actorUserId, $note);
        Audit::log('Note', 'Operations', "Note added to exception #$id", ['exception_id' => $id]);
    }

    /** @param string $status One of RESOLVED, ACCEPTED_RISK, CLOSED (Part 27: resolution/root cause captured together). */
    public static function resolve(int $id, string $status, string $resolution, ?string $rootCause, int $resolvedBy): void
    {
        if (!in_array($status, ['RESOLVED', 'ACCEPTED_RISK', 'CLOSED'], true)) {
            throw new \RuntimeException('Invalid resolution status.');
        }
        (new ExceptionRecord())->resolve($id, $status, $resolution, $rootCause, $resolvedBy);
        Audit::log('Resolve', 'Operations', "Exception #$id resolved ($status): $resolution", ['exception_id' => $id, 'root_cause' => $rootCause]);
        Events::fire('ExceptionResolved', ['exception_id' => $id, 'status' => $status]);
    }

    public static function reopen(int $id, int $actorUserId, string $reason): void
    {
        (new ExceptionRecord())->reopen($id);
        (new ExceptionRecord())->addNote($id, $actorUserId, "Reopened: $reason");
        Audit::log('Reopen', 'Operations', "Exception #$id reopened: $reason", ['exception_id' => $id]);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
