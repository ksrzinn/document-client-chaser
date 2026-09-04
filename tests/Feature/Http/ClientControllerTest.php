<?php

use App\Models\Client;
use App\Models\User;

// --- Normal CRUD ---

it('lets an authenticated user create a client', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'user_id' => $user->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);
});

it('lets an authenticated user view their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('clients.show', $client));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Show')
        ->where('client.id', $client->id)
        ->where('client.name', $client->name)
    );
});

it('lets an authenticated user edit their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->put(route('clients.update', $client), [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

it('lets an authenticated user archive their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->post(route('clients.archive', $client));

    $response->assertRedirect();
    expect($client->fresh()->archived_at)->not->toBeNull();
});

it('lets an authenticated user list their clients', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('clients.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Index')
        ->has('clients.data', 1)
        ->where('clients.data.0.id', $client->id)
    );
});

// --- Ownership on create (mass-assignment / spoofing) ---

it('ignores a spoofed user_id and assigns the client to the authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->post(route('clients.store'), [
        'name' => 'Malicious Client',
        'email' => 'client@example.com',
        'user_id' => $userB->id,
    ]);

    $this->assertDatabaseHas('clients', [
        'name' => 'Malicious Client',
        'user_id' => $userA->id,
    ]);
    $this->assertDatabaseMissing('clients', [
        'name' => 'Malicious Client',
        'user_id' => $userB->id,
    ]);
});

// --- Tenant isolation ---

it('lists only the authenticated users clients, never another tenants', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $clientB = Client::factory()->for($userB)->create();

    $response = $this->actingAs($userA)->get(route('clients.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Index')
        ->has('clients.data', 1)
        ->where('clients.data.0.id', $clientA->id)
    );
});

it('returns 404 when a user tries to view another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->get(route('clients.show', $clientB))
        ->assertNotFound();
});

it('returns 404 when a user tries to edit another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->get(route('clients.edit', $clientB))
        ->assertNotFound();
});

it('returns 404 when a user tries to update another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->put(route('clients.update', $clientB), [
        'name' => 'Hacked',
        'email' => 'hacked@example.com',
    ])->assertNotFound();

    expect($clientB->fresh()->name)->not->toBe('Hacked');
});

it('returns 404 when a user tries to archive another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->post(route('clients.archive', $clientB))
        ->assertNotFound();

    expect($clientB->fresh()->archived_at)->toBeNull();
});

it('redirects unauthenticated users away from every client route', function () {
    $client = Client::factory()->create();

    $this->get(route('clients.index'))->assertRedirect(route('login'));
    $this->get(route('clients.create'))->assertRedirect(route('login'));
    $this->post(route('clients.store'), [])->assertRedirect(route('login'));
    $this->get(route('clients.show', $client))->assertRedirect(route('login'));
    $this->get(route('clients.edit', $client))->assertRedirect(route('login'));
    $this->put(route('clients.update', $client), [])->assertRedirect(route('login'));
    $this->post(route('clients.archive', $client))->assertRedirect(route('login'));
});

// --- Validation ---

it('rejects client creation with a missing name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'email' => 'jane@example.com',
    ])->assertInvalid(['name']);
});

it('rejects client creation with a missing email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
    ])->assertInvalid(['email']);
});

it('rejects client creation with an invalid email format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => 'not-an-email',
    ])->assertInvalid(['email']);
});

it('rejects client creation with a name over the max length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => str_repeat('a', 256),
        'email' => 'jane@example.com',
    ])->assertInvalid(['name']);
});

it('rejects client creation with an email over the max length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => str_repeat('a', 250).'@example.com',
    ])->assertInvalid(['email']);
});

it('allows two different tenants to have clients with the same email', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    Client::factory()->for($userA)->create(['email' => 'shared@example.com']);

    $this->actingAs($userB)->post(route('clients.store'), [
        'name' => 'Shared Email Client',
        'email' => 'shared@example.com',
    ])->assertRedirect();

    $this->assertDatabaseHas('clients', [
        'user_id' => $userB->id,
        'email' => 'shared@example.com',
    ]);
});

// --- Archive idempotency ---

it('does not error or change the timestamp when archiving an already-archived client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('clients.archive', $client));
    $firstArchivedAt = $client->fresh()->archived_at;

    $this->actingAs($user)->post(route('clients.archive', $client))
        ->assertRedirect();
    $secondArchivedAt = $client->fresh()->archived_at;

    expect($secondArchivedAt->equalTo($firstArchivedAt))->toBeTrue();
});

it('keeps an archived client in the database, not deleted', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('clients.archive', $client));

    $this->assertDatabaseHas('clients', ['id' => $client->id]);
});
