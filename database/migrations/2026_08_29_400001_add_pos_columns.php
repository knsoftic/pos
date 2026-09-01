<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small things the till needs (#147, #91).
 *
 * `products.is_favourite` — the handful a shop sells all day, pinned to the
 * front of the POS grid. A boolean on the product rather than a per-user list:
 * favourites describe what the SHOP sells, not what one cashier likes, and every
 * till should open showing the same dozen things.
 *
 * `sales.idempotency_key` — the real defence against a double-submitted sale
 * (#91). Disabling the button after a click is a courtesy; it does not survive a
 * flaky connection, an impatient double-tap, or a retry. The till generates one
 * key per cart, and the unique index means a repeat of the SAME cart can only
 * ever produce one sale, however many times the request arrives.
 *
 * Nullable, because a sale created from anywhere other than the till (an import,
 * a future API) has no cart to key on — and NULLs do not collide in a unique
 * index, which is exactly the behaviour wanted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_favourite')->default(false)->after('is_active');
            $table->index(['business_id', 'is_favourite']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('invoice_no');
            $table->unique(['business_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_favourite']);
            $table->dropColumn('is_favourite');
        });
    }
};
