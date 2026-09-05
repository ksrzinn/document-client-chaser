<?php

namespace App\Jobs;

use App\Mail\DocumentRequestReminder;
use App\Models\ActivityLog;
use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendDocumentRequestReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $documentRequestId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->documentRequestId;
    }

    public function handle(): void
    {
        $documentRequest = DocumentRequest::with('client', 'items')->find($this->documentRequestId);

        if ($documentRequest === null || ! $documentRequest->isEligibleForReminder()) {
            return;
        }

        $threshold = now()->subDays((int) config('reminders.interval_days'));
        $maxCount = (int) config('reminders.max_count');
        $now = now();

        $claimed = DB::table('document_requests')
            ->where('id', $documentRequest->id)
            ->where('reminder_count', '<', $maxCount)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
            })
            ->update([
                'reminder_count' => DB::raw('reminder_count + 1'),
                'last_reminder_sent_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $token = $documentRequest->regenerateAccessToken();

        $missingItemNames = $documentRequest->items
            ->where('status', '!=', 'received')
            ->pluck('name')
            ->values()
            ->all();

        $email = $documentRequest->client->email;

        Mail::to($email)->queue(new DocumentRequestReminder(
            businessName: $documentRequest->user->name,
            clientName: $documentRequest->client->name,
            requestMessage: $documentRequest->message,
            dueAt: $documentRequest->due_at?->toDateString(),
            link: route('public.document-request.show', $token),
            missingItemNames: $missingItemNames,
        ));

        ActivityLog::create([
            'user_id' => $documentRequest->user_id,
            'client_id' => $documentRequest->client_id,
            'document_request_id' => $documentRequest->id,
            'event' => 'reminder_sent',
            'metadata' => ['client_email' => $email],
        ]);
    }
}
