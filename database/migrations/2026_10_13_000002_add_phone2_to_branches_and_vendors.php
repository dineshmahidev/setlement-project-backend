<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'phone_2')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('phone_2', 30)->nullable()->after('phone');
            });
        }

        if (Schema::hasTable('vendors') && !Schema::hasColumn('vendors', 'phone_2')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('phone_2', 30)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'phone_2')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('phone_2');
            });
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'phone_2')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('phone_2');
            });
        }
    }
};
