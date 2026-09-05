<?php

namespace App\Models;

use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['client_id', 'message', 'due_at', 'expires_at', 'sent_at', 'completed_at'])]
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
}
