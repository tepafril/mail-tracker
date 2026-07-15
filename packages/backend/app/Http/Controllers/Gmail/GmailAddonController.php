<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gmail;

use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAddonVerifier;
use App\Services\Auth\TenantResolver;
use App\Services\Auth\TokenVerificationException;
use App\Services\Gmail\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gmail Workspace Add-on endpoints (alternate runtime, MASTER-PLAN §6). Google POSTs
 * event JSON here and we return CardService (Card V1) JSON. There is NO client-side
 * on-send interception in Gmail (§6.2) — the contextual card is best-effort, and full
 * outbound capture is server-side (Phase 2 sync).
 */
class GmailAddonController extends Controller
{
    /** Contextual (message-open) trigger: render the CRM card for the open message. */
    public function contextual(Request $request, GoogleAddonVerifier $verifier, TenantResolver $resolver): JsonResponse
    {
        $event = $request->json()->all();

        try {
            $identity = $verifier->verify($event);
        } catch (TokenVerificationException) {
            return response()->json(CardService::notification('Please sign in to Mail Tracker.'), 401);
        }

        $tenant = $resolver->resolveTenant($identity);
        if ($tenant === null) {
            return response()->json(CardService::renderCards([
                CardService::card('SMOH Mail Tracker', null, [
                    CardService::section([
                        CardService::textParagraph('Your organization is not onboarded for CRM tracking.'),
                    ]),
                ]),
            ]));
        }

        $user = $resolver->provisionUser($tenant, $identity);
        $messageId = (string) data_get($event, 'gmail.messageId', '');

        $card = CardService::card('Log to SMOH CRM', $user->email, [
            CardService::section([
                CardService::textParagraph('Track this email against your CRM contact.'),
                CardService::actionButton('Log to CRM', route('gmail.actions.log'), [
                    'messageId' => $messageId,
                ]),
            ]),
        ]);

        return response()->json(CardService::renderCards([$card]));
    }

    /**
     * "Log to CRM" button action. Full message extraction + SMOH match runs server-side
     * via the Gmail API using the event's scoped token — that is Phase 2 (needs
     * gmail.readonly/metadata). Here we verify the caller and acknowledge.
     */
    public function logAction(Request $request, GoogleAddonVerifier $verifier): JsonResponse
    {
        $event = $request->json()->all();

        try {
            $verifier->verify($event);
        } catch (TokenVerificationException) {
            return response()->json(CardService::notification('Please sign in to Mail Tracker.'), 401);
        }

        // TODO(Phase 2): fetch the message via the Gmail API (gmail.addons scoped token),
        // build an EmailMessageData, and hand it to EmailIngestionService::ingest().
        return response()->json(CardService::notification('This email will be logged to your CRM.'));
    }
}
