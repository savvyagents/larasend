<?php

use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Webhooks\SesWebhookController;
use App\Http\Middleware\AuthenticateLarasendApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateLarasendApiKey::class)->group(function () {
    Route::get('emails', [EmailController::class, 'index']);
    Route::post('emails', [EmailController::class, 'store']);
    Route::get('emails/{email}', [EmailController::class, 'show']);
});

Route::post('webhooks/ses/{token}', SesWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ses');
