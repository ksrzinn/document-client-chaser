<?php

namespace App\Models;

use Database\Factories\UploadedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'client_id',
    'document_request_id',
    'document_request_item_id',
    'original_filename',
    'storage_path',
    'disk',
    'mime_type',
    'size',
    'uploaded_at',
])]
class UploadedDocument extends Model
{
    /** @use HasFactory<UploadedDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'size' => 'integer',
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

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function documentRequestItem(): BelongsTo
    {
        return $this->belongsTo(DocumentRequestItem::class);
    }
}
