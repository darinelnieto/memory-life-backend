<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->boolean('is_pet')->default(false)->after('user_id');
            $table->foreignId('owner_tree_member_id')
                ->nullable()
                ->after('is_pet')
                ->constrained('tree_members')
                ->nullOnDelete();

            $table->index(['family_id', 'is_pet']);
            $table->index(['owner_tree_member_id', 'is_pet']);
        });
    }

    public function down(): void
    {
        Schema::table('tree_members', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'is_pet']);
            $table->dropIndex(['owner_tree_member_id', 'is_pet']);
            $table->dropConstrainedForeignId('owner_tree_member_id');
            $table->dropColumn('is_pet');
        });
    }
};
