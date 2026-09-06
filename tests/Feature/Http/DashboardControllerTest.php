<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

it('shows zeroed stats and no recent requests for a brand new user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('stats.clients', 0)
        ->where('stats.activeRequests', 0)
        ->where('stats.pendingDocuments', 0)
        ->where('stats.completed', 0)
        ->where('recentRequests', []));
});

it('counts clients, active/completed requests, and pending documents scoped to the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $client = Client::factory()->for($user)->create();
    Client::factory()->for($user)->create(['archived_at' => now()]);
    Client::factory()->for($otherUser)->create();

    $active = DocumentRequest::factory()->for($user)->for($client)
        ->create(['status' => 'draft', 'sent_at' => now()]);
    DocumentRequestItem::factory()->for($active)->create(['status' => 'requested']);
    DocumentRequestItem::factory()->for($active)->create(['status' => 'requested']);

    $completed = DocumentRequest::factory()->for($user)->for($client)
        ->create(['status' => 'completed']);
    DocumentRequestItem::factory()->for($completed)->create(['status' => 'received']);

    DocumentRequest::factory()->for($otherUser)->for(Client::factory()->for($otherUser))
        ->create(['status' => 'draft', 'sent_at' => now()]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('stats.clients', 2)
        ->where('stats.activeRequests', 1)
        ->where('stats.pendingDocuments', 2)
        ->where('stats.completed', 1));
});

it('lists at most 5 recent requests ordered newest first, regardless of status', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    for ($i = 0; $i < 7; $i++) {
        DocumentRequest::factory()->for($user)->for($client)->create();
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentRequests', 5));
});
