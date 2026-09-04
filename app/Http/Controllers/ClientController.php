<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $clients = $request->user()->clients()
            ->orderBy('name')
            ->paginate(15)
            ->through(fn ($client) => $client->only(['id', 'name', 'email', 'archived_at']));

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $client = $request->user()->clients()->create($validated);

        return redirect()->route('clients.show', $client);
    }

    public function show(Request $request, string $client): Response
    {
        $client = $request->user()->clients()->findOrFail($client);

        return Inertia::render('Clients/Show', [
            'client' => $client->only(['id', 'name', 'email', 'archived_at']),
        ]);
    }

    public function edit(Request $request, string $client): Response
    {
        $client = $request->user()->clients()->findOrFail($client);

        return Inertia::render('Clients/Edit', [
            'client' => $client->only(['id', 'name', 'email', 'archived_at']),
        ]);
    }

    public function update(Request $request, string $client): RedirectResponse
    {
        $client = $request->user()->clients()->findOrFail($client);

        $validated = $this->validated($request);

        $client->update($validated);

        return redirect()->route('clients.show', $client);
    }

    public function archive(Request $request, string $client): RedirectResponse
    {
        $client = $request->user()->clients()->findOrFail($client);

        if ($client->archived_at === null) {
            $client->archived_at = now();
            $client->save();
        }

        return redirect()->route('clients.show', $client);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
    }
}
