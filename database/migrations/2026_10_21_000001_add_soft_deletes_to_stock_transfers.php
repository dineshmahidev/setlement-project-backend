<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_transfers') && !Schema::hasColumn('stock_transfers', 'deleted_at')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'deleted_at')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
