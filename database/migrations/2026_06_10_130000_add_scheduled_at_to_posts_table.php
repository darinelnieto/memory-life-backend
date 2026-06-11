<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('posts', 'scheduled_at')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('show_on_profile')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('posts', 'scheduled_at')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
