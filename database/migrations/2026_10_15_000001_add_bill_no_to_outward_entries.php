<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outward_entries') && !Schema::hasColumn('outward_entries', 'bill_no')) {
            Schema::table('outward_entries', function (Blueprint $table) {
                $table->string('bill_no', 100)->nullable()->after('entry_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('outward_entries') && Schema::hasColumn('outward_entries', 'bill_no')) {
            Schema::table('outward_entries', function (Blueprint $table) {
                $table->dropColumn('bill_no');
            });
        }
    }
};
