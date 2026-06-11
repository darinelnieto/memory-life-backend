<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_leaf_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_leaf_id')->constrained('memory_leaves')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('copied_memory_leaf_id')->nullable()->constrained('memory_leaves')->nullOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->unique(['memory_leaf_id', 'recipient_id', 'status'], 'memory_leaf_shares_unique_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_leaf_shares');
    }
};
