<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('message');
            $table->string('media_type', 20)->nullable()->after('media_path');
            $table->boolean('is_temporary')->default(false)->after('media_type');
            $table->boolean('is_view_once')->default(false)->after('is_temporary');
            $table->timestamp('expires_at')->nullable()->after('is_view_once');
            $table->timestamp('viewed_at')->nullable()->after('read_at');
            $table->index(['is_temporary', 'expires_at']);
            $table->index(['is_view_once', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['is_temporary', 'expires_at']);
            $table->dropIndex(['is_view_once', 'viewed_at']);
            $table->dropColumn([
                'media_path',
                'media_type',
                'is_temporary',
                'is_view_once',
                'expires_at',
                'viewed_at',
            ]);
        });
    }
};
