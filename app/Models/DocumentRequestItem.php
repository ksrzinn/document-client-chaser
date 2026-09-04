<?php

namespace App\Models;

use Database\Factories\DocumentRequestItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['document_request_id', 'name', 'status'])]
class DocumentRequestItem extends Model
{
    /** @use HasFactory<DocumentRequestItemFactory> */
    use HasFactory;

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }
}
