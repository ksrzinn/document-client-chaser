<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $activeRequests = $user->documentRequests()
            ->whereNotIn('status', ['archived', 'completed']);

        $pendingDocuments = (clone $activeRequests)
            ->withCount(['items' => fn ($query) => $query->where('status', '!=', 'received')])
            ->get()
            ->sum('items_count');

        $recentRequests = $user->documentRequests()
            ->with('client:id,name')
            ->withCount('items')
            ->withCount(['items as received_items_count' => fn ($query) => $query->where('status', 'received')])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (DocumentRequest $documentRequest) => [
                'id' => $documentRequest->id,
                'client_name' => $documentRequest->client->name,
                'display_status' => $documentRequest->display_status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'items_received' => $documentRequest->received_items_count,
                'items_total' => $documentRequest->items_count,
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'clients' => $user->clients()->count(),
                'activeRequests' => (clone $activeRequests)->count(),
                'pendingDocuments' => $pendingDocuments,
                'completed' => $user->documentRequests()->where('status', 'completed')->count(),
            ],
            'recentRequests' => $recentRequests,
        ]);
    }
}
