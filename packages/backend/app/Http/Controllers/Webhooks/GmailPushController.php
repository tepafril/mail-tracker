<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\GmailHistoryJob;
use App\Models\GmailWatch;
use App\Services\Auth\GooglePushVerifier;
use App\Services\Auth\TokenVerificationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gmail Cloud Pub/Sub push endpoint (MASTER-PLAN §4.7). Verifies the push OIDC token,
 * decodes the notification, and dispatches history processing. Returns 204 to ACK.
 * A non-2xx makes Pub/Sub redeliver, so we only 4xx on a genuine auth failure.
 */
class GmailPushController extends Controller
{
    public function __invoke(Request $request, GooglePushVerifier $verifier): Response
    {
        try {
            $verifier->verify($request);
        } catch (TokenVerificationException $e) {
            return response('', 403);
        }

        $message = (array) $request->input('message', []);
        $data = base64_decode((string) ($message['data'] ?? ''), true);
        $decoded = is_string($data) ? json_decode($data, true) : null;

        if (is_array($decoded) && isset($decoded['emailAddress'], $decoded['historyId'])) {
            $watch = GmailWatch::withoutGlobalScopes()
                ->whereHas('user', fn ($q) => $q->where('email', $decoded['emailAddress']))
                ->first();

            if ($watch instanceof GmailWatch) {
                GmailHistoryJob::dispatch($watch->tenant_id, $watch->id, (int) $decoded['historyId'])
                    ->onQueue('sync');
            }
        }

        return response('', 204);
    }
}
