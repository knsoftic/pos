<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue (#24, #25, #105, #149).
 *
 * MONEY IS DECIMAL, NEVER FLOAT. Cost carries four places because a unit cost
 * is often a fraction of a paisa once a case price is divided down; selling
 * price carries two, because that is what a customer is actually charged.
 *
 * For a VARIABLE product the price and stock columns here are ignored — each
 * {@see ProductVariant} carries its own (#25). They stay non-null so a product
 * that changes type does not leave nulls behind for the POS to trip over.
 *
 * `track_inventory` is stored rather than derived from the type so a shop can
 * keep a physical item off the stock ledger (a free carrier bag, a display
 * unit) without inventing a service for it. A service can never track stock —
 * the service layer enforces that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // All three optional: a shop should be able to add its first product
            // in ten seconds without first inventing a taxonomy (#195).
            // restrictOnDelete because a category in use is archived, not
            // deleted (#104) — the FK is the last line of that defence.
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();

            // App\Enums\ProductType
            $table->string('type', 20)->default('standard');

            $table->string('name');
            $table->string('slug', 180);

            // Every sellable row has an SKU, generated when the shop does not
            // supply one. Unique per business, never globally.
            $table->string('sku', 60);

            // Scanned at the till (#27). Nullable — plenty of products never get
            // one — but unique per business when present.
            $table->string('barcode', 60)->nullable();

            $table->text('description')->nullable();

            // Single image + a placeholder in the UI (#149). A gallery would be
            // a second table; nothing in the POS or on a receipt needs one yet.
            $table->string('image_path')->nullable();

            $table->decimal('cost_price', 14, 4)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);

            // Per-product override of the business tax rate (#SALES_TAX). NULL
            // means "use whatever the business setting says" (Phase 11).
            $table->decimal('tax_rate', 5, 2)->nullable();

            $table->boolean('track_inventory')->default(true);

            // Low-stock threshold (#33). NULL = never warn about this one.
            $table->decimal('alert_quantity', 16, 4)->nullable();

            // Active/Inactive (#105) — an inactive product stays in history and
            // in reports but cannot be sold or purchased.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Products with history are archived, never destroyed (#104, #198).
            $table->softDeletes();

            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->unique(['business_id', 'slug']);

            // The POS searches by name constantly, and the catalogue screen
            // filters by category/brand/status (#167).
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'category_id']);
            $table->index(['business_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
