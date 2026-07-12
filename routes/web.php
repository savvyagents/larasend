<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityExportController;
use App\Http\Controllers\DashboardActionController;
use App\Http\Controllers\EmailMimeController;
use App\Http\Controllers\EmailPreviewController;
use App\Http\Controllers\InboundAttachmentController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ThreadActionController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Support\RegistrationAvailability;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (! config('larasend.show_landing_page')) {
        return redirect()->route(RegistrationAvailability::isOpen() ? 'register' : 'login');
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration())
            && RegistrationAvailability::isOpen(),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [OnboardingController::class, 'entry'])->name('dashboard');
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('onboarding/validate', [OnboardingController::class, 'validateCredentials'])->name('onboarding.validate');
    Route::get('projects', ActivityController::class)->defaults('section', 'projects')->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('workspace/members', [WorkspaceMemberController::class, 'store'])->name('workspace-members.store');
    Route::put('workspace/members/{user}', [WorkspaceMemberController::class, 'update'])->name('workspace-members.update');
    Route::delete('workspace/members/{user}', [WorkspaceMemberController::class, 'destroy'])->name('workspace-members.destroy');

    Route::prefix('projects/{project}')->name('projects.')->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->name('show');
        Route::get('activity/export', ActivityExportController::class)->name('activity.export');
        Route::put('source', [DashboardActionController::class, 'updateSource'])->name('source.update');
        Route::post('source/quota', [DashboardActionController::class, 'syncSourceQuota'])->name('source.quota.sync');
        Route::post('domains', [DashboardActionController::class, 'storeDomain'])->name('domains.store');
        Route::post('domains/{domain}/check-dns', [DashboardActionController::class, 'checkProjectDomain'])->name('domains.check-dns');
        Route::post('domains/{domain}/inbound', [DashboardActionController::class, 'enableProjectDomainInbound'])->name('domains.inbound');
        Route::delete('domains/{domain}', [DashboardActionController::class, 'destroyProjectDomain'])->name('domains.destroy');
        Route::post('templates', [DashboardActionController::class, 'storeTemplate'])->name('templates.store');
        Route::post('api-keys', [DashboardActionController::class, 'storeApiKey'])->name('api-keys.store');
        Route::post('api-keys/{apiKey}/rotate', [DashboardActionController::class, 'rotateApiKey'])->name('api-keys.rotate');
        Route::delete('api-keys/{apiKey}', [DashboardActionController::class, 'destroyApiKey'])->name('api-keys.destroy');
        Route::post('webhooks', [DashboardActionController::class, 'storeWebhookEndpoint'])->name('webhooks.store');
        Route::put('webhooks/{endpoint:public_id}', [DashboardActionController::class, 'updateWebhookEndpoint'])->name('webhooks.update');
        Route::post('send', [DashboardActionController::class, 'sendEmail'])->name('send.store');
        Route::post('bounces/retry-soft', [DashboardActionController::class, 'retrySoftBounces'])->name('bounces.retry-soft');
        Route::post('emails/{email:public_id}/resend', [DashboardActionController::class, 'resendProjectEmail'])->name('emails.resend');
        Route::get('inbox', InboxController::class)->name('inbox');
        Route::post('threads/{thread:public_id}/read', [ThreadActionController::class, 'read'])->name('threads.read');
        Route::post('threads/{thread:public_id}/unread', [ThreadActionController::class, 'unread'])->name('threads.unread');
        Route::post('threads/{thread:public_id}/archive', [ThreadActionController::class, 'archive'])->name('threads.archive');
        Route::post('threads/{thread:public_id}/unarchive', [ThreadActionController::class, 'unarchive'])->name('threads.unarchive');
        Route::post('threads/{thread:public_id}/reply', [ThreadActionController::class, 'reply'])->name('threads.reply');
        Route::post('threads/{thread:public_id}/forward', [ThreadActionController::class, 'forward'])->name('threads.forward');
        Route::post('threads/{thread:public_id}/snooze', [ThreadActionController::class, 'snooze'])->name('threads.snooze');
        Route::post('threads/{thread:public_id}/unsnooze', [ThreadActionController::class, 'unsnooze'])->name('threads.unsnooze');
        Route::post('threads/{thread:public_id}/notes', [ThreadActionController::class, 'storeNote'])->name('threads.notes.store');
        Route::post('inbox/compose', [ThreadActionController::class, 'compose'])->name('inbox.compose');
        Route::get('inbound/{inboundEmail:public_id}/attachments/{index}', InboundAttachmentController::class)
            ->whereNumber('index')
            ->name('inbound.attachments');
        Route::get('{section}', ActivityController::class)
            ->where('section', 'activity|sent|inbound|bounces|complaints|suppressions|source|identities|templates|webhooks|api-keys|send|setup|projects')
            ->name('section');
    });

    Route::get('activity', ActivityController::class)->defaults('section', 'activity')->name('activity');
    Route::get('activity/export', ActivityExportController::class)->name('activity.export');
    Route::get('sent', ActivityController::class)->defaults('section', 'sent')->name('sent');
    Route::get('inbound', ActivityController::class)->defaults('section', 'inbound')->name('inbound');
    Route::get('inbox', InboxController::class)->name('inbox');
    Route::get('bounces', ActivityController::class)->defaults('section', 'bounces')->name('bounces');
    Route::get('complaints', ActivityController::class)->defaults('section', 'complaints')->name('complaints');
    Route::get('suppressions', ActivityController::class)->defaults('section', 'suppressions')->name('suppressions');
    Route::get('identities', ActivityController::class)->defaults('section', 'identities')->name('identities');
    Route::get('templates', ActivityController::class)->defaults('section', 'templates')->name('templates');
    Route::get('webhooks', ActivityController::class)->defaults('section', 'webhooks')->name('webhooks');
    Route::get('api-keys', ActivityController::class)->defaults('section', 'api-keys')->name('api-keys');
    Route::get('send', ActivityController::class)->defaults('section', 'send')->name('send');
    Route::get('setup', ActivityController::class)->defaults('section', 'setup')->name('setup');
    Route::get('source', ActivityController::class)->defaults('section', 'source')->name('source');
    Route::post('domains', [DashboardActionController::class, 'storeDomain'])->name('domains.store');
    Route::post('domains/{domain}/check-dns', [DashboardActionController::class, 'checkDomain'])->name('domains.check-dns');
    Route::post('domains/{domain}/inbound', [DashboardActionController::class, 'enableDomainInbound'])->name('domains.inbound');
    Route::delete('domains/{domain}', [DashboardActionController::class, 'destroyDomain'])->name('domains.destroy');
    Route::put('source', [DashboardActionController::class, 'updateSource'])->name('source.update');
    Route::post('templates', [DashboardActionController::class, 'storeTemplate'])->name('templates.store');
    Route::post('api-keys', [DashboardActionController::class, 'storeApiKey'])->name('api-keys.store');
    Route::post('api-keys/{apiKey}/rotate', [DashboardActionController::class, 'rotateApiKey'])->name('api-keys.rotate');
    Route::delete('api-keys/{apiKey}', [DashboardActionController::class, 'destroyApiKey'])->name('api-keys.destroy');
    Route::post('webhooks', [DashboardActionController::class, 'storeWebhookEndpoint'])->name('webhooks.store');
    Route::put('webhooks/{endpoint:public_id}', [DashboardActionController::class, 'updateWebhookEndpoint'])->name('webhooks.update');
    Route::post('send', [DashboardActionController::class, 'sendEmail'])->name('send.store');
    Route::post('bounces/retry-soft', [DashboardActionController::class, 'retrySoftBounces'])->name('bounces.retry-soft');
    Route::post('emails/{email:public_id}/resend', [DashboardActionController::class, 'resendEmail'])->name('emails.resend');
    Route::get('emails/{email:public_id}/preview', EmailPreviewController::class)->name('emails.preview');
    Route::get('emails/{email:public_id}/mime', EmailMimeController::class)->name('emails.mime');
});

require __DIR__.'/settings.php';
