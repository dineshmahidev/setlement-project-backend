<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique();
            $table->date('invoice_date');
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('lot_number', 50)->nullable()->index();
            $table->string('vehicle_number', 30)->nullable();
            $table->decimal('net_weight_mt', 15, 3)->default(0.000);
            $table->decimal('rate', 15, 2)->default(0.00);
            $table->decimal('taxable_amount', 15, 2)->default(0.00);
            $table->boolean('is_interstate')->default(false);
            $table->decimal('cgst_rate', 8, 3)->default(2.500);
            $table->decimal('sgst_rate', 8, 3)->default(2.500);
            $table->decimal('igst_rate', 8, 3)->default(5.000);
            $table->decimal('cgst_amount', 15, 2)->default(0.00);
            $table->decimal('sgst_amount', 15, 2)->default(0.00);
            $table->decimal('igst_amount', 15, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('status', 20)->default('draft'); // draft, approved, settled, cancelled
            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'invoice_date']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number', 50)->unique();
            $table->string('financial_year', 20)->nullable();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            
            // Basic Details
            $table->string('lot_number', 50)->nullable()->index();
            $table->string('bill_number', 50)->nullable();
            $table->date('bill_date')->nullable();
            $table->date('settlement_date');
            $table->string('vehicle_number', 30)->nullable();
            
            // Weight Details
            $table->decimal('net_weight_mt', 15, 3)->default(0.000);
            $table->decimal('jute_bag', 15, 3)->default(0.000);
            $table->decimal('pp_bag', 15, 3)->default(0.000);
            $table->decimal('final_weight_mt', 15, 3)->default(0.000);
            $table->decimal('final_weight_kg', 15, 3)->default(0.000);
            
            // Quality Tests
            $table->decimal('oil_content', 8, 3)->default(0.000);
            $table->string('ffa', 50)->nullable()->default('PASS');
            $table->string('ss', 50)->nullable()->default('PASS');
            $table->string('fibre', 50)->nullable()->default('PASS');
            $table->string('moisture', 50)->nullable()->default('PASS');
            $table->string('iv', 50)->nullable();
            $table->string('calcium', 50)->nullable();
            $table->string('protein', 50)->nullable();
            
            // Rates & Bran Value
            $table->decimal('contract_unit_rate', 15, 2)->default(0.00);
            $table->decimal('final_unit_rate', 15, 2)->default(0.00);
            $table->decimal('oil_quantity_kg', 15, 3)->default(0.000);
            $table->decimal('bran_value', 15, 2)->default(0.00);
            
            // Charges
            $table->decimal('bag_amount', 15, 2)->default(0.00);
            $table->decimal('loading_charges', 15, 2)->default(0.00);
            $table->decimal('unloading_charges', 15, 2)->default(0.00);
            $table->decimal('tds', 15, 2)->default(0.00);
            $table->decimal('advance_paid', 15, 2)->default(0.00);
            $table->decimal('lorry_freight', 15, 2)->default(0.00);
            $table->decimal('other_charges', 15, 2)->default(0.00);
            
            // Original Invoice comparison & Rebate
            $table->decimal('original_invoice_value', 15, 2)->default(0.00);
            $table->decimal('rebate_amount', 15, 2)->default(0.00);
            $table->decimal('manual_adjustment', 15, 2)->default(0.00);
            $table->text('manual_adjustment_reason')->nullable();
            
            // GST & Net Payable
            $table->decimal('subtotal_amount', 15, 2)->default(0.00);
            $table->boolean('is_interstate')->default(false);
            $table->decimal('cgst_rate', 8, 3)->default(2.500);
            $table->decimal('sgst_rate', 8, 3)->default(2.500);
            $table->decimal('igst_rate', 8, 3)->default(5.000);
            $table->decimal('cgst_amount', 15, 2)->default(0.00);
            $table->decimal('sgst_amount', 15, 2)->default(0.00);
            $table->decimal('igst_amount', 15, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->decimal('net_payable', 15, 2)->default(0.00);
            
            // Status & Audit
            $table->string('status', 20)->default('draft'); // draft, approved, posted, cancelled
            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            
            $table->index(['branch_id', 'settlement_date']);
            $table->index('vendor_id');
        });

        Schema::create('settlement_quality_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->string('parameter_name', 50);
            $table->string('parameter_value', 100)->nullable()->default('PASS');
            $table->string('standard_value', 100)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->string('charge_name', 100);
            $table->enum('charge_type', ['addition', 'deduction'])->default('addition');
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->string('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('debit_note_number', 50)->unique();
            $table->string('financial_year', 20)->nullable();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            
            $table->string('invoice_number', 50)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('lot_number', 50)->nullable();
            $table->date('date');
            $table->string('reason', 100)->default('Rebate');
            $table->string('transaction_type', 20)->default('debit'); // debit, credit
            $table->decimal('taxable_amount', 15, 2)->default(0.00);
            
            $table->boolean('is_interstate')->default(false);
            $table->decimal('cgst_rate', 8, 3)->default(2.500);
            $table->decimal('sgst_rate', 8, 3)->default(2.500);
            $table->decimal('igst_rate', 8, 3)->default(5.000);
            $table->decimal('cgst_amount', 15, 2)->default(0.00);
            $table->decimal('sgst_amount', 15, 2)->default(0.00);
            $table->decimal('igst_amount', 15, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            
            $table->string('status', 20)->default('draft'); // draft, approved, posted, cancelled
            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            
            $table->index(['branch_id', 'date']);
            $table->index('vendor_id');
        });

        Schema::create('debit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_note_id')->constrained('debit_notes')->cascadeOnDelete();
            $table->string('particulars', 150);
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->string('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('transaction_date');
            $table->string('document_type', 50); // invoice, settlement, debit_note, credit_note, payment, receipt, opening_balance
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_number', 50)->nullable();
            $table->string('lot_number', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0.00);
            $table->decimal('credit', 15, 2)->default(0.00);
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            $table->index(['vendor_id', 'transaction_date']);
            $table->index(['branch_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ledger');
        Schema::dropIfExists('debit_note_items');
        Schema::dropIfExists('debit_notes');
        Schema::dropIfExists('settlement_charges');
        Schema::dropIfExists('settlement_quality_tests');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('invoices');
    }
};
