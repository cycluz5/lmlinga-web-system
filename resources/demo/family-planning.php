<?php

/**
 * Demo Family Planning visit history keyed by household member.
 *
 * UI-preview catalog only — no persistence.
 * Absence of entries for a member is a valid empty state.
 *
 * Commodity names/quantities are fixture display data for the Main table,
 * not a clinical commodity catalog or eligibility rules.
 *
 * @return array<string, array<string, list<array<string, mixed>>>>
 */
return [
    'HH-151' => [
        'MB-002' => [
            [
                'id' => 'FP-001',
                'visited_at' => '2026-06-08',
                'remarks' => 'Commodities provided during follow-up visit.',
                'commodities' => [
                    ['name' => 'Pills', 'quantity' => 10],
                    ['name' => 'Condoms', 'quantity' => 3],
                ],
            ],
            [
                'id' => 'FP-002',
                'visited_at' => '2026-05-01',
                'remarks' => 'Injectable contraceptive given.',
                'commodities' => [
                    ['name' => 'DMPA', 'quantity' => 1],
                ],
            ],
            [
                'id' => 'FP-003',
                'visited_at' => '2025-10-08',
                'remarks' => 'Pills given to the resident.',
                'commodities' => [
                    ['name' => 'Pills', 'quantity' => 3],
                ],
            ],
        ],
    ],
];
