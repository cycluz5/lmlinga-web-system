@if (($canPersist ?? false) && ! ($readOnly ?? false))
    @include('offline.form-attrs', [
        'offlineOperation' => 'HEALTH_SERVICE_WRITE',
        'offlineHealthAction' => 'maternal_section_update',
        'offlineHealthSection' => $section,
        'offlineHouseholdNo' => $householdNo ?? ($routeParams['householdNo'] ?? null),
        'offlineMemberNo' => $memberId ?? ($routeParams['memberId'] ?? null),
    ])
@endif
