<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving stock from one branch to another (#32).
 *
 * The three timestamps below are the whole point of this table. A transfer is
 * not one event: goods are written down, then they leave, then someone counts
 * what actually turned up. Each step has a different person and a different
 * moment, and a shortfall is only meaningful if you can say which leg it
 * happened on.
 *
 * NOT soft-deleted. A transfer that has moved stock is cancelled, never
 * deleted — the ledger it wrote cannot be un-written (#133, #198). A draft has
 * moved nothing, so that one may genuinely be removed.
 *
 * `reference` is per business and generated in the same transaction as the row,
 * so two people clicking "New transfer" at once cannot both get TRF-000004.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 30);

            // restrictOnDelete: a branch with transfer history is archived, not
            // deleted (#104) — otherwise the history would lose an end.
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();

            // App\Enums\TransferStatus
            $table->string('status', 20)->default('draft')->index();

            $table->text('notes')->nullable();

            // Who did what, and when. Nullable users because staff leave and
            // their transfers must still read correctly.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'reference']);

            // "What is coming to my branch" and "what have I sent" are the two
            // questions the screens ask.
            $table->index(['business_id', 'status']);
            $table->index(['to_branch_id', 'status']);
            $table->index(['from_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
