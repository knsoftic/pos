<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an employee sits in the organisation (#50, #138, #141).
 *
 * All four columns are nullable on purpose:
 *   role_id       NULL → no role, therefore no permissions at all (the owner
 *                        needs none, everyone else must be given one). #51
 *   branch_id     NULL → not tied to a branch. For an owner that means every
 *                        branch; for anyone else the branch gate denies. #138
 *   pos_counter_id NULL → not tied to a till. #49
 *   max_discount_percent NULL → no personal cap. 0 means no discount at all,
 *                        which is a real setting and must not be confused with
 *                        "unset". #141
 *
 * `nullOnDelete` on all three FKs: archiving a branch or role must never delete
 * a person's login. They lose their assignment and the gates fail closed until
 * someone re-assigns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('is_business_owner')
                ->constrained('roles')->nullOnDelete();

            $table->foreignId('branch_id')->nullable()->after('role_id')
                ->constrained('branches')->nullOnDelete();

            $table->foreignId('pos_counter_id')->nullable()->after('branch_id')
                ->constrained('pos_counters')->nullOnDelete();

            // 0.00–100.00. Enforced server-side by the POS from Phase 7; stored
            // here so the cap follows the person, not the till they stand at.
            $table->decimal('max_discount_percent', 5, 2)->nullable()->after('pos_counter_id');

            $table->index(['business_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'branch_id']);
            $table->dropConstrainedForeignId('pos_counter_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('max_discount_percent');
        });
    }
};
