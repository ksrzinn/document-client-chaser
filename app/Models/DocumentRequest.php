<?php

namespace App\Models;

use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'client_id', 'status', 'message', 'due_at', 'expires_at', 'sent_at', 'completed_at'])]
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
}
