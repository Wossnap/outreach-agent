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
|
| `ingest` is the legacy ability, accepted on POST /contacts only, so keys
| issued before abilities existed keep working.
*/

Route::get('/health', HealthController::class);

Route::middleware('api.auth:read')->group(function () {
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

Route::middleware('api.auth:write,ingest')->group(function () {
    // The lead-magnet route: upsert a contact and enroll them by tag.
    Route::post('/contacts', [ContactIngestController::class, 'store']);
});

Route::middleware('api.auth:write')->group(function () {
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
});

/*
| Guarded actions. Each removes a safeguard rather than just moving data, so
| both need a config switch on top of the key's abilities. Off by default.
*/

Route::middleware(['api.auth:approve', 'api.enabled:allow_approval'])->group(function () {
    // Sends a real email with no human having read it.
    Route::post('/messages/{message}/approve', [MessageController::class, 'approve']);
});

Route::middleware(['api.auth:write', 'api.enabled:allow_suppression_removal'])->group(function () {
    // Resumes emailing someone who asked us to stop.
    Route::delete('/suppressions/{email}', [SuppressionController::class, 'destroy']);
});
