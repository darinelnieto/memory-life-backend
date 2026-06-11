<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_group_message_user_hiddens')) {
            Schema::create('chat_group_message_user_hiddens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_group_message_id')->constrained('chat_group_messages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('hidden_at');
                $table->timestamps();

                $table->unique(['chat_group_message_id', 'user_id'], 'cgm_hidden_msg_user_unique');
                $table->index(['user_id', 'hidden_at'], 'cgm_hidden_user_hidden_idx');
            });

            return;
        }

        Schema::table('chat_group_message_user_hiddens', function (Blueprint $table) {
            $table->unique(['chat_group_message_id', 'user_id'], 'cgm_hidden_msg_user_unique');
            $table->index(['user_id', 'hidden_at'], 'cgm_hidden_user_hidden_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_message_user_hiddens');
    }
};
