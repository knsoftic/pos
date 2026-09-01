<?php

use App\Enums\LedgerEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers (#38). Tenant-scoped, business-level like customers (#137) — the
 * wholesaler who delivers to three branches is one supplier, with one balance.
 *
 * `balance` is the mirror of the customer's: positive means the business OWES
 * this supplier. Same arithmetic (debit up, credit down), different heading —
 * see {@see LedgerEntryType} for why that is one rule rather than two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 30);

            // The person you actually ring, as distinct from the company.
            $table->string('contact_person')->nullable();

            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('tax_number', 60)->nullable();

            // How long they give the business to pay. Informational until
            // purchases arrive (Phase 6), when it drives due dates.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            // Positive = the business owes them. Cache over the ledger.
            $table->decimal('balance', 14, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->string('blocked_reason')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
