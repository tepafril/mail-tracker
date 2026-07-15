<?php

use App\Http\Controllers\Dev\LoggedEmailViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// DEV/DEMO ONLY: human-readable viewer of logged emails (decrypted). Gated in the
// controller to local env / demo mode. See §7.4 (PII).
Route::get('/dev/emails', LoggedEmailViewerController::class)->name('dev.emails');
