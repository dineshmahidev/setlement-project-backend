<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('module')->default('general');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 30)->unique();
            $table->string('branch_name');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('state', 100)->nullable()->default('Tamil Nadu');
            $table->string('pincode', 10)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'branch_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted')->default(true);
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 30)->nullable()->unique()->after('name');
            $table->string('mobile', 20)->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('mobile')->constrained('roles')->nullOnDelete();
            $table->foreignId('default_branch_id')->nullable()->after('role_id')->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('password');
        });

        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->string('fy_code', 20)->unique(); // e.g., 2026-27
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('gst_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('cgst_rate', 8, 3)->default(2.500);
            $table->decimal('sgst_rate', 8, 3)->default(2.500);
            $table->decimal('igst_rate', 8, 3)->default(5.000);
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50); // settlement, debit_note, invoice
            $table->string('prefix', 20); // SB, DN, INV
            $table->unsignedBigInteger('current_number')->default(0);
            $table->string('financial_year', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_type', 'prefix', 'financial_year', 'branch_id'], 'doc_seq_unique');
        });

        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('LAXMI TRADERS');
            $table->string('trade_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('state')->nullable()->default('Tamil Nadu');
            $table->string('pincode', 10)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('cin', 30)->nullable();
            $table->string('pan', 15)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc_code')->nullable();
            $table->string('branch_bank')->nullable();
            $table->string('rounding_mode', 30)->default('nearest_rupee'); // nearest_rupee, two_decimal, floor, ceil
            $table->string('gst_api_provider', 50)->nullable();
            $table->text('gst_api_key')->nullable();
            $table->text('gst_api_secret')->nullable();
            $table->boolean('gst_api_enabled')->default(false);
            $table->string('settlement_prefix', 20)->default('SB');
            $table->string('debit_note_prefix', 20)->default('DN');
            $table->string('invoice_prefix', 20)->default('INV');
            $table->text('terms_conditions')->nullable();
            $table->string('authorized_signatory_title')->default('Authorised Signatory');
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_role')->nullable();
            $table->string('action', 50); // created, updated, deleted, approved, posted, cancelled, manual_adjustment
            $table->string('entity_type', 50); // Branch, Vendor, Product, Settlement, DebitNote, Invoice, Setting
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('document_number', 50)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('remarks')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('gst_rates');
        Schema::dropIfExists('financial_years');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('default_branch_id');
            $table->dropColumn(['employee_id', 'mobile', 'status']);
        });
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
