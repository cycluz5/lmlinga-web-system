<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsNonResidentChildCare;
use Illuminate\View\View;

class NonResidentChildCareController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsNonResidentChildCare::rows();

        return view('pages.health-records.child-care-non-residents', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Non-resident and unregistered child care records.',
            'rows' => $rows,
            'barangays' => HealthRecordsNonResidentChildCare::barangays($rows),
            'years' => HealthRecordsNonResidentChildCare::years($rows),
            'totalUnfiltered' => count($rows),
        ]);
    }

    public function create(): View
    {
        return view('pages.health-records.child-care-non-residents-create', [
            'active' => 'child-care',
            'pageTitle' => 'Add New Child',
            'pageSubtitle' => 'Register a non-resident or unregistered child.',
            'barangays' => HealthRecordsNonResidentChildCare::barangays(),
            'sexOptions' => HealthRecordsNonResidentChildCare::sexOptions(),
            'gradeLevelOptions' => HealthRecordsNonResidentChildCare::gradeLevelOptions(),
            'residentLookup' => HealthRecordsNonResidentChildCare::residentLookupPayload(),
        ]);
    }

    public function show(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-show', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Non-resident child health record.',
            'child' => $child,
            'childKey' => $childKey,
            'recordItems' => HealthRecordsNonResidentChildCare::childCareRecordItems($child),
        ]);
    }

    public function edit(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-edit', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Edit personal information for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
            'sexOptions' => HealthRecordsNonResidentChildCare::sexOptions(),
        ]);
    }

    public function nutrition(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);
        $measurements = is_array($child) && is_array($child['measurements'] ?? null)
            ? $child['measurements']
            : [];
        $groups = HealthRecordsNonResidentChildCare::groupMeasurements($measurements);

        return view('pages.health-records.child-care-non-residents-nutrition', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Track the growth of the child.',
            'child' => $child,
            'childKey' => $childKey,
            'infantRecords' => $groups['infant'],
            'childRecords' => $groups['child'],
        ]);
    }

    public function createMeasurement(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-measurement', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Track the growth of the child.',
            'child' => $child,
            'childKey' => $childKey,
            'mode' => 'create',
            'measurement' => [],
            'statusOptions' => HealthRecordsNonResidentChildCare::nutritionStatusOptions(),
        ]);
    }

    public function editMeasurement(string $childKey, string $measurementId): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);
        $measurement = $child !== null
            ? HealthRecordsNonResidentChildCare::findMeasurement($childKey, $measurementId)
            : null;

        return view('pages.health-records.child-care-non-residents-measurement', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Track the growth of the child.',
            'child' => $child,
            'childKey' => $childKey,
            'mode' => 'edit',
            'measurement' => $measurement ?? [],
            'statusOptions' => HealthRecordsNonResidentChildCare::nutritionStatusOptions(),
        ]);
    }

    public function immunization(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-immunization', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Child immunization for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
        ]);
    }

    public function editBirthHistory(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-birth-history', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Birth history for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
            'returnTo' => request()->query('from', 'immunization'),
        ]);
    }

    public function schoolBasedImmunization(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-school-based', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'School-based immunization for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
        ]);
    }

    public function childNutrition(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-child-nutrition', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Child nutrition for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
        ]);
    }

    public function deworming(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-deworming', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Deworming record for this non-resident child.',
            'child' => $child,
            'childKey' => $childKey,
            'records' => is_array($child) && is_array($child['deworming_records'] ?? null)
                ? $child['deworming_records']
                : [],
        ]);
    }

    public function createDeworming(string $childKey): View
    {
        $child = HealthRecordsNonResidentChildCare::find($childKey);

        return view('pages.health-records.child-care-non-residents-deworming-create', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Non-Residents',
            'pageSubtitle' => 'Add a deworming record for this child.',
            'child' => $child,
            'childKey' => $childKey,
            'roundOptions' => HealthRecordsNonResidentChildCare::dewormingRoundOptions(),
            'seStatusOptions' => HealthRecordsNonResidentChildCare::dewormingSeStatusOptions(),
        ]);
    }
}
