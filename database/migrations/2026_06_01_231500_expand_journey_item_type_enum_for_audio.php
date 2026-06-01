<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE journey_items MODIFY COLUMN type ENUM('text','photo','video','voice','audio') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE journey_items SET type = 'voice' WHERE type = 'audio'");
        DB::statement("ALTER TABLE journey_items MODIFY COLUMN type ENUM('text','photo','video','voice') NOT NULL");
    }
};
