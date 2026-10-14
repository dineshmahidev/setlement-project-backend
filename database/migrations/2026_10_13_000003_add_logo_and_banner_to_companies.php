<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'logo_transparent_path')) {
                $table->string('logo_transparent_path', 255)->nullable()->after('logo_path');
            }
            if (!Schema::hasColumn('companies', 'banner_path')) {
                $table->string('banner_path', 255)->nullable()->after('logo_transparent_path');
            }
        });

        // Seed initial default logo and banner paths
        DB::table('companies')->where('code', 'laxmi')->update([
            'logo_path' => '/logos/laxmi_traders.png',
            'logo_transparent_path' => '/logos/laxmi_traders_transparent.png',
            'banner_path' => '/banners/laxmi_traders_banner.png',
        ]);

        DB::table('companies')->where('code', 'sm')->update([
            'logo_path' => '/logos/sm_traders.png',
            'logo_transparent_path' => '/logos/sm_traders_transparent.png',
            'banner_path' => '/banners/sm_traders_banner.png',
        ]);

        DB::table('companies')->where('code', 'shriee')->update([
            'logo_path' => '/logos/shree_feeds.png',
            'logo_transparent_path' => '/logos/shree_feeds_transparent.png',
            'banner_path' => '/banners/shree_feeds_banner.png',
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'logo_transparent_path')) {
                $table->dropColumn('logo_transparent_path');
            }
            if (Schema::hasColumn('companies', 'banner_path')) {
                $table->dropColumn('banner_path');
            }
        });
    }
};
