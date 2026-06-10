<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journeys', function (Blueprint $table) {
            $table->foreignId('tree_member_id')
                ->nullable()
                ->after('user_id')
                ->constrained('tree_members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journeys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tree_member_id');
        });
    }
};
