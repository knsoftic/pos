<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tills / registers inside a branch (#49). A cashier is assigned to one, and
 * from Phase 7 every sale and cash session records which counter it happened on
 * — that is what makes "close the drawer on counter 2" a meaningful question.
 *
 * `business_id` is stored even though it is reachable through the branch: the
 * tenant scope must be able to filter this table on its own, without a join, and
 * a counter must never end up pointing at another tenant's branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 20);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_counters');
    }
};
