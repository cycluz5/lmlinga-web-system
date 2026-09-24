<?php

namespace App\Services\Offline;

use App\Models\OfflineSyncReceipt;
use App\Models\User;
use App\Support\Offline\OfflineSyncCode;
use App\Support\Offline\OfflineSyncException;

/**
 * Durable successful-operation receipts. Unique operation_id is the source of truth.
 */
final class OfflineIdempotencyService
{
    /**
     * @param  array<string, mixed>  $identities
     */
    public function recordApplied(
        string $operationId,
        User $actor,
        string $operationType,
        string $payloadHash,
        array $identities,
        array $result,
    ): OfflineSyncReceipt {
        return OfflineSyncReceipt::query()->create([
            'operation_id' => $operationId,
            'actor_user_id' => (int) $actor->getKey(),
            'operation_type' => $operationType,
            'payload_hash' => $payloadHash,
            'status' => OfflineSyncReceipt::STATUS_APPLIED,
            'household_pk' => $identities['household_pk'] ?? null,
            'resident_pk' => $identities['resident_pk'] ?? null,
            'household_no' => $identities['household_no'] ?? null,
            'member_no' => $identities['member_no'] ?? null,
            'result_json' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function replayOrReject(
        OfflineSyncReceipt $receipt,
        User $actor,
        string $operationType,
        string $payloadHash,
    ): array {
        if ((int) $receipt->actor_user_id !== (int) $actor->getKey()) {
            throw OfflineSyncException::actorMismatch();
        }

        if ($receipt->operation_type !== $operationType || $receipt->payload_hash !== $payloadHash) {
            throw OfflineSyncException::payloadMismatch();
        }

        $stored = is_array($receipt->result_json) ? $receipt->result_json : [];

        return array_merge($stored, [
            'ok' => true,
            'code' => OfflineSyncCode::ALREADY_APPLIED,
            'operation_id' => $receipt->operation_id,
        ]);
    }
}
