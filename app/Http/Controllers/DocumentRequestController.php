<?php

namespace App\Http\Controllers;

use App\Mail\DocumentRequestSent;
use App\Models\ActivityLog;
use App\Models\DocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            ->with(['client', 'items.uploadedDocuments' => fn ($query) => $query->orderByDesc('uploaded_at')])
            ->findOrFail($documentRequest);

        return Inertia::render('DocumentRequests/Show', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'sent_at' => $documentRequest->sent_at?->toIso8601String(),
                'completed_at' => $documentRequest->completed_at?->toIso8601String(),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
                'items' => $documentRequest->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                    'documents' => $item->uploadedDocuments->map(fn ($document) => [
                        'id' => $document->id,
                        'original_filename' => $document->original_filename,
                        'mime_type' => $document->mime_type,
                        'size' => $document->size,
                        'uploaded_at' => $document->uploaded_at->toIso8601String(),
                    ])->values(),
                ]),
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

            $documentRequest->items()->whereNotIn('id', $submittedIds)->whereDoesntHave('uploadedDocuments')->delete();

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

    public function accessLink(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        $token = $documentRequest->access_token_hash === null
            ? $documentRequest->generateAccessToken()
            : null;

        if ($token === null) {
            return back()->with('accessLinkExists', true);
        }

        return back()->with('accessLink', route('public.document-request.show', $token));
    }

    public function send(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()
            ->with('client')
            ->findOrFail($documentRequest);

        if ($documentRequest->status === 'archived') {
            return back()->with('error', 'Archived requests cannot be sent.');
        }

        $email = $documentRequest->client->email;
        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'The client does not have a valid email address.');
        }

        $token = $documentRequest->regenerateAccessToken();

        if ($documentRequest->sent_at === null) {
            $documentRequest->sent_at = now();
            $documentRequest->save();
        }

        Mail::to($email)->queue(new DocumentRequestSent(
            businessName: $request->user()->name,
            clientName: $documentRequest->client->name,
            requestMessage: $documentRequest->message,
            dueAt: $documentRequest->due_at?->toDateString(),
            link: route('public.document-request.show', $token),
        ));

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'client_id' => $documentRequest->client_id,
            'document_request_id' => $documentRequest->id,
            'event' => 'request_sent',
            'metadata' => ['client_email' => $email],
        ]);

        return back()->with('success', 'Request sent to the client.');
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
