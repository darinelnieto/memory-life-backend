<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['text', 'photo', 'video', 'audio']);
            $table->text('content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_items');
    }
};
