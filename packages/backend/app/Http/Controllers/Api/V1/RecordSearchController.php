<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Smoh\SmohClientFactory;
use App\Services\Smoh\SmohException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/records?q=` — search the tenant's SMOH for contacts/leads/accounts to
 * populate the add-in's "Set Regarding" picker (Dynamics parity, Phase B).
 */
class RecordSearchController extends Controller
{
    public function __invoke(Request $request, SmohClientFactory $factory): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['records' => []]);
        }

        try {
            $records = $factory->for($request->user()->tenant)->searchRecords($query);
        } catch (SmohException) {
            return response()->json(['message' => 'CRM search failed.'], 502);
        }

        return response()->json(['records' => $records]);
    }
}
