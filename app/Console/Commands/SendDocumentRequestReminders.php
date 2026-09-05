<?php

namespace App\Console\Commands;

use App\Jobs\SendDocumentRequestReminderJob;
use App\Models\DocumentRequest;
use Illuminate\Console\Command;

class SendDocumentRequestReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Dispatch reminder jobs for document requests with missing documents';

    public function handle(): int
    {
        $dispatched = 0;

        DocumentRequest::eligibleForReminder()
            ->select('id')
            ->chunkById(200, function ($documentRequests) use (&$dispatched) {
                foreach ($documentRequests as $documentRequest) {
                    SendDocumentRequestReminderJob::dispatch($documentRequest->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} reminder job(s).");

        return self::SUCCESS;
    }
}
