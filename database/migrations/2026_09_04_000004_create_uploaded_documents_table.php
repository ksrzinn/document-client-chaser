<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('disk')->default('local');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('client_id');
            $table->index('document_request_id');
            $table->index('document_request_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_documents');
    }
};
