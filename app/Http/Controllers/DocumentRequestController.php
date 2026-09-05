<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $documentRequests = $request->user()->documentRequests()
            ->with('client')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (DocumentRequest $documentRequest) => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'items_count']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'client' => $documentRequest->client->only(['id', 'name']),
            ]);

        return Inertia::render('DocumentRequests/Index', [
            'documentRequests' => $documentRequests,
        ]);
    }

    public function create(Request $request): Response
    {
        $clients = $request->user()->clients()
            ->orderBy('name')
            ->get()
            ->map->only(['id', 'name']);

        return Inertia::render('DocumentRequests/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $client = $request->user()->clients()->findOrFail($validated['client_id']);

        $documentRequest = DB::transaction(function () use ($request, $client, $validated) {
            $documentRequest = $request->user()->documentRequests()->create([
                'client_id' => $client->id,
                'message' => $validated['message'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            $documentRequest->items()->createMany(
                collect($validated['items'])->map(fn (array $item) => ['name' => $item['name']])->all()
            );

            return $documentRequest;
        });

        return redirect()->route('document-requests.show', $documentRequest);
    }

    public function show(Request $request, string $documentRequest): Response
    {
        $documentRequest = $request->user()->documentRequests()
            ->with(['client', 'items'])
            ->findOrFail($documentRequest);

        return Inertia::render('DocumentRequests/Show', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
                'items' => $documentRequest->items->map->only(['id', 'name', 'status']),
            ],
        ]);
    }

    public function edit(Request $request, string $documentRequest): Response
    {
        $documentRequest = $request->user()->documentRequests()
            ->with(['client', 'items'])
            ->findOrFail($documentRequest);

        $clients = $request->user()->clients()
            ->orderBy('name')
            ->get()
            ->map->only(['id', 'name']);

        return Inertia::render('DocumentRequests/Edit', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'client_id', 'message', 'due_at', 'expires_at']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'items' => $documentRequest->items->map->only(['id', 'name']),
            ],
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        $validated = $this->validated($request);

        $client = $request->user()->clients()->findOrFail($validated['client_id']);

        DB::transaction(function () use ($documentRequest, $client, $validated) {
            $documentRequest->update([
                'client_id' => $client->id,
                'message' => $validated['message'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            $existingIds = $documentRequest->items()->pluck('id');
            $submittedIds = collect($validated['items'])->pluck('id')->filter()->values();

            if ($submittedIds->diff($existingIds)->isNotEmpty()) {
                abort(404);
            }

            $documentRequest->items()->whereNotIn('id', $submittedIds)->delete();

            foreach ($validated['items'] as $item) {
                if (! empty($item['id'])) {
                    $documentRequest->items()->whereKey($item['id'])->update(['name' => $item['name']]);
                } else {
                    $documentRequest->items()->create(['name' => $item['name']]);
                }
            }
        });

        return redirect()->route('document-requests.show', $documentRequest);
    }

    public function archive(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        if ($documentRequest->status !== 'archived') {
            $documentRequest->status = 'archived';
            $documentRequest->save();
        }

        return redirect()->route('document-requests.show', $documentRequest);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where('user_id', $request->user()->id),
            ],
            'message' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:due_at'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['sometimes', 'nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
        ]);
    }
}
