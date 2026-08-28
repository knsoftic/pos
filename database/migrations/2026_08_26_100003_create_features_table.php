<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central feature registry (#128). Every gate-able capability in the SaaS has a
 * row here with a stable `code` — `pos.hold_sales`, `reports.export_pdf`, … —
 * and plans switch them on/off through the `plan_feature` pivot (#9).
 *
 * Why a DB table rather than a PHP array: the operator has to be able to see,
 * group and reorder features in the plan editor, and the pricing-page
 * comparison matrix (#84) is built from this. The canonical list of codes still
 * lives in {@see \App\Support\FeatureRegistry} so application code can reference
 * them with autocomplete instead of magic strings; the registry SEEDS this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();

            // Stable machine key used in code and middleware (`feature:pos.hold_sales`).
            $table->string('code', 80)->unique();

            $table->string('name');
            $table->string('description')->nullable();

            // Section heading in the plan editor / comparison matrix, e.g. "POS".
            $table->string('group', 40)->default('general')->index();

            // Value used when a plan has no explicit pivot row. Defaults to
            // FALSE so a newly added feature is opt-in, never silently granted.
            $table->boolean('default_enabled')->default(false);

            $table->unsignedInteger('sort_order')->default(0)->index();

            // Retired features stay in the table (old plans still reference them)
            // but disappear from the UI.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
