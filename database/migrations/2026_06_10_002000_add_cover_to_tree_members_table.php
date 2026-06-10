<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->string('cover')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->dropColumn('cover');
        });
    }
};
