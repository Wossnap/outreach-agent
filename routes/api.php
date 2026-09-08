<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactIngestController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MailboxController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ReplyController;
use App\Http\Controllers\Api\SequenceStepController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SuppressionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Outreach API
|--------------------------------------------------------------------------
| Bearer auth with a key created in the dashboard under Settings > API keys.
| Each route lists the abilities that satisfy it and a key needs any one of
| them: `read` to GET, `write` to change data, `approve` to release an email.
*/

/*
| Route parameters that are database ids are constrained to digits.
|
| A controller taking an int and a URL supplying "abc" is a TypeError, which
| answers a mistyped URL with a 500 as though the server were broken. With the
| constraint the route simply does not match and the caller gets a 404.
|
| Deliberately not constrained: `contact` and `automation` accept an id or an
| email/tag, `email` is an address, and `attachment` is a generated id.
*/
// A variable, not a constant: this file is included more than once in the
// test suite, and redefining a constant is a fatal error.
$numericIds = ['mailbox', 'message', 'reply', 'enrollment', 'step'];

Route::get('/health', HealthController::class);

Route::middleware('api.auth:read')->whereNumber($numericIds)->group(function () {
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::get('/contacts/{contact}', [ContactController::class, 'show']);

    Route::get('/replies', [ReplyController::class, 'index']);
    Route::get('/replies/{reply}', [ReplyController::class, 'show']);

    Route::get('/suppressions', [SuppressionController::class, 'index']);

    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/{message}', [MessageController::class, 'show']);

    Route::get('/enrollments', [EnrollmentController::class, 'index']);

    Route::get('/automations', [AutomationController::class, 'index']);
    Route::get('/automations/{automation}', [AutomationController::class, 'show']);
    Route::get('/automations/{automation}/steps', [SequenceStepController::class, 'index']);

    Route::get('/mailboxes', [MailboxController::class, 'index']);
    Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'show']);

    Route::get('/activity', [ActivityController::class, 'index']);
    Route::get('/stats', [StatsController::class, 'index']);
});

Route::middleware(['api.auth:write', 'throttle:push-contacts'])->whereNumber($numericIds)->group(function () {
    // The lead-magnet route: upsert a contact and enroll them by tag.
    Route::post('/contacts', [ContactIngestController::class, 'store']);
});

Route::middleware('api.auth:write')->whereNumber($numericIds)->group(function () {
    Route::patch('/messages/{message}', [MessageController::class, 'update']);
    Route::post('/messages/{message}/reject', [MessageController::class, 'reject']);

    Route::post('/enrollments', [EnrollmentController::class, 'store']);
    Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);

    Route::post('/suppressions', [SuppressionController::class, 'store']);

    Route::post('/automations', [AutomationController::class, 'store']);
    Route::patch('/automations/{automation}', [AutomationController::class, 'update']);

    Route::post('/automations/{automation}/steps', [SequenceStepController::class, 'store']);
    Route::patch('/automations/{automation}/steps/{step}', [SequenceStepController::class, 'update']);
    Route::delete('/automations/{automation}/steps/{step}', [SequenceStepController::class, 'destroy']);

    Route::post('/automations/{automation}/steps/{step}/attachments', [SequenceStepController::class, 'storeAttachment']);
    Route::delete('/automations/{automation}/steps/{step}/attachments/{attachment}', [SequenceStepController::class, 'destroyAttachment']);

    Route::post('/mailboxes/{mailbox}/pause', [MailboxController::class, 'pause']);
    Route::post('/mailboxes/{mailbox}/resume', [MailboxController::class, 'resume']);
    // Not guarded by a config switch: it only ever stops sending, and it is
    // reversible by reconnecting.
    Route::post('/mailboxes/{mailbox}/disconnect', [MailboxController::class, 'disconnect']);
});

/*
| Guarded actions. Each removes a safeguard rather than just moving data, so
| both need a config switch on top of the key's abilities. Off by default.
*/

Route::middleware(['api.auth:approve', 'api.enabled:allow_approval'])->whereNumber($numericIds)->group(function () {
    // Sends a real email with no human having read it.
    Route::post('/messages/{message}/approve', [MessageController::class, 'approve']);
});

Route::middleware(['api.auth:write', 'api.enabled:allow_suppression_removal'])->whereNumber($numericIds)->group(function () {
    // Resumes emailing someone who asked us to stop.
    Route::delete('/suppressions/{email}', [SuppressionController::class, 'destroy']);
});
