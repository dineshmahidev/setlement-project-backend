<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add deleted_at to tables for soft deletes
        $tables = [
            'inward_entries',
            'outward_entries',
            'production_batches',
            'settlements',
            'debit_notes',
            'invoices',
            'vendors',
            'items',
            'branches',
            'companies',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        // 2. Add CMS fields and 2nd phone to companies table
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'phone_2')) {
                $table->string('phone_2', 30)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('companies', 'map_url')) {
                $table->text('map_url')->nullable()->after('website');
            }
            if (!Schema::hasColumn('companies', 'about_us')) {
                $table->text('about_us')->nullable()->after('sub_tagline');
            }
            if (!Schema::hasColumn('companies', 'cms_hero_title')) {
                $table->string('cms_hero_title')->nullable()->after('about_us');
            }
            if (!Schema::hasColumn('companies', 'cms_hero_subtitle')) {
                $table->string('cms_hero_subtitle')->nullable()->after('cms_hero_title');
            }
            if (!Schema::hasColumn('companies', 'cms_services')) {
                $table->text('cms_services')->nullable()->after('cms_hero_subtitle');
            }
        });
    }

    public function down(): void
    {
        $tables = [
            'inward_entries',
            'outward_entries',
            'production_batches',
            'settlements',
            'debit_notes',
            'invoices',
            'vendors',
            'items',
            'branches',
            'companies',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'phone_2',
                'map_url',
                'about_us',
                'cms_hero_title',
                'cms_hero_subtitle',
                'cms_services',
            ]);
        });
    }
};
