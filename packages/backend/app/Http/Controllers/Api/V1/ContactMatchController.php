<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MatchContactRequest;
use App\Services\Smoh\SmohClientFactory;
use App\Services\Smoh\SmohException;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/contacts/match` — resolve an email address to a SMOH contact id for the
 * current tenant. Powers the add-in's "is this a known contact?" check before logging.
 */
class ContactMatchController extends Controller
{
    public function __invoke(MatchContactRequest $request, SmohClientFactory $factory): JsonResponse
    {
        $tenant = $request->user()->tenant;

        try {
            $contactId = $factory->for($tenant)->findContactByEmail($request->string('email')->value());
        } catch (SmohException $e) {
            return response()->json(['message' => 'CRM lookup failed.'], 502);
        }

        return response()->json(['contactId' => $contactId]);
    }
}
