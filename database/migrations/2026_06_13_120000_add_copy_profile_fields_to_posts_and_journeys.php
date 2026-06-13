<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (!Schema::hasColumn('posts', 'copied_from_post_id')) {
                $table->unsignedBigInteger('copied_from_post_id')->nullable()->index()->after('scheduled_at');
            }

            if (!Schema::hasColumn('posts', 'copied_at')) {
                $table->timestamp('copied_at')->nullable()->after('copied_from_post_id');
            }
        });

        Schema::table('journeys', function (Blueprint $table) {
            if (!Schema::hasColumn('journeys', 'copied_from_journey_id')) {
                $table->unsignedBigInteger('copied_from_journey_id')->nullable()->index()->after('published_at');
            }

            if (!Schema::hasColumn('journeys', 'copied_at')) {
                $table->timestamp('copied_at')->nullable()->after('copied_from_journey_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'copied_at')) {
                $table->dropColumn('copied_at');
            }

            if (Schema::hasColumn('posts', 'copied_from_post_id')) {
                $table->dropColumn('copied_from_post_id');
            }
        });

        Schema::table('journeys', function (Blueprint $table) {
            if (Schema::hasColumn('journeys', 'copied_at')) {
                $table->dropColumn('copied_at');
            }

            if (Schema::hasColumn('journeys', 'copied_from_journey_id')) {
                $table->dropColumn('copied_from_journey_id');
            }
        });
    }
};
