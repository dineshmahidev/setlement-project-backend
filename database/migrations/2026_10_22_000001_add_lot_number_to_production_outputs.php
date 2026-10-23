<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_outputs') && !Schema::hasColumn('production_outputs', 'lot_number')) {
            Schema::table('production_outputs', function (Blueprint $table) {
                $table->string('lot_number', 100)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('production_outputs') && Schema::hasColumn('production_outputs', 'lot_number')) {
            Schema::table('production_outputs', function (Blueprint $table) {
                $table->dropColumn('lot_number');
            });
        }
    }
};
