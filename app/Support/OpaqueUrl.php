<?php

namespace App\Support;

/**
 * Which route parameters carry guessable record ids, and the token kind used for each.
 * Chatbot (resident) routes, password-reset tokens and workflow step numbers are not ids
 * of staff records and stay as they are.
 */
final class OpaqueUrl
{
    /** Environmental Health wizard route whose ?household= query names a household. */
    public const EH_QUERY_ROUTE = 'environmental-health/household-water-supply';

    public static function kind(string $parameter, string $routeUri): ?string
    {
        return match ($parameter) {
            'householdNo' => 'h',
            'memberId' => 'm',
            'residentId' => 'r',
            'assessmentId' => 's',
            'visitId' => 'v',
            'pregnancyId' => 'p',
            'measurementId' => 'x',
            'childKey' => 'c',
            'clientKey' => 'k',
            'announcement' => 'a',
            'deathRequest' => 'd',
            'id' => match (true) {
                str_starts_with($routeUri, 'user-management/health-workers') => 'w',
                str_starts_with($routeUri, 'user-management/residents') => 't',
                str_starts_with($routeUri, 'household-requests') => 'q',
                default => null,
            },
            default => null,
        };
    }
}
