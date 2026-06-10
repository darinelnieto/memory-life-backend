<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->string('invite_status', 20)->default('none')->after('app_user_email');
        });
    }

    public function down(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->dropColumn('invite_status');
        });
    }
};
