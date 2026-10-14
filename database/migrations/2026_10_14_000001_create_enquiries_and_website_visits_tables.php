<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone', 30);
            $table->string('email', 150)->nullable();
            $table->string('requirement')->nullable();
            $table->text('message')->nullable();
            $table->string('source', 50)->default('website');
            $table->string('status', 30)->default('new'); // new, contacted, in_progress, converted, closed
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('website_visits', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100)->index();
            $table->string('page_url', 500);
            $table->string('page_title', 255)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type', 30)->default('desktop');
            $table->string('browser', 50)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_visits');
        Schema::dropIfExists('enquiries');
    }
};
