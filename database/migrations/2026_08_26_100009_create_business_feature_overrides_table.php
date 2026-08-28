<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-business feature override (#10) — lets the operator grant or revoke one
 * capability for a single tenant without inventing a bespoke plan for them
 * ("give this customer the export feature as a goodwill gesture").
 *
 * Resolution order, highest wins:  business override → plan_feature → features.default_enabled
 *
 * A missing row means "inherit". To go back to inheriting, the row is DELETED
 * rather than set to some third value — one representation per state.
 * Every change here is written to the audit log. #177
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled');

            // Why the operator granted/revoked it — shows in the tenant's admin view.
            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_feature_overrides');
    }
};
