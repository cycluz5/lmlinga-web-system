<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\ChatbotHouseholdNumberDisplay;
use App\Support\HouseholdRecordVerifiedAccess;
use App\Support\ResidentAuthenticator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ChatbotMainController extends Controller
{
    public function show(): View
    {
        $account = $this->currentResidentAccount();
        $householdRequestState = $this->householdRequestState($account);

        return view('pages.chatbot.main', [
            'residentDisplayName' => $this->displayName($account),
            'householdDisplayNo' => $this->officialHouseholdDisplay($account),
            'householdRequestState' => $householdRequestState,
            'householdDecisionReason' => $this->householdDecisionReason($account),
            'chatbotNotifications' => $this->residentNotificationsForView(
                $account,
                $householdRequestState === 'approved',
            ),
        ]);
    }

    private function currentResidentAccount(): ?ResidentAccount
    {
        $accountId = session(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        if ($accountId === null || $accountId === '') {
            return null;
        }

        $account = ResidentAccount::query()->find($accountId);

        return $account instanceof ResidentAccount ? $account : null;
    }

    private function displayName(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return 'Resident';
        }

        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        return $name !== '' ? $name : 'Resident';
    }

    /**
     * Official households.household_no for verified household access only.
     * Null when unverified — Blade must not render a profile house line.
     * Uses grantsHouseholdInformationAccess (Approved + OTP + linked resident).
     * Resolved server-side only — never from browser/query identifiers.
     */
    private function officialHouseholdDisplay(?ResidentAccount $account): ?string
    {
        if (! $account instanceof ResidentAccount) {
            return null;
        }

        $accountKey = $account->getKey();
        if ($accountKey === null || $accountKey === '') {
            return null;
        }

        $record = RecordRequest::latestForAccount($accountKey);

        // Require an owned request row before the shared gate (avoids null account_id lookup).
        if (! $record instanceof RecordRequest) {
            return null;
        }

        if (! HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)) {
            return null;
        }

        $householdNo = HouseholdRecordVerifiedAccess::officialHouseholdNoForLinkedAccount($account);

        return $householdNo !== null
            ? ChatbotHouseholdNumberDisplay::format($householdNo)
            : null;
    }

    private function householdRequestState(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return 'none';
        }

        $accountKey = $account->getKey();
        if ($accountKey === null || $accountKey === '') {
            return 'none';
        }

        $record = RecordRequest::latestForAccount($accountKey);

        if (! $record instanceof RecordRequest || (int) $record->account_id !== (int) $accountKey) {
            return 'none';
        }

        // Permanent verified CTA: Approved + OTP evidence + valid resident/household link.
        if (HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)) {
            return 'approved';
        }

        if (HouseholdRecordVerifiedAccess::requiresOtpVerification($record)) {
            return 'awaiting_otp';
        }

        return match ($record->status) {
            RecordRequest::STATUS_PENDING => 'pending',
            RecordRequest::STATUS_DENIED => 'denied',
            default => 'none',
        };
    }

    private function householdDecisionReason(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return '';
        }

        $accountKey = $account->getKey();
        if ($accountKey === null || $accountKey === '') {
            return '';
        }

        $record = RecordRequest::latestForAccount($accountKey);

        if (
            ! $record instanceof RecordRequest
            || (int) $record->account_id !== (int) $accountKey
        ) {
            return '';
        }

        // Sidebar helper text is for Denied only. Verified residents must not see
        // stored match/OTP decision_reason (e.g. "Complete OTP verification…").
        if ($record->status === RecordRequest::STATUS_DENIED) {
            return trim((string) $record->decision_reason);
        }

        return '';
    }

    /**
     * Persist is_read=1 for one owned notification.
     * Ownership: session ResidentAccount only — never trust client account_id.
     */
    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        if ($accountId === null || $accountId === '' || ! Schema::hasTable('notifications')) {
            abort(404);
        }

        $owned = DB::table('notifications')
            ->where('notification_id', $notificationId)
            ->where('account_id', $accountId)
            ->exists();

        if (! $owned) {
            abort(404);
        }

        DB::table('notifications')
            ->where('notification_id', $notificationId)
            ->where('account_id', $accountId)
            ->update(['is_read' => 1]);

        return response()->json([
            'ok' => true,
            'notification_id' => $notificationId,
            'is_read' => true,
        ]);
    }

    /**
     * Real notifications for the session resident account when household-verified.
     * Account ID comes from session-loaded ResidentAccount only — never from request input.
     *
     * @return list<array<string, mixed>>
     */
    private function residentNotificationsForView(?ResidentAccount $account, bool $householdVerified): array
    {
        if (
            ! $householdVerified
            || ! $account instanceof ResidentAccount
            || ! Schema::hasTable('notifications')
        ) {
            return [];
        }

        $accountKey = $account->getKey();
        if ($accountKey === null || $accountKey === '') {
            return [];
        }

        $rows = DB::table('notifications')
            ->where('account_id', $accountKey)
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id')
            ->get();

        return $rows->map(fn ($row): array => $this->presentNotificationRow($row))->all();
    }

    /**
     * Map a notifications table row into the existing sidebar/modal data shape.
     *
     * @return array<string, mixed>
     */
    private function presentNotificationRow(object $row): array
    {
        $title = trim((string) ($row->title ?? ''));
        $message = trim((string) ($row->message ?? ''));
        $who = property_exists($row, 'recipient_context')
            ? trim((string) ($row->recipient_context ?? ''))
            : '';
        $place = property_exists($row, 'place')
            ? trim((string) ($row->place ?? ''))
            : '';
        $eventDate = property_exists($row, 'event_date') ? ($row->event_date ?? null) : null;
        $eventTime = property_exists($row, 'event_time') ? ($row->event_time ?? null) : null;

        return [
            'id' => (string) $row->notification_id,
            'title' => $title,
            'message' => $message,
            'notification_type' => trim((string) ($row->notification_type ?? 'System')) ?: 'System',
            'service' => $title,
            'service_short' => $title,
            'who' => $who,
            'recipient_context' => $who,
            'member_name' => '',
            'relationship' => '',
            // Announcement schedule — never fall back to created_at.
            'date' => $this->formatNotificationEventDate($eventDate),
            'time' => $this->formatNotificationEventTime($eventTime),
            'place' => $place,
            'status' => 'notice',
            'icon' => 'bi-bell',
            'is_read' => (bool) $row->is_read,
            'reminder_html' => $message,
        ];
    }

    private function formatNotificationEventDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->format('F j, Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatNotificationEventTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        try {
            $normalized = preg_match('/^\d{1,2}:\d{2}$/', $raw) === 1 ? $raw.':00' : $raw;

            return Carbon::createFromFormat('H:i:s', $normalized)->format('g:i A');
        } catch (\Throwable) {
            try {
                return Carbon::parse($raw)->format('g:i A');
            } catch (\Throwable) {
                return '';
            }
        }
    }
}
