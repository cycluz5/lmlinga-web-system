<?php

/**
 * LMLinga — UI-phase Household Amenities demo records.
 *
 * Keyed by canonical household number (HH-151 … HH-156) from the
 * Household Profiling demo list. Used only for Amenities Details / Edit
 * preview. Not a database. Not synchronized with Spot Mapping plots.
 *
 * Field values are machine values; server derivation fills statuses.
 *
 * @return array<string, array<string, mixed>>
 */

use App\Support\DemoHouseholdWaterSupply;

return [
    'HH-151' => [
        'household_type' => 'HHTS',
        'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
        'specify_water_source' => null,
        'water_source_location' => 'yes',
        'water_availability' => 'yes',
        'microbiological_test_date' => '2026-06-10',
        'microbiological_result' => 'passed',
        'physicochemical_test_date' => '2026-06-12',
        'physicochemical_result' => 'passed',
        'toilet_type' => 'pour_flush_with_septic_tank',
        'open_defecation_practiced' => 'no',
        'shared_toilet' => 'no',
        'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
        'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
    ],

    'HH-152' => [
        'household_type' => 'Non-HHTS',
        'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
        'specify_water_source' => 'Open dug well',
        'water_source_location' => 'no',
        'water_availability' => 'no',
        'microbiological_test_date' => null,
        'microbiological_result' => null,
        'physicochemical_test_date' => null,
        'physicochemical_result' => null,
        'toilet_type' => 'open_pit_latrine',
        'open_defecation_practiced' => 'yes',
        'shared_toilet' => 'yes',
        'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
        'solid_waste_practices' => [
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
        ],
    ],

    'HH-153' => [
        'household_type' => 'HHTS',
        'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_II,
        'specify_water_source' => null,
        'water_source_location' => 'yes',
        'water_availability' => 'no',
        'microbiological_test_date' => '2026-05-01',
        'microbiological_result' => 'passed',
        'physicochemical_test_date' => '2026-05-03',
        'physicochemical_result' => 'failed',
        'toilet_type' => 'open_pit_latrine',
        'open_defecation_practiced' => 'yes',
        'shared_toilet' => 'no',
        'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
        'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
    ],

    'HH-154' => [
        'household_type' => 'HHTS',
        'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
        'specify_water_source' => null,
        'water_source_location' => 'yes',
        'water_availability' => 'yes',
        'microbiological_test_date' => '2026-04-18',
        'microbiological_result' => 'passed',
        'physicochemical_test_date' => null,
        'physicochemical_result' => null,
        'toilet_type' => 'ventilated_improved_pit_latrine',
        'open_defecation_practiced' => 'no',
        'shared_toilet' => 'no',
        'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
        'solid_waste_practices' => [
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
        ],
    ],

    'HH-155' => [
        'household_type' => 'Non-HHTS',
        'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
        'specify_water_source' => null,
        'water_source_location' => 'yes',
        'water_availability' => 'yes',
        'microbiological_test_date' => '2026-03-08',
        'microbiological_result' => 'passed',
        'physicochemical_test_date' => '2026-03-09',
        'physicochemical_result' => 'passed',
        'toilet_type' => 'pour_flush_connected_to_septic_or_sewer',
        'open_defecation_practiced' => 'no',
        'shared_toilet' => 'no',
        'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
        'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
    ],

    'HH-156' => [
        'household_type' => 'HHTS',
        'water_supply_status' => '',
        'specify_water_source' => null,
        'water_source_location' => '',
        'water_availability' => '',
        'microbiological_test_date' => null,
        'microbiological_result' => null,
        'physicochemical_test_date' => null,
        'physicochemical_result' => null,
        'toilet_type' => '',
        'open_defecation_practiced' => '',
        'shared_toilet' => '',
        'sewage_disposal_method' => null,
        'solid_waste_practices' => [],
    ],
];
