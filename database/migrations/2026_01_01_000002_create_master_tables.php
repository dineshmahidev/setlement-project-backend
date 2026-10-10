<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_code', 50)->unique();
            $table->string('vendor_name');
            $table->string('business_name')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('state', 100)->nullable()->default('Tamil Nadu');
            $table->string('pincode', 10)->nullable();
            $table->string('gstin', 20)->nullable()->index();
            $table->string('pan', 15)->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('ifsc', 20)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0.00);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
            $table->index('vendor_name');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->string('product_code', 50)->unique();
            $table->string('product_type', 50)->default('Agro'); // Rice Bran Grade II, Boiled Bran, Rice Bran, Other
            $table->string('unit', 20)->default('MT'); // MT, KG, BAG
            $table->decimal('default_rate', 15, 2)->default(0.00);
            $table->decimal('default_cgst', 8, 3)->default(2.500);
            $table->decimal('default_sgst', 8, 3)->default(2.500);
            $table->decimal('default_igst', 8, 3)->default(5.000);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index('product_name');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_number', 30)->unique();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_mobile', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index('vehicle_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('products');
        Schema::dropIfExists('vendors');
    }
};
