<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure (#26), built now so unit CONVERSION can arrive later without
 * a migration (#158).
 *
 * The structure is the whole point:
 *   base_unit_id NULL  → this IS a base unit (Piece, Kilogram, Litre).
 *   base_unit_id set   → a derived unit, and `conversion_factor` says how many
 *                        BASE units one of these is worth (Dozen → 12 Piece,
 *                        Gram → 0.001 Kilogram).
 *
 * Stock is always kept in the base unit. Selling a Dozen removes 12 Pieces;
 * nothing else in the system has to know that a dozen exists. Phase 4 only
 * reads the factor for display — the multi-unit selling UI is gated behind the
 * `catalog.multi_unit` feature and lands with the POS.
 *
 * Deliberately generous precision on the factor: 0.001 (gram) and 1000 (tonne)
 * must both be exact, so this is a decimal, never a float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);

            // What appears next to a quantity: kg, pc, ltr.
            $table->string('short_name', 12);

            $table->foreignId('base_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 16, 6)->default(1);

            // Whether quantities may have a fractional part. Weighed goods yes,
            // countable goods no — selling 2.5 phones should be impossible.
            $table->boolean('allows_decimals')->default(false);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'short_name']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
