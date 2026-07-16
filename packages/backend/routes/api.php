<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthExchangeController;
use App\Http\Controllers\Api\V1\ContactMatchController;
use App\Http\Controllers\Api\V1\DevLoginController;
use App\Http\Controllers\Api\V1\EmailActivityController;
use App\Http\Controllers\Api\V1\TimelineController;
use App\Http\Controllers\Dev\MockSmohController;
use App\Http\Controllers\Gmail\GmailAddonController;
use App\Http\Controllers\Webhooks\GmailPushController;
use App\Http\Controllers\Webhooks\GraphNotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: exchange a verified provider OIDC token for a first-party Sanctum token.
    Route::post('auth/exchange', AuthExchangeController::class);

    // DEV/DEMO ONLY: mint a token for the demo user with no real OIDC (see config flag).
    if (config('mail_tracker.dev_auth')) {
        Route::post('auth/dev-login', DevLoginController::class);
    }

    // Authenticated client API. `tenant.user` initializes tenancy from the Sanctum user.
    Route::middleware(['auth:sanctum', 'tenant.user'])->group(function () {
        Route::get('me', fn (Request $request) => response()->json([
            'id' => $request->user()->id,
            'email' => $request->user()->email,
            'provider' => $request->user()->provider->value,
            'tenant_id' => $request->user()->tenant_id,
        ]));

        Route::post('contacts/match', ContactMatchController::class)
            ->middleware('ability:contacts:read');

        Route::post('activities/email', EmailActivityController::class)
            ->middleware('ability:activities:write');

        Route::get('contacts/{contact}/timeline', TimelineController::class)
            ->middleware('ability:contacts:read');
    });
});

/*
| Zero-touch ingestion webhooks (Phase 2). Public — authenticity is per-request
| (Graph clientState / Gmail Pub/Sub OIDC token), so no Sanctum guard. See §4.7.
*/
Route::prefix('webhooks')->group(function () {
    // Graph sends a POST validation handshake (?validationToken) then POST notifications.
    Route::match(['get', 'post'], 'graph/notifications', GraphNotificationController::class)
        ->name('webhooks.graph');
    Route::post('gmail/push', GmailPushController::class)->name('webhooks.gmail');
});

/*
| Gmail Workspace Add-on (alternate runtime, §6). Google POSTs event JSON; we return
| CardService JSON. Public — the add-on's userIdToken (in the body) is verified per call.
*/
Route::prefix('gmail')->group(function () {
    Route::post('addon/contextual', [GmailAddonController::class, 'contextual'])->name('gmail.contextual');
    Route::post('actions/log', [GmailAddonController::class, 'logAction'])->name('gmail.actions.log');
});

/*
| DEV/DEMO ONLY: in-backend mock SMOH CRM (OData v4 + /auth/login) so the CRM-depth
| features can be built against a real SMOH-like service before a real one exists. The
| controller self-gates to dev (MAIL_TRACKER_DEV_AUTH / local) and 404s in production.
| Point a tenant's smoh_base_url at {APP_URL}/api/mock-smoh. See DYNAMICS-PARITY.md.
*/
Route::prefix('mock-smoh')->group(function () {
    Route::post('auth/login', [MockSmohController::class, 'login']);
    Route::get('odata/{set}', [MockSmohController::class, 'query']);
    Route::post('odata/{set}', [MockSmohController::class, 'create']);
});
