<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private internal support notes about a tenant (#159) — "called about the
 * failed card, promised to pay Friday".
 *
 * ⚠️ OPERATOR-ONLY. These rows are written and read exclusively in the /admin
 * panel. Nothing under /app may ever query this table, and the model carries no
 * tenant trait precisely so it can never be surfaced through a tenant-scoped
 * query by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Which operator wrote it. Kept when the admin account is removed so
            // the note itself is not lost.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_name')->nullable();

            $table->text('body');

            // Pins a note to the top of the tenant's file (payment disputes etc.).
            $table->boolean('is_pinned')->default(false);

            $table->timestamps();

            $table->index(['business_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notes');
    }
};
