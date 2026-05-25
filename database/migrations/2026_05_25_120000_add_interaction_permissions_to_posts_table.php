<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('allow_comments')->default(true)->after('media_path');
            $table->boolean('allow_likes')->default(true)->after('allow_comments');
            $table->boolean('allow_reposts')->default(true)->after('allow_likes');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['allow_comments', 'allow_likes', 'allow_reposts']);
        });
    }
};
