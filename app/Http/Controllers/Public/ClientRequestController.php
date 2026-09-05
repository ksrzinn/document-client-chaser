<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ClientRequestController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $documentRequest = DocumentRequest::findPubliclyAccessible($token);

        abort_unless($documentRequest !== null, 404);

        $documentRequest->loadMissing(['client:id,name', 'items:id,document_request_id,name,status']);

        return Inertia::render('Public/DocumentRequest', [
            'token' => $token,
            'documentRequest' => [
                'client_name' => $documentRequest->client->name,
                'message' => $documentRequest->message,
                'status' => $documentRequest->status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'items' => $documentRequest->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                ])->values(),
            ],
        ])->toResponse($request)->header('Referrer-Policy', 'no-referrer');
    }
}
