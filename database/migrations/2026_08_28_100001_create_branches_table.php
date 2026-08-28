<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop locations (#47). The first unit of data control below the tenant: from
 * Phase 4 onward stock, sales and cash sessions all hang off a branch, and who
 * may see them is decided by which branches the employee can reach (#48, #138).
 *
 * Every business gets a main branch the moment it is created — a single-shop
 * tenant should never have to think about branches at all, it just happens to
 * have exactly one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Short human code used on invoice numbers and reports (BR-01, MAIN).
            $table->string('code', 20);

            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();

            // Exactly one main branch per business; the service layer keeps that
            // true, and it is the fallback for anything that needs "the" branch.
            $table->boolean('is_main')->default(false);

            // Closed for business but its history stays readable. Deleting a
            // branch that has sales is never allowed (#104) — it is archived.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Codes are reserved even after archiving, so an old branch code can
            // never be reused and make two eras of history look like one shop.
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
