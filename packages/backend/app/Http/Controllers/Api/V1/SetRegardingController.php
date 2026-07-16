<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailActivityLedger;
use App\Services\Smoh\SmohClientFactory;
use App\Services\Smoh\SmohException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/activities/{ledger}/regarding` — link a logged email to a chosen CRM
 * record (Set Regarding, Dynamics parity Phase B). Re-points the SMOH activity and updates
 * the ledger. {ledger} is looked up manually (not route-model-bound) so the tenant scope,
 * initialized by the tenant.user middleware, applies — same approach as TimelineController.
 */
class SetRegardingController extends Controller
{
    public function __invoke(Request $request, string $ledger, SmohClientFactory $factory): JsonResponse
    {
        $validated = $request->validate([
            'regarding_id' => ['required', 'string', 'max:255'],
            'regarding_type' => ['required', 'string', 'max:100'],
        ]);

        $row = EmailActivityLedger::find($ledger);
        if (! $row instanceof EmailActivityLedger) {
            return response()->json(['message' => 'Activity not found.'], 404);
        }
        if ($row->smoh_activity_id === null) {
            return response()->json(['message' => 'Activity is not logged to the CRM yet.'], 409);
        }

        try {
            $factory->for($request->user()->tenant)->setActivityRegarding(
                $row->smoh_activity_id,
                $validated['regarding_id'],
                $validated['regarding_type'],
            );
        } catch (SmohException) {
            return response()->json(['message' => 'CRM update failed.'], 502);
        }

        $row->forceFill([
            'contact_id' => $validated['regarding_id'],
            'regarding_type' => $validated['regarding_type'],
        ])->save();

        return response()->json([
            'id' => $row->id,
            'regardingId' => $row->contact_id,
            'regardingType' => $row->regarding_type,
        ]);
    }
}
