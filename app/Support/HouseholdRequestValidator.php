<?php

namespace App\Support;

/**
 * Deterministic household-request automated validation for UI-preview residents.
 * Evaluates only machine-checkable rules (presence, format, consistency).
 * Does not assess real-world truthfulness of identity or residency.
 */
final class HouseholdRequestValidator
{
    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /** @var list<string> */
    private const ALLOWED_ZONES = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];

    /**
     * @param  array<string, mixed>  $request
     * @return array{
     *     status: string,
     *     validation_result: string,
     *     evaluated_at: string|null,
     *     passed_rules: list<string>,
     *     failed_rules: list<string>,
     *     rejection_reasons: list<string>,
     *     decision_reason: string
     * }
     */
    public static function evaluate(array $request): array
    {
        $passed = [];
        $failed = [];
        $reasons = [];

        $requiredFields = [
            'first_name' => 'Missing required identity information (first name).',
            'last_name' => 'Missing required identity information (last name).',
            'zone' => 'Household address is incomplete (zone is required).',
            'mobile' => 'Required supporting information is missing (mobile number).',
            'house_no' => 'Household address is incomplete (house number is missing).',
            'street' => 'Household address is incomplete (street is missing).',
            'barangay' => 'Household address is incomplete (barangay is missing).',
            'submitted_at' => 'Required supporting information is missing (submission date).',
        ];

        foreach ($requiredFields as $field => $reason) {
            $value = trim((string) ($request[$field] ?? ''));
            if ($value === '') {
                $failed[] = "required.{$field}";
                $reasons[] = $reason;
            } else {
                $passed[] = "required.{$field}";
            }
        }

        $zone = trim((string) ($request['zone'] ?? ''));
        if ($zone !== '') {
            if (in_array($zone, self::ALLOWED_ZONES, true)) {
                $passed[] = 'zone.allowed';
            } else {
                $failed[] = 'zone.allowed';
                $reasons[] = 'Zone value is not in the allowed barangay zone list.';
            }
        }

        $mobile = preg_replace('/\s+/', '', (string) ($request['mobile'] ?? '')) ?? '';
        if ($mobile !== '') {
            if (preg_match('/^(09|\+639)\d{9}$/', $mobile) === 1) {
                $passed[] = 'mobile.format';
            } else {
                $failed[] = 'mobile.format';
                $reasons[] = 'Invalid mobile number format.';
            }
        }

        $members = $request['household_members'] ?? null;
        $memberCount = is_array($members) ? count($members) : 0;
        if ($memberCount >= 1) {
            $passed[] = 'members.present';
        } else {
            $failed[] = 'members.present';
            $reasons[] = 'Missing required household member information.';
        }

        if (! empty($request['duplicate_detected'])) {
            $failed[] = 'duplicate.request';
            $reasons[] = 'Duplicate household request detected.';
        } else {
            $passed[] = 'duplicate.request';
        }

        $submittedAt = trim((string) ($request['submitted_at'] ?? ''));
        if ($submittedAt !== '') {
            $timestamp = strtotime($submittedAt);
            if ($timestamp !== false) {
                $passed[] = 'date.valid';
            } else {
                $failed[] = 'date.valid';
                $reasons[] = 'Submission date is invalid.';
            }
        }

        $passed = array_values(array_unique($passed));
        $failed = array_values(array_unique($failed));
        $reasons = array_values(array_unique($reasons));

        $isApproved = $failed === [];
        $status = $isApproved ? self::STATUS_APPROVED : self::STATUS_REJECTED;

        return [
            'status' => $status,
            'validation_result' => $isApproved
                ? 'Passed automated validation'
                : 'Failed automated validation',
            'evaluated_at' => $submittedAt !== '' && strtotime($submittedAt) !== false
                ? date('Y-m-d H:i:s', strtotime($submittedAt) + 3600)
                : date('Y-m-d H:i:s'),
            'passed_rules' => $passed,
            'failed_rules' => $failed,
            'rejection_reasons' => $reasons,
            'decision_reason' => $isApproved
                ? 'The submitted household information passed the required completeness and validation checks.'
                : ($reasons[0] ?? 'The submitted household request contains missing, inconsistent, or invalid required information.'),
        ];
    }

    /**
     * Enrich a demo resident record with deterministic review metadata.
     *
     * @param  array<string, mixed>  $resident
     * @return array<string, mixed>
     */
    public static function enrich(array $resident): array
    {
        $review = self::evaluate($resident);
        $resident['status'] = $review['status'];
        $resident['validation_result'] = $review['validation_result'];
        $resident['evaluated_at'] = $review['evaluated_at'];
        $resident['passed_rules'] = $review['passed_rules'];
        $resident['failed_rules'] = $review['failed_rules'];
        $resident['rejection_reasons'] = $review['rejection_reasons'];
        $resident['decision_reason'] = $review['decision_reason'];

        return $resident;
    }
}
