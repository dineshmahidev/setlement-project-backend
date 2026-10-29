<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'landing_slide_1',
            'landing_slide_2',
            'landing_slide_3',
            'landing_feature_image',
            'landing_service_image_1',
            'landing_service_image_2',
            'landing_service_image_3',
            'landing_contact_image_1',
            'landing_contact_image_2',
            'landing_form_image',
        ];

        Schema::table('companies', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn('companies', $column)) {
                    $table->string($column, 255)->nullable()->after('gallery_image_4');
                }
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'landing_slide_1',
            'landing_slide_2',
            'landing_slide_3',
            'landing_feature_image',
            'landing_service_image_1',
            'landing_service_image_2',
            'landing_service_image_3',
            'landing_contact_image_1',
            'landing_contact_image_2',
            'landing_form_image',
        ];

        Schema::table('companies', function (Blueprint $table) use ($columns) {
            $existing = array_filter($columns, fn ($column) => Schema::hasColumn('companies', $column));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
