<?php

namespace Database\Factories;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequestItem>
 */
class DocumentRequestItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_request_id' => DocumentRequest::factory(),
            'name' => fake()->randomElement(['Bank statement', 'Invoice', 'Identification', 'Contract']),
            'status' => 'requested',
        ];
    }
}
