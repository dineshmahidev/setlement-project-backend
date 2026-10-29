<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'cms_testimonials')) {
                $table->json('cms_testimonials')->nullable()->after('cms_services');
            }
            for ($i = 1; $i <= 4; $i++) {
                $column = 'gallery_image_' . $i;
                if (!Schema::hasColumn('companies', $column)) {
                    $table->string($column, 255)->nullable()->after('cms_testimonials');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $columns = ['cms_testimonials', 'gallery_image_1', 'gallery_image_2', 'gallery_image_3', 'gallery_image_4'];
            $existing = array_filter($columns, fn ($column) => Schema::hasColumn('companies', $column));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
