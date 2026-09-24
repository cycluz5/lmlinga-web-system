<?php

namespace App\Support;

/**
 * Presentation-only household number formatting for chatbot surfaces.
 * Does not alter stored households.household_no values.
 */
final class ChatbotHouseholdNumberDisplay
{
    public static function format(?string $householdNo): string
    {
        $householdNo = trim((string) $householdNo);

        if ($householdNo === '') {
            return '-';
        }

        if (preg_match('/^hh\b/i', $householdNo) === 1) {
            return $householdNo;
        }

        return 'HH '.$householdNo;
    }
}
