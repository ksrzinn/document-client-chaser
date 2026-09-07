<?php

namespace App\Models;

use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['client_id', 'message', 'due_at', 'expires_at', 'sent_at', 'completed_at', 'last_reminder_sent_at', 'reminder_count'])]
class DocumentRequest extends Model
{
    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentRequestItem::class);
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function generateAccessToken(): string
    {
        if ($this->access_token_hash !== null) {
            throw new \RuntimeException('Access token already generated for this document request.');
        }

        $token = Str::random(40);

        $this->access_token_hash = hash('sha256', $token);
        $this->access_token_encrypted = Crypt::encryptString($token);
        $this->save();

        return $token;
    }

    public function regenerateAccessToken(): string
    {
        $token = Str::random(40);

        $this->access_token_hash = hash('sha256', $token);
        $this->access_token_encrypted = Crypt::encryptString($token);
        $this->save();

        return $token;
    }

    /**
     * The secure client-upload URL for the currently persisted token, or null
     * if no token has been generated yet or the request is no longer publicly
     * accessible (archived/expired). Purely derived from stored state — never
     * generates or rotates a token.
     */
    public function currentAccessLink(): ?string
    {
        if ($this->access_token_encrypted === null || ! $this->isPubliclyAccessible()) {
            return null;
        }

        $token = Crypt::decryptString($this->access_token_encrypted);

        return route('public.document-request.show', $token);
    }

    public function isPubliclyAccessible(): bool
    {
        if ($this->sent_at === null || $this->status === 'archived') {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public static function findPubliclyAccessible(string $token): ?self
    {
        $documentRequest = static::where('access_token_hash', hash('sha256', $token))->first();

        if ($documentRequest === null || ! $documentRequest->isPubliclyAccessible()) {
            return null;
        }

        return $documentRequest;
    }

    public function scopeEligibleForReminder(Builder $query): Builder
    {
        $threshold = now()->subDays((int) config('reminders.interval_days'));
        $maxCount = (int) config('reminders.max_count');

        return $query
            ->whereNotNull('sent_at')
            ->whereNotIn('status', ['archived', 'completed'])
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('reminder_count', '<', $maxCount)
            ->where(function (Builder $q) use ($threshold) {
                $q->whereNull('last_reminder_sent_at')->where('sent_at', '<=', $threshold)
                    ->orWhere('last_reminder_sent_at', '<=', $threshold);
            })
            ->whereHas('client', function (Builder $q) {
                $q->whereNotNull('email')->where('email', '!=', '');
            })
            ->whereHas('items', function (Builder $q) {
                $q->where('status', '!=', 'received');
            });
    }

    public function isEligibleForReminder(): bool
    {
        if ($this->sent_at === null) {
            return false;
        }

        if (in_array($this->status, ['archived', 'completed'], true)) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        $email = $this->client?->email;
        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (! $this->items()->where('status', '!=', 'received')->exists()) {
            return false;
        }

        if ($this->reminder_count >= (int) config('reminders.max_count')) {
            return false;
        }

        $threshold = now()->subDays((int) config('reminders.interval_days'));
        $lastActivity = $this->last_reminder_sent_at ?? $this->sent_at;

        return $lastActivity->lte($threshold);
    }

    public function isComplete(): bool
    {
        return $this->items()->exists()
            && ! $this->items()->where('status', '!=', 'received')->exists();
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === 'archived') {
            return 'archived';
        }

        if ($this->status === 'completed') {
            return 'completed';
        }

        if ($this->sent_at === null) {
            return 'draft';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'awaiting_client';
    }

    /**
     * Transition this request to `completed` if every item is `received`.
     *
     * Self-contained and safe to call standalone or nested inside an existing
     * transaction (e.g. the public upload transaction): it locks its own row
     * with SELECT ... FOR UPDATE before re-checking state, which is what makes
     * the transition race-free across two concurrent uploads to different
     * items of the same request. Two invariants this depends on:
     *
     *  - The item's own status write must already be part of the same
     *    transaction that calls this method, and must happen *before* this
     *    method is called, so the lock's post-acquisition read observes it.
     *  - Postgres's default READ COMMITTED isolation gives each statement a
     *    fresh snapshot once the row lock is acquired by the previous holder's
     *    commit. Under REPEATABLE READ this method would instead raise a
     *    serialization failure on the lock (a loud error, not a silent miss) —
     *    do not raise the isolation level without revisiting this.
     */
    public function markCompletedIfComplete(): bool
    {
        return DB::transaction(function () {
            $locked = self::whereKey($this->id)->lockForUpdate()->first();

            if ($locked === null || in_array($locked->status, ['completed', 'archived'], true)) {
                return false;
            }

            if (! $locked->isComplete()) {
                return false;
            }

            $now = now();
            $locked->status = 'completed';
            $locked->completed_at = $now;
            $locked->save();

            ActivityLog::create([
                'user_id' => $locked->user_id,
                'client_id' => $locked->client_id,
                'document_request_id' => $locked->id,
                'event' => 'request_completed',
                'metadata' => [],
            ]);

            $this->status = $locked->status;
            $this->completed_at = $locked->completed_at;
            $this->syncOriginalAttribute('status');
            $this->syncOriginalAttribute('completed_at');

            return true;
        });
    }
}
