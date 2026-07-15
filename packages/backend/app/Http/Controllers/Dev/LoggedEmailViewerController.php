<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\EmailActivityLedger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DEV/DEMO ONLY. A human-readable page of every logged email, with the encrypted content
 * decrypted for viewing. Gated to local env / demo mode because it exposes decrypted PII;
 * it must never be reachable in production.
 */
class LoggedEmailViewerController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(app()->environment('local') || config('mail_tracker.dev_auth'), 404);

        // No tenant scope here (dev viewer): show every tenant's rows, newest first.
        $emails = EmailActivityLedger::query()
            ->withoutGlobalScopes()
            ->latest()
            ->limit(200)
            ->get();

        return view('dev.emails', ['emails' => $emails]);
    }
}
