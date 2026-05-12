<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_leaf_id')->constrained('memory_leaves')->cascadeOnDelete();
            $table->foreignId('contributed_by')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['text', 'photo', 'video', 'voice'])->default('text');
            $table->text('content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
