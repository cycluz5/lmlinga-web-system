<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StaffNameSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 4 — GET /staff/name-suggestions?q=… (JSON, staff only, zone-scoped). See StaffNameSuggestionService.
 */
class StaffNameSuggestionController extends Controller
{
    public function residents(Request $request, StaffNameSuggestionService $suggestions): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! StaffNameSuggestionService::mayUse($actor)) {
            return response()->json(['message' => 'Not available.'], 403);
        }

        $query = $request->query('q');

        return response()
            ->json($suggestions->residents($actor, is_string($query) ? $query : ''))
            ->header('Cache-Control', 'no-store, private');
    }
}
