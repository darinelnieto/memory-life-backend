<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->json('media_photos')->nullable()->after('avatar');
            $table->string('media_video')->nullable()->after('media_photos');
            $table->string('app_user_email')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->dropColumn(['media_photos', 'media_video', 'app_user_email']);
        });
    }
};
