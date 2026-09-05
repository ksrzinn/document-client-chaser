<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreUploadedDocumentRequest;
use App\Models\DocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadedDocumentController extends Controller
{
    public function store(StoreUploadedDocumentRequest $request, string $token, string $itemId): RedirectResponse
    {
        $documentRequest = DocumentRequest::findPubliclyAccessible($token);

        abort_unless($documentRequest !== null, 404);

        $item = $documentRequest->items()->findOrFail($itemId);

        $file = $request->file('file');
        $directory = 'uploads/'.$documentRequest->id;
        $name = (string) Str::uuid();

        $storagePath = Storage::disk('local')->putFileAs($directory, $file, $name);

        abort_if($storagePath === false, 500, 'Unable to store the uploaded file.');

        try {
            DB::transaction(function () use ($documentRequest, $item, $file, $storagePath) {
                $documentRequest->uploadedDocuments()->create([
                    'user_id' => $documentRequest->user_id,
                    'client_id' => $documentRequest->client_id,
                    'document_request_item_id' => $item->id,
                    'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                    'storage_path' => $storagePath,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_at' => now(),
                ]);

                if ($item->status !== 'received') {
                    $item->status = 'received';
                    $item->save();
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storagePath);

            throw $e;
        }

        return back()->with('uploaded', true);
    }
}
