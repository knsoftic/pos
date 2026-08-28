<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan quota values (#8). See the null-semantics note on the `limits`
 * migration: row missing = inherit the registry default, value NULL = unlimited,
 * value 0 = nothing allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_limit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('limit_id')->constrained()->cascadeOnDelete();

            // NULL = unlimited for this plan.
            $table->unsignedBigInteger('value')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'limit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limit');
    }
};
