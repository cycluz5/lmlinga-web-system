<?php

namespace App\Services\Offline;

use App\Http\Requests\Admin\UpdateHealthWorkerRequest;
use App\Http\Requests\StoreHouseholdRequest;
use App\Http\Requests\StoreHouseholdWaterSupplyRequest;
use App\Http\Requests\StoreHouseholdWaterSupplyStep2Request;
use App\Http\Requests\StoreHouseholdWaterSupplyStep3Request;
use App\Http\Requests\StoreHouseholdWaterSupplyStep4Request;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Requests\StoreSpotMappingHouseholdRequest;
use App\Http\Requests\UpdateHouseholdAmenitiesRequest;
use App\Http\Requests\UpdateHouseholdRequest;
use App\Http\Requests\UpdateResidentRequest;
use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\Resident;
use App\Models\User;
use App\Services\HealthWorkerAccountService;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Services\HouseholdService;
use App\Services\ResidentService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\HealthWorkerUiCatalog;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineInnerRequestValidator;
use App\Support\Offline\OfflineOperationType;
use App\Support\Offline\OfflinePayloadCanonicalizer;
use App\Support\Offline\OfflineSyncCode;
use App\Support\Offline\OfflineSyncException;
use App\Support\OpaqueId;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single-operation offline sync dispatcher.
 *
 * Reuses HouseholdService / ResidentService / HealthWorkerAccountService
 * and existing FormRequest rules. Plot sync never calls SpotMappingHandoffService.
 */
final class OfflineSyncService
{
    public function __construct(
        private readonly HouseholdService $households,
        private readonly ResidentService $residents,
        private readonly HealthWorkerAccountService $healthWorkers,
        private readonly HouseholdEnvironmentalProfileService $environmentalProfiles,
        private readonly OfflineHealthServiceWriter $healthWrites,
        private readonly OfflineIdempotencyService $idempotency,
    ) {}

    /**
     * @param  array{
     *     operation_id: string,
     *     schema_version: int,
     *     operation_type: string,
     *     payload: array<string, mixed>,
     *     base_snapshot: array<string, mixed>|null,
     *     parent_server: array<string, mixed>|null
     * }  $envelope
     * @return array<string, mixed>
     */
    public function process(User $actor, array $envelope): array
    {
        if (! $actor->isActive()) {
            throw OfflineSyncException::accountInactive();
        }

        $role = StaffRole::normalize($actor->role);
        if ($role === null) {
            throw OfflineSyncException::forbidden();
        }

        $operationId = $envelope['operation_id'];
        $operationType = $envelope['operation_type'];
        $payload = OfflinePayloadCanonicalizer::semanticPayload($envelope['payload']);
        $payloadHash = OfflinePayloadCanonicalizer::operationHash($operationType, $payload);
        $baseSnapshot = $envelope['base_snapshot'];
        $parentServer = $envelope['parent_server'];

        try {
            return DB::transaction(function () use (
                $actor,
                $operationId,
                $operationType,
                $payload,
                $payloadHash,
                $baseSnapshot,
                $parentServer,
            ): array {
                $existing = OfflineSyncReceipt::query()
                    ->where('operation_id', $operationId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->idempotency->replayOrReject(
                        $existing,
                        $actor,
                        $operationType,
                        $payloadHash,
                    );
                }

                $applied = $this->dispatch(
                    $actor,
                    $operationType,
                    $payload,
                    $baseSnapshot,
                    $parentServer,
                );

                $applied['identities'] = $this->withUrlKeys((array) ($applied['identities'] ?? []));
                $applied['body']['url_keys'] = array_intersect_key(
                    $applied['identities'],
                    array_flip(['household_no', 'household_key', 'member_no', 'member_key', 'resident_pk', 'resident_key'])
                );

                $result = $this->successBody($operationId, $applied);

                $this->idempotency->recordApplied(
                    $operationId,
                    $actor,
                    $operationType,
                    $payloadHash,
                    $applied['identities'],
                    $result,
                );

                return $result;
            });
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'offline_sync_receipts')) {
                throw $e;
            }

            $existing = OfflineSyncReceipt::query()
                ->where('operation_id', $operationId)
                ->first();

            if ($existing === null) {
                throw OfflineSyncException::retryable();
            }

            return $this->idempotency->replayOrReject(
                $existing,
                $actor,
                $operationType,
                $payloadHash,
            );
        } catch (ValidationException $e) {
            throw OfflineSyncException::validationFailed($e->errors(), $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function dispatch(
        User $actor,
        string $operationType,
        array $payload,
        ?array $baseSnapshot,
        ?array $parentServer,
    ): array {
        return match ($operationType) {
            OfflineOperationType::HOUSEHOLD_CREATE => $this->householdCreate($payload),
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD => $this->plotHouseholdWithHead($payload),
            OfflineOperationType::RESIDENT_CREATE => $this->residentCreate($payload, $parentServer),
            OfflineOperationType::HOUSEHOLD_UPDATE => $this->householdUpdate($payload, $baseSnapshot, $parentServer),
            OfflineOperationType::RESIDENT_UPDATE => $this->residentUpdate($payload, $baseSnapshot, $parentServer),
            OfflineOperationType::HEALTH_WORKER_UPDATE => $this->healthWorkerUpdate(
                $actor,
                $payload,
                $baseSnapshot,
                $parentServer,
            ),
            OfflineOperationType::HOUSEHOLD_AMENITIES_UPDATE => $this->householdAmenitiesUpdate(
                $payload,
                $baseSnapshot,
                $parentServer,
            ),
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE => $this->environmentalWaterSupplyUpdate(
                $payload,
                $baseSnapshot,
                $parentServer,
            ),
            OfflineOperationType::HEALTH_SERVICE_WRITE => $this->healthServiceWrite(
                $payload,
                $parentServer,
            ),
            default => throw OfflineSyncException::unknownOperation(),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function householdCreate(array $payload): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'resident_id', 'member_no']);

        $validated = OfflineInnerRequestValidator::validate(StoreHouseholdRequest::class, $payload);
        $household = $this->households->create($validated);

        return $this->householdIdentityResult($household);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function plotHouseholdWithHead(array $payload): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'resident_id', 'member_no']);

        /** @var StoreSpotMappingHouseholdRequest $request */
        $request = OfflineInnerRequestValidator::request(StoreSpotMappingHouseholdRequest::class, $payload);
        $created = $this->households->createWithHead(
            $request->householdAttributes(),
            $request->headAttributes(),
            $this->residents,
        );

        $household = $created['household'];
        $resident = $created['resident'];
        $householdBody = $this->householdIdentity($household);
        $residentBody = $this->residentIdentity($resident);

        return [
            'identities' => [
                'household_pk' => $householdBody['id'],
                'household_no' => $householdBody['household_no'],
                'resident_pk' => $residentBody['id'],
                'member_no' => $residentBody['member_no'],
            ],
            'body' => [
                'household' => $householdBody,
                'resident' => $residentBody,
                'environmental_health' => [
                    'started' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    /**
     * Public URL codes for records the sync just created/updated, so the browser can link to them.
     *
     * @param  array<string, mixed>  $identities
     * @return array<string, mixed>
     */
    private function withUrlKeys(array $identities): array
    {
        if (! empty($identities['household_no'])) {
            $identities['household_key'] = OpaqueId::forUrl('h', (string) $identities['household_no']);
        }
        if (! empty($identities['member_no'])) {
            $identities['member_key'] = OpaqueId::forUrl('m', (string) $identities['member_no']);
        }
        if (! empty($identities['resident_pk'])) {
            $identities['resident_key'] = OpaqueId::forUrl('r', (string) $identities['resident_pk']);
        }

        return $identities;
    }

    private function residentCreate(array $payload, ?array $parentServer): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'resident_id', 'member_no']);
        unset($payload['client_local_member_id']);

        $household = $this->resolveHouseholdTarget($parentServer, true);
        $validated = OfflineInnerRequestValidator::validate(StoreResidentRequest::class, $payload);
        $resident = $this->residents->create($household, $validated);

        $householdBody = $this->householdIdentity($household);
        $residentBody = $this->residentIdentity($resident);
        $residentBody['field_hash'] = OfflineFieldHasher::resident($resident);

        return [
            'identities' => [
                'household_pk' => $householdBody['id'],
                'household_no' => $householdBody['household_no'],
                'resident_pk' => $residentBody['id'],
                'member_no' => $residentBody['member_no'],
                'field_hash' => $residentBody['field_hash'],
            ],
            'body' => [
                'household' => $householdBody,
                'resident' => $residentBody,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function householdUpdate(array $payload, ?array $baseSnapshot, ?array $parentServer): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'household_no']);

        $household = $this->resolveHouseholdTarget($parentServer, true);
        $this->assertFieldHash($baseSnapshot, OfflineFieldHasher::household($household));

        $validated = OfflineInnerRequestValidator::validate(UpdateHouseholdRequest::class, $payload);
        $updated = $this->households->update($household, $validated);

        return $this->householdIdentityResult($updated);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function residentUpdate(array $payload, ?array $baseSnapshot, ?array $parentServer): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'resident_id', 'member_no']);

        $resident = $this->resolveResidentTarget($parentServer);
        $this->assertFieldHash($baseSnapshot, OfflineFieldHasher::resident($resident));

        $validated = OfflineInnerRequestValidator::validate(UpdateResidentRequest::class, $payload);
        $updated = $this->residents->update($resident, $validated);

        $household = $updated->household ?? Household::query()->whereKey($updated->household_id)->first();
        $householdBody = $household !== null ? $this->householdIdentity($household) : null;
        $residentBody = $this->residentIdentity($updated);
        $residentBody['field_hash'] = OfflineFieldHasher::resident($updated);

        return [
            'identities' => [
                'household_pk' => $householdBody['id'] ?? null,
                'household_no' => $householdBody['household_no'] ?? null,
                'resident_pk' => $residentBody['id'],
                'member_no' => $residentBody['member_no'],
                'field_hash' => $residentBody['field_hash'],
            ],
            'body' => array_filter([
                'household' => $householdBody,
                'resident' => $residentBody,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function healthWorkerUpdate(
        User $actor,
        array $payload,
        ?array $baseSnapshot,
        ?array $parentServer,
    ): array {
        if (StaffRole::normalize($actor->role) !== StaffRole::ADMIN) {
            throw OfflineSyncException::forbidden();
        }

        $this->rejectClientAuthoritativeIds($payload, [
            'id',
            'user_id',
            'worker_id',
            'appointment_id',
            'worker_appointment_zone_id',
            'zone_id',
        ]);
        $this->rejectOfflineCredentialOrFileFields($payload);

        $user = $this->resolveHealthWorkerTarget($parentServer);
        $this->assertFieldHash($baseSnapshot, OfflineFieldHasher::healthWorker($user));

        unset(
            $payload['hw_password'],
            $payload['hw_password_confirmation'],
            $payload['password'],
            $payload['password_confirmation'],
            $payload['hw_photo'],
            $payload['hw_remove_photo'],
        );

        $validated = OfflineInnerRequestValidator::validate(
            UpdateHealthWorkerRequest::class,
            $payload,
            ['id' => (string) $user->getKey()],
        );

        unset(
            $validated['hw_password'],
            $validated['hw_password_confirmation'],
            $validated['hw_photo'],
            $validated['hw_remove_photo'],
        );

        $updated = $this->healthWorkers->update($user, $validated);

        return [
            'identities' => [
                'household_pk' => null,
                'household_no' => null,
                'resident_pk' => null,
                'member_no' => null,
            ],
            'body' => [
                'user' => [
                    'id' => (int) $updated->getKey(),
                    'email' => (string) $updated->email,
                    'username' => (string) ($updated->username ?? ''),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function householdAmenitiesUpdate(
        array $payload,
        ?array $baseSnapshot,
        ?array $parentServer,
    ): array {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'household_environmental_profile_id']);

        $household = $this->resolveHouseholdTarget($parentServer, true);
        $this->assertEnvironmentalHashIfPresent($household, $baseSnapshot);

        $validated = OfflineInnerRequestValidator::validate(
            UpdateHouseholdAmenitiesRequest::class,
            $payload,
            ['householdNo' => (string) $household->household_no],
        );

        $this->environmentalProfiles->saveAll($household, $validated);

        return $this->householdIdentityResult($household->fresh() ?? $household);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function environmentalWaterSupplyUpdate(
        array $payload,
        ?array $baseSnapshot,
        ?array $parentServer,
    ): array {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'household_environmental_profile_id']);

        $household = $this->resolveHouseholdTarget($parentServer, true);
        $step = (int) ($payload['_eh_step'] ?? 0);
        unset($payload['_eh_step']);

        DemoHouseholdWaterSupply::linkFromHousehold($household);

        // Wizard steps 2–4 are often queued before Step 1 has a server hash.
        // Require a hash when the client sent one; do not block continuation writes.
        $this->assertEnvironmentalHashIfPresent($household, $baseSnapshot, true);

        $route = ['householdNo' => (string) $household->household_no];

        match ($step) {
            1 => $this->environmentalProfiles->saveStep1(
                $household,
                OfflineInnerRequestValidator::validate(StoreHouseholdWaterSupplyRequest::class, $payload, $route),
            ),
            2 => $this->environmentalProfiles->saveStep2(
                $household,
                OfflineInnerRequestValidator::validate(StoreHouseholdWaterSupplyStep2Request::class, $payload, $route),
            ),
            3 => $this->environmentalProfiles->saveStep3(
                $household,
                OfflineInnerRequestValidator::validate(StoreHouseholdWaterSupplyStep3Request::class, $payload, $route),
            ),
            4 => $this->environmentalProfiles->saveStep4(
                $household,
                OfflineInnerRequestValidator::validate(StoreHouseholdWaterSupplyStep4Request::class, $payload, $route),
            ),
            default => throw OfflineSyncException::malformed(
                'Environmental sanitation step is required.',
                ['_eh_step' => ['A valid environmental sanitation step is required.']],
            ),
        };

        return $this->householdIdentityResult($household->fresh() ?? $household);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $parentServer
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function healthServiceWrite(array $payload, ?array $parentServer): array
    {
        $this->rejectClientAuthoritativeIds($payload, ['id', 'household_id', 'resident_id', 'member_no']);
        $this->rejectOfflineCredentialOrFileFields($payload);

        $resident = $this->resolveResidentTarget($parentServer);
        $household = $resident->household ?? Household::query()->whereKey($resident->household_id)->first();
        if ($household === null) {
            throw OfflineSyncException::targetMissing('The parent household was not found.');
        }

        $parentHousehold = $this->resolveHouseholdTarget(
            array_filter([
                'household_id' => $parentServer['household_id'] ?? $household->getKey(),
                'household_no' => $parentServer['household_no'] ?? $household->household_no,
            ]),
            true,
        );

        if ((int) $parentHousehold->getKey() !== (int) $household->getKey()) {
            throw OfflineSyncException::targetMissing('The parent household was not found.');
        }

        return $this->healthWrites->write($resident, $household, $payload);
    }

    /**
     * @param  array<string, mixed>|null  $parentServer
     */
    private function resolveHouseholdTarget(?array $parentServer, bool $required): Household
    {
        if (! is_array($parentServer) || $parentServer === []) {
            if ($required) {
                throw OfflineSyncException::targetMissing('The parent household was not found.');
            }

            throw OfflineSyncException::targetMissing();
        }

        if ($this->looksLikeClientUuid($parentServer['household_id'] ?? null)
            || $this->looksLikeClientUuid($parentServer['id'] ?? null)
        ) {
            throw OfflineSyncException::targetMissing('The parent household was not found.');
        }

        $household = null;
        $pk = $this->positiveInt($parentServer['household_id'] ?? $parentServer['id'] ?? null);
        if ($pk !== null) {
            $household = Household::query()->whereKey($pk)->first();
        }

        if ($household === null) {
            $householdNo = trim((string) ($parentServer['household_no'] ?? ''));
            if ($householdNo !== '') {
                $household = Household::query()->where('household_no', $householdNo)->first();
            }
        }

        if ($household === null) {
            throw OfflineSyncException::targetMissing('The parent household was not found.');
        }

        return Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $parentServer
     */
    private function resolveResidentTarget(?array $parentServer): Resident
    {
        if (! is_array($parentServer) || $parentServer === []) {
            throw OfflineSyncException::targetMissing('The target resident was not found.');
        }

        if ($this->looksLikeClientUuid($parentServer['resident_id'] ?? null)
            || $this->looksLikeClientUuid($parentServer['id'] ?? null)
        ) {
            throw OfflineSyncException::targetMissing('The target resident was not found.');
        }

        $resident = null;
        $pk = $this->positiveInt($parentServer['resident_id'] ?? $parentServer['id'] ?? null);
        if ($pk !== null) {
            $resident = Resident::query()->whereKey($pk)->first();
        }

        if ($resident === null) {
            $memberNo = trim((string) ($parentServer['member_no'] ?? ''));
            if ($memberNo !== '') {
                $resident = Resident::query()->where('member_no', $memberNo)->first();
            }
        }

        if ($resident === null) {
            throw OfflineSyncException::targetMissing('The target resident was not found.');
        }

        return Resident::query()->whereKey($resident->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $parentServer
     */
    private function resolveHealthWorkerTarget(?array $parentServer): User
    {
        if (! is_array($parentServer) || $parentServer === []) {
            throw OfflineSyncException::targetMissing('The target health worker was not found.');
        }

        $raw = $parentServer['user_id'] ?? null;
        if (is_string($raw) && preg_match('/^hw-/i', trim($raw)) === 1) {
            throw OfflineSyncException::targetMissing('The target health worker was not found.');
        }

        $userId = $this->positiveInt($raw);
        if ($userId === null) {
            throw OfflineSyncException::targetMissing('The target health worker was not found.');
        }

        $user = HealthWorkerUiCatalog::findMutableUser((string) $userId);
        if ($user === null) {
            throw OfflineSyncException::targetMissing('The target health worker was not found.');
        }

        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
        if ($locked === null) {
            throw OfflineSyncException::targetMissing('The target health worker was not found.');
        }

        return $locked->loadMissing('currentAppointment');
    }

    /**
     * @param  array<string, mixed>|null  $baseSnapshot
     */
    private function assertFieldHash(?array $baseSnapshot, string $currentHash): void
    {
        $submitted = is_array($baseSnapshot) ? trim((string) ($baseSnapshot['field_hash'] ?? '')) : '';

        if ($submitted === '') {
            throw OfflineSyncException::malformed(
                'A base field hash is required for this update.',
                ['base_snapshot.field_hash' => ['A base field hash is required for this update.']],
            );
        }

        if (! hash_equals($currentHash, $submitted)) {
            throw OfflineSyncException::targetChanged();
        }
    }

    /**
     * @param  array<string, mixed>|null  $baseSnapshot
     */
    private function assertEnvironmentalHashIfPresent(
        Household $household,
        ?array $baseSnapshot,
        bool $allowMissing = false,
    ): void {
        if (! OfflineFieldHasher::environmentalExists($household)) {
            return;
        }

        $submitted = is_array($baseSnapshot) ? trim((string) ($baseSnapshot['field_hash'] ?? '')) : '';
        if ($submitted === '' && $allowMissing) {
            return;
        }

        $this->assertFieldHash($baseSnapshot, OfflineFieldHasher::environmental($household));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function rejectClientAuthoritativeIds(array $payload, array $keys): void
    {
        $errors = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }

            $errors[$key] = ['This field is assigned by the server and cannot be supplied by the client.'];
        }

        if ($errors !== []) {
            throw OfflineSyncException::validationFailed($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectOfflineCredentialOrFileFields(array $payload): void
    {
        $errors = [];
        foreach (['hw_password', 'hw_password_confirmation', 'password', 'password_confirmation'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $errors[$key] = ['Password changes require a connection.'];
        }

        foreach (['hw_photo'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $errors[$key] = ['Profile photo changes require a connection.'];
        }

        foreach (['death_certificate'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $errors[$key] = ['File uploads require a connection.'];
        }

        if (array_key_exists('hw_remove_photo', $payload)) {
            $remove = filter_var($payload['hw_remove_photo'], FILTER_VALIDATE_BOOLEAN);
            if ($remove) {
                $errors['hw_remove_photo'] = ['Profile photo changes require a connection.'];
            }
        }

        if ($errors !== []) {
            throw OfflineSyncException::validationFailed($errors);
        }
    }

    /**
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    private function householdIdentityResult(Household $household): array
    {
        $householdBody = $this->householdIdentity($household);

        return [
            'identities' => [
                'household_pk' => $householdBody['id'],
                'household_no' => $householdBody['household_no'],
                'resident_pk' => null,
                'member_no' => null,
            ],
            'body' => [
                'household' => $householdBody,
            ],
        ];
    }

    /**
     * @return array{id: int, household_no: string}
     */
    private function householdIdentity(Household $household): array
    {
        return [
            'id' => (int) $household->getKey(),
            'household_no' => (string) $household->household_no,
        ];
    }

    /**
     * @return array{id: int, member_no: string|null}
     */
    private function residentIdentity(Resident $resident): array
    {
        $memberNo = $resident->member_no;

        return [
            'id' => (int) $resident->getKey(),
            'member_no' => is_string($memberNo) && $memberNo !== '' ? $memberNo : null,
        ];
    }

    /**
     * @param  array{identities: array<string, mixed>, body: array<string, mixed>}  $applied
     * @return array<string, mixed>
     */
    private function successBody(string $operationId, array $applied): array
    {
        return array_merge([
            'ok' => true,
            'code' => OfflineSyncCode::SYNCED,
            'operation_id' => $operationId,
        ], $applied['body']);
    }

    private function looksLikeClientUuid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
