<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vendors', 'products', 'items', 'vehicles'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                    $t->index('company_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['vendors', 'products', 'items', 'vehicles'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('company_id');
                });
            }
        }
    }
};
