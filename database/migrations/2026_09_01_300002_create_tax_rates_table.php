<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax rates a shop actually charges (#59).
 *
 * ================= WHY A TABLE AND NOT A NUMBER =================
 * Products already carry a numeric `tax_rate`, and they keep it: a sale line
 * snapshots the rate that applied when it sold, the same way it snapshots the
 * cost (#52). If the rate lived only here and a sale read it by relation, then
 * the day the government moved VAT from 17% to 18% every invoice ever printed
 * would silently restate itself.
 *
 * What this table adds is the NAMED LIST a shop picks from — "Standard 17%",
 * "Reduced 5%", "Zero-rated" — so nobody has to remember which number goes on
 * which product, and so a rate change is one row rather than a thousand
 * products edited by hand.
 *
 * ⚠️ Changing a rate here changes what NEW lines get. It deliberately does not
 * touch a single line already sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->decimal('rate', 6, 3);

            // The one offered first on a new product. Exactly one per business,
            // enforced in the service rather than the schema because "exactly
            // one" needs a transaction to move, not a constraint to refuse.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
