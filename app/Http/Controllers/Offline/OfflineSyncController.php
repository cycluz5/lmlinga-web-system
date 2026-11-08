<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offline\SyncOfflineOperationRequest;
use App\Models\User;
use App\Services\Offline\OfflineSyncService;
use App\Support\Offline\OfflineSyncException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

class OfflineSyncController extends Controller
{
    public function store(SyncOfflineOperationRequest $request, OfflineSyncService $sync): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return OfflineSyncException::sessionExpired()->toResponse();
        }

        try {
            return response()->json($sync->process($user, $request->offlineEnvelope()));
        } catch (OfflineSyncException $e) {
            return $e->toResponse();
        } catch (QueryException|PDOException $e) {
            report($e);

            return OfflineSyncException::retryable()->toResponse();
        } catch (\Throwable $e) {
            report($e);

            return OfflineSyncException::retryable()->toResponse();
        }
    }
}
