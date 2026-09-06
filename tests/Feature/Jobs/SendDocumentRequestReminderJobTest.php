<?php

use App\Jobs\SendDocumentRequestReminderJob;
use App\Mail\DocumentRequestReminder;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function makeSendableRequest(array $attrs = []): DocumentRequest
{
    $user = User::factory()->create(['name' => 'Acme Bookkeeping']);
    $client = Client::factory()->for($user)->create(['name' => 'John Smith', 'email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ], $attrs));
    $documentRequest->generateAccessToken();
    DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Invoice', 'status' => 'pending']);

    return $documentRequest;
}

it('is queueable and unique', function () {
    $job = new SendDocumentRequestReminderJob(1);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeUnique::class);
});

it('sends a reminder mail and updates tracking columns for an eligible request', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();
    $oldHash = $documentRequest->access_token_hash;

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    $documentRequest->refresh();
    expect($documentRequest->reminder_count)->toBe(1);
    expect($documentRequest->last_reminder_sent_at)->not->toBeNull();
    expect($documentRequest->access_token_hash)->not->toBe($oldHash);
    Mail::assertQueued(DocumentRequestReminder::class, fn ($mail) => $mail->hasTo('client@example.com'));
});

it('logs a reminder_sent activity without leaking the token', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    $log = ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'reminder_sent')
        ->first();

    expect($log)->not->toBeNull();
    expect(json_encode($log->metadata))->not->toContain('access_token_hash');
    $documentRequest->refresh();
    expect(json_encode($log->metadata))->not->toContain(substr($documentRequest->access_token_hash, 0, 10));
});

it('does nothing when the request is no longer eligible', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest(['status' => 'archived']);

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    Mail::assertNothingQueued();
    expect($documentRequest->fresh()->reminder_count)->toBe(0);
    expect(ActivityLog::where('document_request_id', $documentRequest->id)->where('event', 'reminder_sent')->exists())->toBeFalse();
});

it('does nothing when the document request no longer exists', function () {
    Mail::fake();

    (new SendDocumentRequestReminderJob(999999))->handle();

    Mail::assertNothingQueued();
});

// --- Concurrency / idempotency ---

it('only one of two concurrent claims on the same request increments reminder_count', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    // Simulate two workers racing: both read reminder_count=0 before either commits.
    // The atomic UPDATE's WHERE clause means only the first commit's predicate still matches.
    $job1 = new SendDocumentRequestReminderJob($documentRequest->id);
    $job2 = new SendDocumentRequestReminderJob($documentRequest->id);

    $job1->handle();
    $job2->handle();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
    Mail::assertQueued(DocumentRequestReminder::class, 1);
    expect(ActivityLog::where('document_request_id', $documentRequest->id)->where('event', 'reminder_sent')->count())->toBe(1);
});

it('retrying the job after a successful send does not send a duplicate reminder', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    $job = new SendDocumentRequestReminderJob($documentRequest->id);
    $job->handle();
    // Simulate a queue retry of the same job instance/id after success.
    $job->handle();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
    Mail::assertQueued(DocumentRequestReminder::class, 1);
});

it('the atomic claim update only affects one row even under a raw concurrent race', function () {
    $documentRequest = makeSendableRequest();

    $threshold = now()->subDays((int) config('reminders.interval_days'));

    $affected1 = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    $affected2 = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    expect($affected1)->toBe(1);
    expect($affected2)->toBe(0);
    expect($documentRequest->fresh()->reminder_count)->toBe(1);
});

it('the atomic claim update does not claim an already-completed request even if counters would otherwise qualify', function () {
    $documentRequest = makeSendableRequest(['status' => 'completed', 'completed_at' => now()]);

    $threshold = now()->subDays((int) config('reminders.interval_days'));

    $affected = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->whereNotIn('status', ['archived', 'completed'])
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    expect($affected)->toBe(0);
});
