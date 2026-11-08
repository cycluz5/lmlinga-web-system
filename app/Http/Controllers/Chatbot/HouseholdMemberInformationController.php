<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\ChatbotHouseholdMemberAccess;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * View-only household member record for verified chatbot resident accounts.
 */
class HouseholdMemberInformationController extends Controller
{
    public function show(Request $request, string $member): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $resolved = ChatbotHouseholdMemberAccess::resolveAuthorizedMember($account, $member);

        if ($resolved === null) {
            return redirect()->route('chatbot.main');
        }

        /** @var Resident $resident */
        $resident = $resolved['resident'];
        $presented = HouseholdProfilingPresenter::memberFromModel($resident);

        return view('pages.chatbot.household-member-information', [
            'memberName' => (string) ($presented['name'] ?? 'Resident'),
            'memberId' => (string) $resident->getKey(),
            'personal' => $this->personalSection($presented),
            'socioEconomic' => $this->socioEconomicSection($presented),
            'healthWelfare' => $this->healthWelfareSection($presented),
        ]);
    }

    /**
     * @param  array<string, mixed>  $presented
     * @return list<array{label: string, value: string}>
     */
    private function personalSection(array $presented): array
    {
        $birthday = trim((string) ($presented['birthday'] ?? ''));

        return [
            ['label' => 'Full Name', 'value' => $this->displayValue($presented['name'] ?? null)],
            ['label' => 'Relation to Household Head', 'value' => $this->displayValue($presented['relationship'] ?? $presented['relation'] ?? null)],
            ['label' => 'Relationship Status', 'value' => $this->displayValue($presented['relationship_status'] ?? null)],
            ['label' => 'Birthday', 'value' => $birthday !== '' ? $this->formatBirthday($birthday) : '—'],
            ['label' => 'Sex', 'value' => $this->displayValue($presented['sex'] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $presented
     * @return list<array{label: string, value: string}>
     */
    private function socioEconomicSection(array $presented): array
    {
        return [
            ['label' => 'Occupation', 'value' => $this->displayValue($presented['occupation'] ?? null)],
            ['label' => 'Monthly Income', 'value' => $this->displayValue($presented['monthly_income'] ?? null)],
            ['label' => 'Religion', 'value' => $this->displayValue($presented['religion'] ?? null)],
            ['label' => 'Educational Attainment', 'value' => $this->displayValue($presented['education'] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $presented
     * @return list<array{label: string, value: string}>
     */
    private function healthWelfareSection(array $presented): array
    {
        return [
            ['label' => 'PhilHealth Number', 'value' => $this->displayValue($presented['philhealth'] ?? null)],
            ['label' => 'Family Planning', 'value' => $this->displayValue($presented['fp_user'] ?? null)],
            ['label' => 'Disability Type', 'value' => $this->formatListField($presented['disability'] ?? null, (string) ($presented['disability_others'] ?? ''))],
            ['label' => 'Medical History', 'value' => $this->formatListField($presented['medical_history'] ?? null, (string) ($presented['medical_others'] ?? ''))],
        ];
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->formatListField($value, '');
        }

        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : '—';
    }

    private function formatListField(mixed $items, string $others): string
    {
        if (! is_array($items) || $items === []) {
            return '—';
        }

        $labels = [];
        foreach ($items as $item) {
            $token = trim((string) $item);
            if ($token === '' || strcasecmp($token, 'none') === 0) {
                continue;
            }
            if (strcasecmp($token, 'others') === 0) {
                $other = trim($others);
                $labels[] = $other !== '' ? 'Others ('.$other.')' : 'Others';
                continue;
            }
            $labels[] = $token;
        }

        if ($labels === []) {
            $hasNone = false;
            foreach ($items as $item) {
                if (strcasecmp(trim((string) $item), 'none') === 0) {
                    $hasNone = true;
                    break;
                }
            }

            return $hasNone ? 'None' : '—';
        }

        return implode(', ', $labels);
    }

    private function formatBirthday(string $birthday): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($birthday)->format('F j, Y');
        } catch (\Throwable) {
            return $birthday;
        }
    }
}
