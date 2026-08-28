<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A `business` is the TENANT ROOT. Every tenant-owned record in the system
 * carries a `business_id` pointing here. This table itself is not tenant-scoped
 * (it defines the tenants). Subscription / plan columns are added in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();

            // Lifecycle status (fixed enum backed by Business::STATUS_* constants).
            // This is account state, not user-editable business data.
            $table->string('status')->default('active')->index();

            // Display/formatting defaults (overridable per business in Settings, Phase 11).
            $table->string('timezone')->default('UTC');
            $table->string('locale', 10)->default('en');

            // Which super admin created this business (nullable: public trial signups have none yet).
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
