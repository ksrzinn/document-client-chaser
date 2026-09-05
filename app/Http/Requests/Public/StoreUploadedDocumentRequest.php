<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreUploadedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is token/item-ownership based, resolved in the controller
        // (token -> DocumentRequest -> items()), not expressible as a simple policy here.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Content-sniffed MIME allowlist (the real security boundary) plus an
                // extension allowlist (consistency check only) — both must agree.
                // Legacy binary .doc/.xls are intentionally not supported (PRODUCT.md
                // §13): real .doc/.xls files are frequently sniffed by libmagic as the
                // generic OLE2 container type rather than a Word/Excel-specific MIME,
                // which would force accepting "any OLE2 compound file" to support them
                // at all. DOCX/XLSX are ZIP-based and detect precisely.
                File::types([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->extensions(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'])
                    ->max(config('uploads.max_size_kb').'kb'),
            ],
        ];
    }
}
