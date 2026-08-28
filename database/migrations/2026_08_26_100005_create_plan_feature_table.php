<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which features each plan grants (#9). A missing row means "inherit
 * features.default_enabled", so adding a feature to the registry does not
 * silently switch it on for every existing plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique(['plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature');
    }
};
