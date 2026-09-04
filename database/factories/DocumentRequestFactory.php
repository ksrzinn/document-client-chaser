<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'status' => 'draft',
            'message' => null,
            'due_at' => null,
            'expires_at' => null,
            'sent_at' => null,
            'completed_at' => null,
        ];
    }
}
