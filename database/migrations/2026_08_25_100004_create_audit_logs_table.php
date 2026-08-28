<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail (foundation for #61 / #133 / #177). Rows are immutable
 * (no updated_at). `actor_type` distinguishes 'admin' | 'user' | 'system' so we
 * can record both super-admin and business-user actions in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->nullableMorphs('auditable');
            $table->string('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['business_id', 'created_at']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
