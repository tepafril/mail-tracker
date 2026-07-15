<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Smoh\SmohClientFactory;
use App\Services\Smoh\SmohException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `GET /api/v1/contacts/{contact}/timeline` — the contact's logged email activities,
 * newest first, straight from the tenant's SMOH instance (MASTER-PLAN §4.5).
 */
class TimelineController extends Controller
{
    public function __invoke(Request $request, string $contact, SmohClientFactory $factory): JsonResponse
    {
        // SMOH contact ids are GUIDs, and the id is interpolated into an OData $filter
        // as a bare Edm.Guid literal (unquoted, so escaping can't protect it). Reject
        // anything that isn't a GUID to prevent $filter injection.
        if (! Str::isUuid($contact)) {
            return response()->json(['message' => 'Invalid contact id.'], 422);
        }

        $top = (int) $request->integer('top', 50);
        $top = max(1, min($top, 200));

        $tenant = $request->user()->tenant;

        try {
            $rows = $factory->for($tenant)->timeline($contact, $top);
        } catch (SmohException) {
            return response()->json(['message' => 'CRM timeline fetch failed.'], 502);
        }

        return response()->json(['contactId' => $contact, 'items' => $rows]);
    }
}
