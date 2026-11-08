<?php

namespace App\Support;

final class HouseholdRecordRequestOptions
{
    /**
     * @return list<string>
     */
    public static function relationships(): array
    {
        return [
            'Household Head',
            'Spouse',
            'Son',
            'Daughter',
            'Parent',
            'Sibling',
            'Grandparent',
            'Grandchild',
            'Relative',
            'Non-relative household member',
            'Other',
        ];
    }
}
