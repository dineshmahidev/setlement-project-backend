<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_batches') && !Schema::hasColumn('production_batches', 'party_id')) {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->unsignedBigInteger('party_id')->nullable()->after('company_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('production_batches') && Schema::hasColumn('production_batches', 'party_id')) {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->dropColumn('party_id');
            });
        }
    }
};
