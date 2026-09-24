<?php

namespace App\Support;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluates a stored Pending household-record request against official data.
 * Requires submitted email + name to match the authenticated account (trim + case-insensitive)
 * AND a unique official masterlist match. Unique match → Awaiting OTP. Otherwise → Denied.
 * Does not issue OTP, write resident_accounts.resident_id, approve, or grant household access.
 */
final class HouseholdRecordRequestMatcher
{
    public const REASON_AWAITING_OTP = 'The provided details match an existing resident record.';

    public const REASON_DENIED = 'The provided details do not correspond with an existing resident record.';

    public function evaluate(ResidentAccount $account, RecordRequest $record): void
    {
        if ((int) $record->account_id !== (int) $account->account_id) {
            $this->deny($record, self::REASON_DENIED);

            return;
        }

        $emailMatches = $this->emailsMatch((string) $record->email_submitted, (string) $account->email);
        $nameMatches = $this->namesMatch(
            (string) $record->first_name_submitted,
            $record->middle_name_submitted,
            (string) $record->last_name_submitted,
            (string) $account->first_name,
            $account->middle_name,
            (string) $account->last_name,
        );

        if (! $emailMatches || ! $nameMatches) {
            $this->deny($record, $this->accountIdentityDenialReason($account, $nameMatches, $emailMatches));

            return;
        }

        if (! $this->officialDataIsEvaluable()) {
            return;
        }

        $household = $this->findUniqueHousehold((string) $record->household_no_submitted);

        if ($household === null) {
            $this->deny($record, self::REASON_DENIED);

            return;
        }

        $matches = $this->matchingResidentsInHousehold(
            $household['id'],
            (string) $record->first_name_submitted,
            $record->middle_name_submitted,
            (string) $record->last_name_submitted,
        );

        if (count($matches) === 0) {
            $this->deny($record, self::REASON_DENIED);

            return;
        }

        if (count($matches) > 1) {
            $this->deny($record, self::REASON_DENIED);

            return;
        }

        $this->markAwaitingOtp($record, $matches[0]);
    }

    /**
     * Account-mismatch denial copy for Admin (and the requester's own Main sidebar).
     * Requester identity is always taken from ResidentAccount — never from form input.
     */
    public static function accountIdentityDenialReason(
        ResidentAccount $account,
        bool $nameMatches,
        bool $emailMatches,
    ): string {
        $requester = self::requesterAccountLabel($account);

        if (! $nameMatches && ! $emailMatches) {
            return 'The submitted name and email address do not match the requester\'s registered chatbot account information. Requester account: '.$requester.'.';
        }

        if (! $emailMatches) {
            return 'The submitted email address does not match the requester\'s registered chatbot account email. Requester account: '.$requester.'.';
        }

        return 'The submitted name does not match the requester\'s registered chatbot account information. Requester account: '.$requester.'.';
    }

    public static function requesterAccountLabel(ResidentAccount $account): string
    {
        $first = trim((string) $account->first_name);
        $middle = trim((string) ($account->middle_name ?? ''));
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        if ($name === '') {
            $name = 'Resident';
        }

        return $name.' ('.trim((string) $account->email).')';
    }

    private function officialDataIsEvaluable(): bool
    {
        try {
            if (! Schema::hasTable('households') || ! Schema::hasTable('residents')) {
                return false;
            }

            if (! Schema::hasColumn('households', 'household_no') || ! Schema::hasColumn('residents', 'household_id')) {
                return false;
            }

            if (! Schema::hasColumn('residents', 'first_name') || ! Schema::hasColumn('residents', 'last_name')) {
                return false;
            }

            $householdKey = $this->tableIdentityColumn('households', 'household_id', 'id');
            $residentKey = $this->tableIdentityColumn('residents', 'resident_id', 'id');

            if ($householdKey === null || $residentKey === null) {
                return false;
            }

            return DB::table('households')->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{id: mixed}|null
     */
    private function findUniqueHousehold(string $submittedHouseholdNo): ?array
    {
        $householdKey = $this->tableIdentityColumn('households', 'household_id', 'id');
        if ($householdKey === null) {
            return null;
        }

        $needle = $this->normalizedComparable($submittedHouseholdNo);
        if ($needle === '') {
            return null;
        }

        $hits = [];

        foreach (DB::table('households')->select([$householdKey, 'household_no'])->get() as $row) {
            if ($this->normalizedComparable((string) $row->household_no) === $needle) {
                $hits[] = $row->{$householdKey};
            }
        }

        if (count($hits) !== 1) {
            return null;
        }

        return ['id' => $hits[0]];
    }

    /**
     * @return list<mixed>
     */
    private function matchingResidentsInHousehold(
        mixed $householdId,
        string $firstSubmitted,
        mixed $middleSubmitted,
        string $lastSubmitted,
    ): array {
        $residentKey = $this->tableIdentityColumn('residents', 'resident_id', 'id');
        if ($residentKey === null) {
            return [];
        }

        $columns = [$residentKey, 'first_name', 'last_name', 'household_id'];
        if (Schema::hasColumn('residents', 'middle_name')) {
            $columns[] = 'middle_name';
        }

        $ids = [];

        foreach (DB::table('residents')->select($columns)->where('household_id', $householdId)->get() as $row) {
            $middleOfficial = Schema::hasColumn('residents', 'middle_name') ? $row->middle_name : null;

            if ($this->namesMatch(
                $firstSubmitted,
                $middleSubmitted,
                $lastSubmitted,
                (string) $row->first_name,
                $middleOfficial,
                (string) $row->last_name,
            )) {
                $ids[] = $row->{$residentKey};
            }
        }

        return $ids;
    }

    private function emailsMatch(string $submittedEmail, string $accountEmail): bool
    {
        $left = mb_strtolower(trim($submittedEmail), 'UTF-8');
        $right = mb_strtolower(trim($accountEmail), 'UTF-8');

        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right;
    }

    private function namesMatch(
        string $firstA,
        mixed $middleA,
        string $lastA,
        string $firstB,
        mixed $middleB,
        string $lastB,
    ): bool {
        if ($this->normalizedComparable($firstA) !== $this->normalizedComparable($firstB)) {
            return false;
        }

        if ($this->normalizedComparable($lastA) !== $this->normalizedComparable($lastB)) {
            return false;
        }

        $middleLeft = $this->normalizedComparable((string) ($middleA ?? ''));
        $middleRight = $this->normalizedComparable((string) ($middleB ?? ''));

        if ($middleLeft !== '' && $middleRight === '') {
            return false;
        }

        return $middleLeft === $middleRight;
    }

    private function normalizedComparable(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function deny(RecordRequest $record, string $reason = self::REASON_DENIED): void
    {
        $record->status = RecordRequest::STATUS_DENIED;
        $record->decision_reason = $reason;
        $record->matched_resident_id = null;
        $record->evaluated_at = now();
        $record->approved_at = null;
        $record->save();
    }

    private function markAwaitingOtp(RecordRequest $record, mixed $residentId): void
    {
        $record->status = RecordRequest::STATUS_AWAITING_OTP;
        $record->matched_resident_id = $residentId;
        $record->decision_reason = self::REASON_AWAITING_OTP;
        $record->evaluated_at = now();
        $record->approved_at = null;
        $record->save();
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
