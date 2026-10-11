<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('slug', 40)->unique();
            $table->string('company_name');
            $table->string('tagline')->nullable();
            $table->string('sub_tagline')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('short_name', 60)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('state')->nullable();
            $table->string('state_code', 5)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 15)->nullable();
            $table->string('cin', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            $table->string('bank_name')->nullable();
            $table->string('branch_bank')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('ifsc_code', 20)->nullable();

            $table->string('settlement_prefix', 20)->default('STL');
            $table->string('debit_note_prefix', 20)->default('DN');
            $table->string('invoice_prefix', 20)->default('INV');
            $table->string('rounding_mode', 20)->default('nearest');

            $table->string('gst_api_provider')->nullable();
            $table->string('gst_api_key')->nullable();
            $table->string('gst_api_secret')->nullable();
            $table->boolean('gst_api_enabled')->default(false);

            $table->text('terms_conditions')->nullable();
            $table->string('authorized_signatory_title')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('signature_path')->nullable();

            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'company_id']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('branch_code')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::dropIfExists('user_companies');
        Schema::dropIfExists('companies');
    }
};
