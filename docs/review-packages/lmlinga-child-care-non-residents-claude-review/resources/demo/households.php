<?php

/**
 * LMLinga — Canonical UI-phase demo households (Barangay La Medalla only).
 *
 * Shared by:
 *   • Household Profiling list
 *   • Household Profiling View Household page
 *   • View Member / Edit Member pages
 *   • Spot Mapping markers (JS mirror must stay in sync — see spot-mapping.js)
 *
 * Do NOT treat this as a database. No persistence. No CRUD.
 *
 * Route identity: householdNo (e.g. HH-151) → /household-profiling/HH-151
 * Member identity: memberId (e.g. MB-001) → …/members/MB-001
 *
 * memberList entries keep list/table fields (id, name, relationship, age, sex, occupation)
 * plus full profile fields used by View Member and Edit Member (single source of truth).
 */

/**
 * @param  array<string, mixed>  $household
 * @return array<string, mixed>|null
 */
if (! function_exists('lml_demo_find_member')) {
    function lml_demo_find_member(array $household, string $memberId): ?array
    {
        $needle = strtoupper(trim($memberId));
        foreach ($household['memberList'] ?? [] as $member) {
            if (strtoupper((string) ($member['id'] ?? '')) === $needle) {
                return $member;
            }
        }

        return null;
    }
}

/**
 * Human-readable labels for View Member (form option values stay canonical for Edit).
 *
 * @param  array<string, mixed>  $member
 */
if (! function_exists('lml_demo_member_display')) {
    function lml_demo_member_display(array $member, string $key): string
    {
        $value = $member[$key] ?? '';

        if ($key === 'relation') {
            return $value === 'Head' ? 'Household Head' : (string) $value;
        }

        if ($key === 'religion' && $value === 'Roman Catholic') {
            return 'Catholic';
        }

        if ($key === 'education' && $value === 'College Graduate') {
            return "Bachelor's Degree";
        }

        if ($key === 'monthly_income' && $value === '30,000 – 49,999') {
            return '30,000';
        }

        if ($key === 'occupation' && $value === 'None / N/A') {
            return 'N/A';
        }

        if ($key === 'birthday' && is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts ? date('F j, Y', $ts) : $value;
        }

        if (in_array($key, ['disability', 'medical_history'], true) && is_array($value)) {
            if ($value === ['none'] || $value === []) {
                return 'None';
            }

            $labels = array_map(static function ($item) use ($member, $key) {
                if ($item === 'others') {
                    $othersKey = $key === 'disability' ? 'disability_others' : 'medical_others';

                    return trim((string) ($member[$othersKey] ?? 'Others')) ?: 'Others';
                }

                return (string) $item;
            }, $value);

            return implode(', ', $labels);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

return [
    'HH-151' => [
        'householdNo' => 'HH-151',
        'displayNo' => 'HH 151',
        'houseHead' => 'Kristine Reyes',
        'zone' => 'Zone 2',
        'street' => 'Layuan St.',
        'address' => 'Layuan St., Brgy. La Medalla',
        'purok' => 'Zone 2',
        'members' => 6,
        'lat' => 13.3811,
        'lng' => 123.4306,
        'mapStatus' => 'plotted',
        'accomplishedBy' => 'Lani Magistrado (BHW)',
        'accomplishedDate' => 'January 21, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level III',
            'status' => 'Safely Managed',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Basic Sanitation Facility',
            'status' => 'Safely Managed Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-001',
                'name' => 'Kristine Reyes',
                'relationship' => 'Head',
                'age' => 35,
                'sex' => 'Male',
                'occupation' => 'Nurse',
                'last_name' => 'Reyes',
                'first_name' => 'Kristine',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1991-05-04',
                'relationship_status' => 'Married',
                'monthly_income' => '30,000 – 49,999',
                'religion' => 'Roman Catholic',
                'education' => 'College Graduate',
                'philhealth' => '123456789012',
                'fp_user' => 'No',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '60',
                    'height' => '168',
                    'bmi' => '20.8',
                    'status' => 'Normal',
                ],
            ],
            [
                'id' => 'MB-002',
                'name' => 'Kristine Reyes',
                'relationship' => 'Wife',
                'age' => 35,
                'sex' => 'Female',
                'occupation' => 'Nurse',
                'last_name' => 'Reyes',
                'first_name' => 'Kristine',
                'middle_name' => '',
                'relation' => 'Spouse',
                'birthday' => '1991-08-12',
                'relationship_status' => 'Married',
                'monthly_income' => '30,000 – 49,999',
                'religion' => 'Roman Catholic',
                'education' => 'College Graduate',
                'philhealth' => '123456789013',
                'fp_user' => 'Yes',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '55',
                    'height' => '160',
                    'bmi' => '21.5',
                    'status' => 'Normal',
                ],
            ],
            [
                'id' => 'MB-003',
                'name' => 'Angelo David Reyes',
                'relationship' => 'Son',
                'age' => 5,
                'sex' => 'Male',
                'occupation' => 'None / N/A',
                'last_name' => 'Reyes',
                'first_name' => 'Angelo David',
                'middle_name' => '',
                'relation' => 'Son',
                'birthday' => '2020-11-03',
                'relationship_status' => 'Single',
                'monthly_income' => 'None / N/A',
                'religion' => 'Roman Catholic',
                'education' => 'N/A',
                'philhealth' => '123456789014',
                'fp_user' => 'N/A',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '18',
                    'height' => '110',
                    'bmi' => '14.9',
                    'status' => 'Normal',
                ],
            ],
            [
                'id' => 'MB-009',
                'name' => 'Kristine B. Reyes',
                'relationship' => 'Daughter',
                'age' => 0,
                'sex' => 'Female',
                'occupation' => 'None / N/A',
                'last_name' => 'Reyes',
                'first_name' => 'Kristine',
                'middle_name' => 'B.',
                'relation' => 'Daughter',
                'birthday' => '2026-05-04',
                'relationship_status' => 'Single',
                'monthly_income' => 'None / N/A',
                'religion' => 'Roman Catholic',
                'education' => 'N/A',
                'philhealth' => '123456789015',
                'fp_user' => 'N/A',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'birth_history' => [
                    'weight' => '3.1',
                    'length' => '49',
                    'status' => 'Normal',
                    'pcab' => 'Yes',
                ],
                'nutrition' => [
                    'weight' => '5.2',
                    'height' => '58',
                    'bmi' => '15.4',
                    'status' => 'Normal',
                ],
            ],
            [
                'id' => 'MB-010',
                'name' => 'Jacob A. Magistrado',
                'relationship' => 'Son',
                'age' => 0,
                'sex' => 'Male',
                'occupation' => 'None / N/A',
                'last_name' => 'Magistrado',
                'first_name' => 'Jacob',
                'middle_name' => 'A.',
                'relation' => 'Son',
                'birthday' => '2026-07-10',
                'relationship_status' => 'Single',
                'monthly_income' => 'None / N/A',
                'religion' => 'Roman Catholic',
                'education' => 'N/A',
                'philhealth' => '123456789016',
                'fp_user' => 'N/A',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'birth_history' => [
                    'weight' => '3.4',
                    'length' => '50',
                    'status' => 'Normal',
                    'pcab' => 'Yes',
                ],
                'nutrition' => [
                    'weight' => '4.1',
                    'height' => '54',
                    'bmi' => '14.0',
                    'status' => 'Normal',
                ],
            ],
            [
                'id' => 'MB-011',
                'name' => 'Haziel H. Santos',
                'relationship' => 'Son',
                'age' => 0,
                'sex' => 'Male',
                'occupation' => 'None / N/A',
                'last_name' => 'Santos',
                'first_name' => 'Haziel',
                'middle_name' => 'H.',
                'relation' => 'Son',
                'birthday' => '2026-02-05',
                'relationship_status' => 'Single',
                'monthly_income' => 'None / N/A',
                'religion' => 'Roman Catholic',
                'education' => 'N/A',
                'philhealth' => '123456789017',
                'fp_user' => 'N/A',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'birth_history' => [
                    'weight' => '3.0',
                    'length' => '48',
                    'status' => 'Normal',
                    'pcab' => 'Yes',
                ],
                'nutrition' => [
                    'weight' => '6.8',
                    'height' => '62',
                    'bmi' => '15.1',
                    'status' => 'Normal',
                ],
            ],
        ],
    ],
    'HH-152' => [
        'householdNo' => 'HH-152',
        'displayNo' => 'HH 152',
        'houseHead' => 'Carlo Evangelista',
        'zone' => 'Zone 5',
        'street' => 'Dalipay St.',
        'address' => 'Dalipay St., Brgy. La Medalla',
        'purok' => 'Zone 5',
        'members' => 10,
        'lat' => 13.3801,
        'lng' => 123.4320,
        'mapStatus' => 'plotted',
        'accomplishedBy' => 'Lani Magistrado (BHW)',
        'accomplishedDate' => 'February 4, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level II',
            'status' => 'Safely Managed',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Basic Sanitation Facility',
            'status' => 'Safely Managed Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-004',
                'name' => 'Carlo Evangelista',
                'relationship' => 'Head',
                'age' => 42,
                'sex' => 'Male',
                'occupation' => 'Farmer',
                'last_name' => 'Evangelista',
                'first_name' => 'Carlo',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1983-03-18',
                'relationship_status' => 'Married',
                'monthly_income' => '10,000 – 19,999',
                'religion' => 'Roman Catholic',
                'education' => 'High School Graduate',
                'philhealth' => '223456789012',
                'fp_user' => 'No',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['Hypertension'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '72',
                    'height' => '170',
                    'bmi' => '24.9',
                    'status' => 'Normal',
                ],
            ],
        ],
    ],
    'HH-153' => [
        'householdNo' => 'HH-153',
        'displayNo' => 'HH 153',
        'houseHead' => 'Adrian Corporal',
        'zone' => 'Zone 1',
        'street' => 'Layuan St.',
        'address' => 'Layuan St., Brgy. La Medalla',
        'purok' => 'Zone 1',
        'members' => 10,
        'lat' => 13.3814,
        'lng' => 123.4318,
        'mapStatus' => 'plotted',
        'accomplishedBy' => 'Sarah BNS',
        'accomplishedDate' => 'March 12, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level III',
            'status' => 'Safely Managed',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Basic Sanitation Facility',
            'status' => 'Safely Managed Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-005',
                'name' => 'Adrian Corporal',
                'relationship' => 'Head',
                'age' => 35,
                'sex' => 'Male',
                'occupation' => 'Driver',
                'last_name' => 'Corporal',
                'first_name' => 'Adrian',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1990-07-22',
                'relationship_status' => 'Married',
                'monthly_income' => '20,000 – 29,999',
                'religion' => 'Born Again',
                'education' => 'Vocational',
                'philhealth' => '323456789012',
                'fp_user' => 'No',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '68',
                    'height' => '172',
                    'bmi' => '23.0',
                    'status' => 'Normal',
                ],
            ],
        ],
    ],
    'HH-154' => [
        'householdNo' => 'HH-154',
        'displayNo' => 'HH 154',
        'houseHead' => 'Maria Santos',
        'zone' => 'Zone 4',
        'street' => 'Cateel Bay St.',
        'address' => 'Cateel Bay St., Brgy. La Medalla',
        'purok' => 'Zone 4',
        'members' => 10,
        'lat' => 13.3798,
        'lng' => 123.4308,
        'mapStatus' => 'pending',
        'accomplishedBy' => 'Lani Magistrado (BHW)',
        'accomplishedDate' => 'January 8, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level I',
            'status' => 'Basic Service',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Limited Sanitation Facility',
            'status' => 'Basic Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-006',
                'name' => 'Maria Santos',
                'relationship' => 'Head',
                'age' => 38,
                'sex' => 'Female',
                'occupation' => 'Vendor',
                'last_name' => 'Santos',
                'first_name' => 'Maria',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1987-01-09',
                'relationship_status' => 'Widowed',
                'monthly_income' => '5,000 – 9,999',
                'religion' => 'Roman Catholic',
                'education' => 'High School Graduate',
                'philhealth' => '423456789012',
                'fp_user' => 'No',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['Diabetes Mellitus'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '58',
                    'height' => '155',
                    'bmi' => '24.1',
                    'status' => 'Normal',
                ],
            ],
        ],
    ],
    'HH-155' => [
        'householdNo' => 'HH-155',
        'displayNo' => 'HH 155',
        'houseHead' => 'Juan dela Cruz',
        'zone' => 'Zone 2',
        'street' => 'Layuan St.',
        'address' => 'Layuan St., Brgy. La Medalla',
        'purok' => 'Zone 2',
        'members' => 10,
        'lat' => 13.3809,
        'lng' => 123.4324,
        'mapStatus' => 'pending',
        'accomplishedBy' => 'Sarah BNS',
        'accomplishedDate' => 'April 2, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level II',
            'status' => 'Safely Managed',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Basic Sanitation Facility',
            'status' => 'Safely Managed Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-007',
                'name' => 'Juan dela Cruz',
                'relationship' => 'Head',
                'age' => 45,
                'sex' => 'Male',
                'occupation' => 'Construction Worker',
                'last_name' => 'dela Cruz',
                'first_name' => 'Juan',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1980-11-30',
                'relationship_status' => 'Married',
                'monthly_income' => '20,000 – 29,999',
                'religion' => 'Iglesia ni Cristo',
                'education' => 'Elementary Graduate',
                'philhealth' => '523456789012',
                'fp_user' => 'No',
                'disability' => ['Physical Disability (PD)'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '75',
                    'height' => '168',
                    'bmi' => '26.6',
                    'status' => 'Overweight',
                ],
            ],
        ],
    ],
    'HH-156' => [
        'householdNo' => 'HH-156',
        'displayNo' => 'HH 156',
        'houseHead' => 'Rosa Lim',
        'zone' => 'Zone 3',
        'street' => 'Cateel Bay St.',
        'address' => 'Cateel Bay St., Brgy. La Medalla',
        'purok' => 'Zone 3',
        'members' => 10,
        'lat' => 13.3805,
        'lng' => 123.4310,
        'mapStatus' => 'plotted',
        'accomplishedBy' => 'Lani Magistrado (BHW)',
        'accomplishedDate' => 'May 16, 2026',
        'water' => [
            'title' => 'Access to Safe Water',
            'level' => 'Level III',
            'status' => 'Safely Managed',
        ],
        'sanitation' => [
            'title' => 'Sanitation Services',
            'facility' => 'Basic Sanitation Facility',
            'status' => 'Safely Managed Sanitation Services',
        ],
        'memberList' => [
            [
                'id' => 'MB-008',
                'name' => 'Rosa Lim',
                'relationship' => 'Head',
                'age' => 29,
                'sex' => 'Female',
                'occupation' => 'Teacher',
                'last_name' => 'Lim',
                'first_name' => 'Rosa',
                'middle_name' => '',
                'relation' => 'Head',
                'birthday' => '1996-04-14',
                'relationship_status' => 'Single',
                'monthly_income' => '30,000 – 49,999',
                'religion' => 'Roman Catholic',
                'education' => 'College Graduate',
                'philhealth' => '623456789012',
                'fp_user' => 'No',
                'disability' => ['none'],
                'disability_others' => '',
                'medical_history' => ['none'],
                'medical_others' => '',
                'nutrition' => [
                    'weight' => '52',
                    'height' => '158',
                    'bmi' => '20.8',
                    'status' => 'Normal',
                ],
            ],
        ],
    ],
];
