<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

function makeReminderCandidate(array $requestAttrs = [], array $clientAttrs = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(array_merge(['email' => 'client@example.com'], $clientAttrs));
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ], $requestAttrs));

    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'pending']);

    return $documentRequest;
}

it('is eligible when sent, missing items, past interval, no prior reminder', function () {
    $documentRequest = makeReminderCandidate();

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
    expect(DocumentRequest::eligibleForReminder()->pluck('id'))->toContain($documentRequest->id);
});

it('is not eligible when never sent', function () {
    $documentRequest = makeReminderCandidate(['sent_at' => null]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
    expect(DocumentRequest::eligibleForReminder()->pluck('id'))->not->toContain($documentRequest->id);
});

it('is not eligible when archived', function () {
    $documentRequest = makeReminderCandidate(['status' => 'archived']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when completed', function () {
    $documentRequest = makeReminderCandidate(['status' => 'completed']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when expired', function () {
    $documentRequest = makeReminderCandidate(['expires_at' => now()->subDay()]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is eligible when expires_at is in the future', function () {
    $documentRequest = makeReminderCandidate(['expires_at' => now()->addDay()]);

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
});

it('is not eligible when the client has no email', function () {
    $documentRequest = makeReminderCandidate([], ['email' => '']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when the client email is invalid', function () {
    $documentRequest = makeReminderCandidate([], ['email' => 'not-an-email']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when all items are received', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ]);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible before the interval has elapsed since sending', function () {
    $documentRequest = makeReminderCandidate(['sent_at' => now()->subHours(2)]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible before the interval has elapsed since the last reminder', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subHours(2),
        'reminder_count' => 1,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is eligible once the interval has elapsed since the last reminder', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subDays(3),
        'reminder_count' => 1,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
});

it('is not eligible once max_count reminders have been sent', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subDays(3),
        'reminder_count' => 3,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('respects a configured interval and max count', function () {
    config(['reminders.interval_days' => 1, 'reminders.max_count' => 1]);

    $eligible = makeReminderCandidate(['sent_at' => now()->subDays(2)]);
    $tooSoon = makeReminderCandidate(['sent_at' => now()->subHours(5)]);
    $overMax = makeReminderCandidate(['last_reminder_sent_at' => now()->subDays(2), 'reminder_count' => 1]);

    expect($eligible->isEligibleForReminder())->toBeTrue();
    expect($tooSoon->isEligibleForReminder())->toBeFalse();
    expect($overMax->isEligibleForReminder())->toBeFalse();
});
