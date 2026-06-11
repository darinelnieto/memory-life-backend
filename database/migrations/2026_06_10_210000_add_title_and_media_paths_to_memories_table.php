<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            if (!Schema::hasColumn('memories', 'title')) {
                $table->string('title')->nullable()->after('type');
            }

            if (!Schema::hasColumn('memories', 'media_paths')) {
                $table->json('media_paths')->nullable()->after('file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            if (Schema::hasColumn('memories', 'media_paths')) {
                $table->dropColumn('media_paths');
            }

            if (Schema::hasColumn('memories', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
