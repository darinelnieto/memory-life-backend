<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('avatar');
            $table->string('cover_photo')->nullable()->after('bio');
            $table->date('birth_date')->nullable()->after('cover_photo');
            $table->string('phone', 30)->nullable()->after('birth_date');
            $table->string('location', 120)->nullable()->after('phone');
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'cover_photo', 'birth_date', 'phone', 'location', 'gender']);
        });
    }
};
