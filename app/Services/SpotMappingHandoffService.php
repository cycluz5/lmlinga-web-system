<?php

namespace App\Services;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\UiRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * DB18-D — short-lived Spot Mapping → Environmental Health handoff.
 * DB19-C — may carry transient canonical household_type until first EH persist.
 *
 * Session stores server-selected household identity (household_id) and optional
 * transient household_type. Coordinates are always resolved from MySQL on consume.
 * Spot Mapping never writes household_type to households or EH profile tables.
 */
final class SpotMappingHandoffService
{
    public const SESSION_KEY = 'lml.spot_mapping_handoff.v1';

    public const ACTOR_SESSION_KEY = 'lml.spot_mapping_handoff.actor.v1';

    public const TTL_MINUTES = 10;

    public const INVALID_MESSAGE = 'Unable to continue because the household plot session is invalid or expired. Please plot the household again.';

    /**
     * Stable actor key for the current request.
     */
    public static function actorKey(): string
    {
        $user = Auth::user();
        if ($user !== null) {
            $id = data_get($user, 'id');
            if ($id !== null && (string) $id !== '') {
                return 'user:'.(string) $id;
            }
        }

        if (! session()->has(self::ACTOR_SESSION_KEY)) {
            session([self::ACTOR_SESSION_KEY => (string) Str::uuid()]);
        }

        $role = UiRole::current() ?? UiRole::shellRole();

        return 'demo:'.(string) $role.':'.(string) session(self::ACTOR_SESSION_KEY);
    }

    /**
     * Issue a single-use handoff token bound to an active Household row.
     *
     * @param  string|null  $uiHouseholdType  Spot Mapping UI value (HHTS / Non-HHTS); normalized before storage.
     */
    public function issueForHousehold(Household $household, ?string $uiHouseholdType = null): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plaintext);
        $now = now();
        $canonicalType = DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType($uiHouseholdType);

        $tokens = $this->allTokens();
        $tokens[$hash] = [
            'token_hash' => $hash,
            'actor_id' => self::actorKey(),
            'household_id' => (int) $household->getKey(),
            'household_no' => (string) $household->household_no,
            'household_type' => $canonicalType,
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            'consumed_at' => null,
        ];
        session([self::SESSION_KEY => $tokens]);

        return $plaintext;
    }

    /**
     * Consume a plaintext token and resolve the authoritative active Household
     * plus any transient canonical household_type from the handoff.
     *
     * @return array{household: Household, household_type: string|null}|null
     */
    public function consume(string $plaintextToken): ?array
    {
        $plaintextToken = trim($plaintextToken);
        if ($plaintextToken === '' || ! preg_match('/^[a-f0-9]{64}$/', $plaintextToken)) {
            return null;
        }

        $hash = hash('sha256', $plaintextToken);
        $tokens = $this->allTokens();
        $record = $tokens[$hash] ?? null;

        if (! is_array($record)) {
            return null;
        }

        if ((string) ($record['actor_id'] ?? '') !== self::actorKey()) {
            return null;
        }

        if (! empty($record['consumed_at'])) {
            return null;
        }

        $expiresAt = (string) ($record['expires_at'] ?? '');
        if ($expiresAt === '' || now()->greaterThan(new \DateTimeImmutable($expiresAt))) {
            return null;
        }

        $householdId = (int) ($record['household_id'] ?? 0);
        $tokenHouseholdNo = (string) ($record['household_no'] ?? '');
        if ($householdId <= 0 || $tokenHouseholdNo === '') {
            return null;
        }

        $keyName = (new Household)->getKeyName();
        $household = Household::query()
            ->where($keyName, $householdId)
            ->first();
        if ($household === null) {
            return null;
        }

        if ((string) $household->household_no !== $tokenHouseholdNo) {
            return null;
        }

        $record['consumed_at'] = now()->toIso8601String();
        $tokens[$hash] = $record;
        session([self::SESSION_KEY => $tokens]);

        $canonicalType = DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(
            isset($record['household_type']) ? (string) $record['household_type'] : null
        );

        return [
            'household' => $household,
            'household_type' => $canonicalType,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allTokens(): array
    {
        /** @var array<string, array<string, mixed>> $tokens */
        $tokens = session(self::SESSION_KEY, []);

        return is_array($tokens) ? $tokens : [];
    }
}
