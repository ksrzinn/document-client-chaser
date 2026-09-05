<?php

namespace App\Http\Requests\Public;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreUploadedDocumentRequest extends FormRequest
{
    private ?DocumentRequest $documentRequest = null;

    private ?DocumentRequestItem $item = null;

    public function authorize(): bool
    {
        // Authorization is token/item-ownership based (token -> DocumentRequest -> item),
        // resolved here so it runs BEFORE the validation rules below — in particular
        // before the content-sniffing File::types() rule, which reads the whole uploaded
        // file. Resolving here also prevents an invalid token from paying that cost.
        $documentRequest = DocumentRequest::findPubliclyAccessible((string) $this->route('token'));

        if ($documentRequest === null) {
            return false;
        }

        $item = $documentRequest->items()->find($this->route('item'));

        if ($item === null) {
            return false;
        }

        $this->documentRequest = $documentRequest;
        $this->item = $item;

        return true;
    }

    protected function failedAuthorization()
    {
        abort(404);
    }

    public function documentRequest(): DocumentRequest
    {
        return $this->documentRequest;
    }

    public function item(): DocumentRequestItem
    {
        return $this->item;
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
