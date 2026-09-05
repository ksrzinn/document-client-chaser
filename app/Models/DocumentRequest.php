<?php

namespace App\Models;

use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        $this->save();

        return $token;
    }

    public function regenerateAccessToken(): string
    {
        $token = Str::random(40);

        $this->access_token_hash = hash('sha256', $token);
        $this->save();

        return $token;
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
}
