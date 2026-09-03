<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop asking to move onto a different plan (#82).
 *
 * ================= WHY A TABLE AND NOT A mailto: LINK =================
 * It used to be a mailto:. That means the shopkeeper's device has to have a
 * mail client configured, the operator has to notice one message among all the
 * others, and nothing anywhere records that the shop ever asked. On a phone
 * with no mail account set up, the button does nothing at all — which is what
 * "request nahi ja rahi" turned out to mean.
 *
 * A row is the opposite of all that: it survives the click, it is visible in
 * the operator's panel next to the shop it came from, and the shop can see that
 * the ask was received. WhatsApp is then an EXTRA route to the same request,
 * not the only copy of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Which plan they asked for. Restricted, not cascaded: deleting a
            // plan must not quietly erase the evidence that someone wanted it.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            // Which cycle they were looking at when they asked. Nullable
            // because the ask is "this plan", and the cycle is a detail the
            // operator will confirm anyway.
            $table->string('billing_cycle', 20)->nullable();

            // Who asked, and what they were on at the time — kept as plain
            // text so the record still reads correctly years later, after the
            // plan has been renamed or the person has left the shop.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->string('current_plan_name')->nullable();

            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            // The operator's list is "everything still waiting, newest first".
            $table->index(['status', 'created_at']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');
    }
};
