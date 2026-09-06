<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;

it('is draft when never sent', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => null, 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('draft');
});

it('is awaiting_client when sent with no expiry', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now(), 'expires_at' => null, 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('awaiting_client');
});

it('is expired when sent and expiry is in the past', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now()->subDays(10), 'expires_at' => now()->subDay(), 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('expired');
});

it('is awaiting_client when sent and expiry is in the future', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now(), 'expires_at' => now()->addDay(), 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('awaiting_client');
});

it('is completed regardless of expiry once status is completed', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now()->subDays(10), 'expires_at' => now()->subDay(), 'status' => 'completed']);

    expect($documentRequest->display_status)->toBe('completed');
});

it('is archived regardless of every other field once status is archived', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => null, 'status' => 'archived']);

    expect($documentRequest->display_status)->toBe('archived');
});
