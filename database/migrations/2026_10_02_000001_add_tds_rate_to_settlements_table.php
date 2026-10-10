<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('settlements', 'tds_rate')) {
            Schema::table('settlements', function (Blueprint $table) {
                $table->decimal('tds_rate', 8, 3)->default(0.000)->after('unloading_charges');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settlements', 'tds_rate')) {
            Schema::table('settlements', function (Blueprint $table) {
                $table->dropColumn('tds_rate');
            });
        }
    }
};
