<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages from the operator to every shop (#77).
 *
 * ================= AN ANNOUNCEMENT IS A MESSAGE, NOT AN ALERT =================
 * The bell in a tenant's workspace shows two different kinds of thing and they
 * behave in opposite ways:
 *
 *   an ALERT is a CONDITION — "six products are below their alert level". It is
 *   computed live and it disappears when the shop fixes it. It cannot be
 *   dismissed, because an alert you can dismiss while it is still true is a lie.
 *
 *   an ANNOUNCEMENT is a MESSAGE — "maintenance on Sunday". It is written once,
 *   it does not become false, and a person who has read it must be able to make
 *   it go away or the bell becomes noise they learn to ignore.
 *
 * That is why only this one has a dismissals table.
 *
 * ================= WHY IT HAS DATES =================
 * "Maintenance on Sunday" is worse than useless on Monday. An announcement
 * carries the window it is true for, so the operator writes it once in advance
 * and never has to remember to take it down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('body');

            // info | warning | danger — how loudly a shop should read it.
            $table->string('level', 20)->default('info');

            $table->boolean('is_active')->default(true);

            // Null start = already running; null end = until switched off.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Whether a shop may put it away, or whether it stays until it
            // expires — an outage notice is not a thing to be swiped aside.
            $table->boolean('is_dismissible')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');

            // Per PERSON, not per business: the owner reading it does not mean
            // the cashier has.
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
    }
};
