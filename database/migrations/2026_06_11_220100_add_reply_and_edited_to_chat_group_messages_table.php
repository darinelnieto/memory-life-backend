<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_group_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')->nullable()->after('sender_id')->constrained('chat_group_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_group_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn('edited_at');
        });
    }
};
