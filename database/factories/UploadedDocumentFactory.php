<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UploadedDocument>
 */
class UploadedDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'document_request_id' => DocumentRequest::factory(),
            'document_request_item_id' => null,
            'original_filename' => fake()->word().'.pdf',
            'storage_path' => Str::uuid()->toString().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 1000000),
            'uploaded_at' => now(),
        ];
    }
}
