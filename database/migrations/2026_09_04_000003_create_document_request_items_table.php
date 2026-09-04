<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('requested');
            $table->timestamps();

            $table->index('document_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_items');
    }
};
