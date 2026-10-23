<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'show_logo_website')) {
                $table->boolean('show_logo_website')->default(true)->after('logo_transparent_path');
            }
            if (!Schema::hasColumn('companies', 'show_logo_pdf')) {
                $table->boolean('show_logo_pdf')->default(true)->after('show_logo_website');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'show_logo_website')) {
                $table->dropColumn('show_logo_website');
            }
            if (Schema::hasColumn('companies', 'show_logo_pdf')) {
                $table->dropColumn('show_logo_pdf');
            }
        });
    }
};
