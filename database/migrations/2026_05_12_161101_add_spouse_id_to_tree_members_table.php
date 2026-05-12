<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->foreignId('spouse_id')
                  ->nullable()
                  ->after('parent_id')
                  ->constrained('tree_members')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->dropForeign(['spouse_id']);
            $table->dropColumn('spouse_id');
        });
    }
};
