<?php

use App\Jobs\SendDocumentRequestReminderJob;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

function makeEligibleForCommand(): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ]);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'pending']);

    return $documentRequest;
}

it('dispatches a reminder job for each eligible request', function () {
    Bus::fake();
    $eligible = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligible->id);
});

it('does not dispatch a job for an ineligible request', function () {
    Bus::fake();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $ineligible = DocumentRequest::factory()->for($user)->for($client)->create(['status' => 'archived', 'sent_at' => now()->subDays(3)]);
    DocumentRequestItem::factory()->for($ineligible)->create(['status' => 'pending']);

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertNotDispatched(SendDocumentRequestReminderJob::class);
});

it('never sends mail directly from the command', function () {
    // Bus::fake prevents the dispatched job from actually running (it would run
    // synchronously under the test's sync queue driver otherwise), isolating
    // whether the *command itself* ever touches the Mail facade.
    Bus::fake();
    Mail::fake();
    makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('never mixes requests across tenants when dispatching', function () {
    Bus::fake();
    $eligibleA = makeEligibleForCommand();
    $eligibleB = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligibleA->id);
    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligibleB->id);
});

it('running the command twice does not change reminder_count more than once per interval', function () {
    // Uses the real job (no Bus::fake) so the atomic claim actually runs.
    Mail::fake();
    $documentRequest = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();
    $this->artisan('reminders:send')->assertSuccessful();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
});
