<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-business quota override (#10) — "this tenant may have 2,000 products even
 * though their plan says 500".
 *
 * Resolution order, highest wins:  business override → plan_limit → limits default
 *
 * NULL `value` = unlimited. Missing row = inherit. Same convention as everywhere
 * else in this layer. Changes are audited (#177).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_limit_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('limit_id')->constrained()->cascadeOnDelete();

            // NULL = unlimited for this business.
            $table->unsignedBigInteger('value')->nullable();

            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'limit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_limit_overrides');
    }
};
