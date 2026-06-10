<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journey_items', function (Blueprint $table) {
            $table->unsignedBigInteger('source_post_id')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('journey_items', function (Blueprint $table) {
            $table->dropColumn('source_post_id');
        });
    }
};
