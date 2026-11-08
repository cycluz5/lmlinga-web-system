<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\RecordRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\TimbangRecord;
use App\Support\ChatbotHouseholdNumberDisplay;
use App\Support\HouseholdProfilingPresenter;
use App\Support\HouseholdRecordVerifiedAccess;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Authorized household-member view for chatbot accounts whose latest
 * owned record request is Approved. Household scope is derived only from
 * session account → linked resident → household (never from request input).
 */
class HouseholdInformationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $payload = $this->authorizedHouseholdPayload($account);

        if ($payload === null) {
            return redirect()->route('chatbot.main');
        }

        return view('pages.chatbot.household-information', $payload);
    }

    /**
     * @return array{
     *     residentDisplayName: string,
     *     householdDisplayNo: string,
     *     members: list<array<string, string>>,
     *     summaryAdults: int,
     *     summaryYouth: int,
     *     summaryChildren: int
     * }|null
     */
    private function authorizedHouseholdPayload(ResidentAccount $account): ?array
    {
        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            ! $record instanceof RecordRequest
            || ! HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)
        ) {
            return null;
        }

        $residentKey = $this->tableIdentityColumn('residents', 'resident_id', 'id');
        $householdKey = $this->tableIdentityColumn('households', 'household_id', 'id');

        if (
            $residentKey === null
            || $householdKey === null
            || ! Schema::hasColumn('residents', 'household_id')
            || ! Schema::hasColumn('households', 'household_no')
        ) {
            return null;
        }

        $linkedResident = DB::table('residents')
            ->where($residentKey, $account->resident_id)
            ->when(Schema::hasColumn('residents', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'))
            ->first();

        if ($linkedResident === null || ! isset($linkedResident->household_id) || $linkedResident->household_id === null) {
            return null;
        }

        $household = DB::table('households')
            ->where($householdKey, $linkedResident->household_id)
            ->when(Schema::hasColumn('households', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'))
            ->first();

        if ($household === null) {
            return null;
        }

        $householdNo = trim((string) ($household->household_no ?? ''));

        if ($householdNo === '') {
            return null;
        }

        $authorizedHouseholdId = $linkedResident->household_id;

        $residentQuery = Resident::query()
            ->where('household_id', $authorizedHouseholdId)
            ->when(Schema::hasColumn('residents', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'));

        Resident::eagerLoadForProfiling($residentQuery);

        /** @var Collection<int, Resident> $residents */
        $residents = $residentQuery->get();
        $ordered = $this->orderHouseholdMembers($residents);
        $latestTimbang = $this->latestTimbangByResidentId(
            $ordered->map(static fn (Resident $resident): string => (string) $resident->getKey())->all()
        );

        $members = [];
        $summaryAdults = 0;
        $summaryYouth = 0;
        $summaryChildren = 0;

        foreach ($ordered as $resident) {
            $members[] = $this->mapMemberRow(
                $resident,
                $latestTimbang[(string) $resident->getKey()] ?? null
            );

            $years = $this->ageInYears($resident->birthday ?? null);

            if ($years === null) {
                continue;
            }

            if ($years >= 18) {
                $summaryAdults++;
            } elseif ($years >= 13) {
                $summaryYouth++;
            } else {
                $summaryChildren++;
            }
        }

        return [
            'residentDisplayName' => $this->displayName($account),
            'householdDisplayNo' => ChatbotHouseholdNumberDisplay::format($householdNo),
            'members' => $members,
            'summaryAdults' => $summaryAdults,
            'summaryYouth' => $summaryYouth,
            'summaryChildren' => $summaryChildren,
        ];
    }

    /**
     * @param  Collection<int, Resident>  $residents
     * @return Collection<int, Resident>
     */
    private function orderHouseholdMembers(Collection $residents): Collection
    {
        return $residents
            ->sort(function (Resident $a, Resident $b): int {
                $priorityCompare = $this->relationPresentationPriority((string) ($a->relation ?? ''))
                    <=> $this->relationPresentationPriority((string) ($b->relation ?? ''));

                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                $birthdayCompare = $this->birthdaySortKey($a->birthday ?? null)
                    <=> $this->birthdaySortKey($b->birthday ?? null);

                if ($birthdayCompare !== 0) {
                    return $birthdayCompare;
                }

                return strcmp((string) $a->getKey(), (string) $b->getKey());
            })
            ->values();
    }

    /**
     * Lower rank sorts first. Built from LMLINGA relation values (Head, Spouse, …).
     */
    private function relationPresentationPriority(string $relation): int
    {
        $normalized = mb_strtolower(trim($relation), 'UTF-8');

        if (in_array($normalized, ['head', 'head of household', 'household head'], true)) {
            return 0;
        }

        if (in_array($normalized, ['spouse', 'partner', 'live-in', 'live in'], true)) {
            return 1;
        }

        if (in_array($normalized, ['parent', 'father', 'mother'], true)) {
            return 2;
        }

        if (in_array($normalized, ['son', 'daughter', 'grandchild', 'child', 'children'], true)) {
            return 4;
        }

        if (in_array($normalized, [
            'sibling',
            'grandparent',
            'other relative',
            'relative',
        ], true)) {
            return 3;
        }

        if (in_array($normalized, [
            'non-relative',
            'non-relative household member',
            'non relative',
            'other',
            '',
        ], true)) {
            return 5;
        }

        return 3;
    }

    private function birthdaySortKey(mixed $birthday): int
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return $date->getTimestamp();
    }

    /**
     * Latest Nutritional Status row per authorized household member.
     * One batched query — measurement_date DESC, timbang_id DESC.
     *
     * @param  list<string>  $residentIds
     * @return array<string, TimbangRecord>
     */
    private function latestTimbangByResidentId(array $residentIds): array
    {
        if (
            $residentIds === []
            || ! Schema::hasTable('timbang_records')
            || ! Schema::hasColumn('timbang_records', 'resident_id')
            || ! Schema::hasColumn('timbang_records', 'measurement_date')
            || ! Schema::hasColumn('timbang_records', 'timbang_id')
        ) {
            return [];
        }

        $rows = TimbangRecord::query()
            ->whereIn('resident_id', $residentIds)
            ->orderByDesc('measurement_date')
            ->orderByDesc('timbang_id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $key = (string) $row->resident_id;
            if (! isset($latest[$key])) {
                $latest[$key] = $row;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, string>
     */
    private function mapMemberRow(Resident $resident, ?TimbangRecord $timbang): array
    {
        $pk = (string) $resident->getKey();
        $anchorId = 'member-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $pk);
        $presented = HouseholdProfilingPresenter::memberFromModel($resident);

        $name = trim((string) ($presented['name'] ?? ''));
        $relation = trim((string) ($presented['relationship'] ?? $presented['relation'] ?? ''));
        $relationshipLabel = $this->relationshipLabel($relation);

        $sex = trim((string) ($presented['sex'] ?? ''));
        $civil = trim((string) ($presented['relationship_status'] ?? ''));
        $occupation = trim((string) ($presented['occupation'] ?? ''));
        $birthdayRaw = $presented['birthday'] ?? $resident->birthday;

        $metrics = $this->nutritionMetricsFromTimbang($timbang);

        return [
            'id' => $anchorId,
            'residentId' => $pk,
            'name' => $name !== '' ? $name : 'Resident',
            'age' => $this->formatAge($birthdayRaw),
            'sex' => $sex !== '' ? $sex : '—',
            'relationship' => $relationshipLabel,
            'birthday' => $this->formatBirthday($birthdayRaw),
            'civilStatus' => $civil !== '' ? $civil : '—',
            'occupation' => $occupation !== '' ? $occupation : '—',
            'weight' => $metrics['weight'],
            'height' => $metrics['height'],
            'nutrition' => $metrics['nutrition'],
        ];
    }

    /**
     * Latest timbang_records measurement supplies weight/height only.
     * Nutritional classification is not stored/derived — listing status stays "No record".
     *
     * @return array{weight: string, height: string, nutrition: string}
     */
    private function nutritionMetricsFromTimbang(?TimbangRecord $timbang): array
    {
        if ($timbang === null) {
            return [
                'weight' => '—',
                'height' => '—',
                'nutrition' => 'No record',
            ];
        }

        return [
            'weight' => $this->formatMeasure($timbang->weight_kg, 'kg'),
            'height' => $this->formatMeasure($timbang->height_cm, 'cm'),
            // No approved nutritional classification algorithm / stored status field.
            'nutrition' => 'No record',
        ];
    }

    private function formatMeasure(mixed $value, string $unit): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;
        $formatted = fmod($number, 1.0) === 0.0
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted.' '.$unit;
    }

    private function relationshipLabel(string $relation): string
    {
        $normalized = mb_strtolower(trim($relation), 'UTF-8');

        if (in_array($normalized, ['head', 'head of household', 'household head'], true)) {
            return 'Head of Household';
        }

        return $relation !== '' ? $relation : '—';
    }

    private function formatAge(mixed $birthday): string
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return '—';
        }

        $now = now()->startOfDay();
        $birth = $date->copy()->startOfDay();

        if ($birth->greaterThan($now)) {
            return '—';
        }

        $months = (int) $birth->diffInMonths($now);

        if ($months < 12) {
            $months = max($months, 0);

            return $months === 1 ? '1 month old' : $months.' months old';
        }

        $years = (int) $birth->diffInYears($now);

        return $years === 1 ? '1 year old' : $years.' years old';
    }

    private function ageInYears(mixed $birthday): ?int
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return null;
        }

        $now = now()->startOfDay();
        $birth = $date->copy()->startOfDay();

        if ($birth->greaterThan($now)) {
            return null;
        }

        return (int) $birth->diffInYears($now);
    }

    private function formatBirthday(mixed $birthday): string
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return '—';
        }

        return $date->format('F j, Y');
    }

    private function displayName(ResidentAccount $account): string
    {
        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        return $name !== '' ? $name : 'Resident';
    }

    private function tableIdentityColumn(string $table, string $livePrimaryKey, string $sqlitePrimaryKey): ?string
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            if (Schema::hasColumn($table, $livePrimaryKey)) {
                return $livePrimaryKey;
            }

            if (Schema::hasColumn($table, $sqlitePrimaryKey)) {
                return $sqlitePrimaryKey;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
