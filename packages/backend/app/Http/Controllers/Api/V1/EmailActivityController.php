<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DataObjects\EmailMessageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogEmailRequest;
use App\Services\Email\EmailIngestionService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/activities/email` — accept a captured email from a thin client, dedup
 * it on the ledger, and dispatch the async match+log to SMOH (MASTER-PLAN §4.5).
 * Returns quickly (202) so the Outlook OnMessageSend handler never blocks the user.
 */
class EmailActivityController extends Controller
{
    public function __invoke(LogEmailRequest $request, EmailIngestionService $ingestion): JsonResponse
    {
        $user = $request->user();

        $message = EmailMessageData::fromArray($request->validated());

        $result = $ingestion->ingest($user, $message, source: 'client');

        // On the sync queue the job has already run and updated the row; reload so the
        // response reflects the final status (on async queues it stays 'pending').
        $result->ledger->refresh();

        return response()->json([
            'ledgerId' => $result->ledger->id,
            'status' => $result->ledger->status->value,
            'deduped' => $result->deduped,
            'smohActivityId' => $result->ledger->smoh_activity_id,
        ], $result->deduped ? 200 : 202);
    }
}
