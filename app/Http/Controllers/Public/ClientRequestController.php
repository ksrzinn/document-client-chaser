<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientRequestController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $documentRequest = DocumentRequest::with(['client:id,name', 'items:id,document_request_id,name'])
            ->where('access_token_hash', hash('sha256', $token))
            ->first();

        abort_unless($documentRequest !== null && $documentRequest->isPubliclyAccessible(), 404);

        return Inertia::render('Public/DocumentRequest', [
            'documentRequest' => [
                'client_name' => $documentRequest->client->name,
                'message' => $documentRequest->message,
                'status' => $documentRequest->status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'items' => $documentRequest->items->map(fn ($item) => ['name' => $item->name])->values(),
            ],
        ]);
    }
}
