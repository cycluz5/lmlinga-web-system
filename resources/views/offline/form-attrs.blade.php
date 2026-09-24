@php
    $offlineOperation = $offlineOperation ?? null;
    $offlineHealthAction = $offlineHealthAction ?? null;
    $offlineHealthSection = $offlineHealthSection ?? null;
    $offlineHealthVisitId = $offlineHealthVisitId ?? null;
    $offlineHealthAssessmentId = $offlineHealthAssessmentId ?? null;
    $offlineEhStep = $offlineEhStep ?? null;
    $offlineHouseholdPk = $offlineHouseholdPk ?? null;
    $offlineHouseholdNo = $offlineHouseholdNo ?? ($householdNo ?? null);
    $offlineResidentPk = $offlineResidentPk ?? null;
    $offlineMemberNo = $offlineMemberNo ?? ($memberId ?? null);
    $offlineFieldHash = $offlineFieldHash ?? null;
@endphp
@if ($offlineOperation)
    data-offline-operation="{{ $offlineOperation }}"
@endif
@if ($offlineHealthAction)
    data-offline-health-action="{{ $offlineHealthAction }}"
@endif
@if ($offlineHealthSection)
    data-offline-health-section="{{ $offlineHealthSection }}"
@endif
@if ($offlineHealthVisitId)
    data-offline-health-visit-id="{{ $offlineHealthVisitId }}"
@endif
@if ($offlineHealthAssessmentId)
    data-offline-health-assessment-id="{{ $offlineHealthAssessmentId }}"
@endif
@if ($offlineEhStep)
    data-offline-eh-step="{{ $offlineEhStep }}"
@endif
@if ($offlineHouseholdPk)
    data-offline-parent-household-id="{{ $offlineHouseholdPk }}"
@endif
@if ($offlineHouseholdNo)
    data-offline-parent-household-no="{{ $offlineHouseholdNo }}"
@endif
@if ($offlineResidentPk)
    data-offline-parent-resident-id="{{ $offlineResidentPk }}"
@endif
@if ($offlineMemberNo)
    data-offline-parent-member-no="{{ $offlineMemberNo }}"
@endif
@if ($offlineFieldHash)
    data-offline-field-hash="{{ $offlineFieldHash }}"
@endif
