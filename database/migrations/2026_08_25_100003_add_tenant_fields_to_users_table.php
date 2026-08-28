<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business users (owners, managers, cashiers) live in the default `users` table.
 * Here we attach them to their tenant via `business_id` and add profile/status
 * fields. `branch_id` / `pos_counter_id` scoping columns are added in Phase 3
 * (alongside the branches & pos_counters tables that they reference).
 *
 * NOTE: email stays globally unique (from the base migration) so login by email
 * alone is unambiguous. (Per-business email reuse is a possible future change.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->after('id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('phone')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('is_business_owner')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('is_business_owner');
            $table->softDeletes();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn([
                'business_id', 'phone', 'avatar_path',
                'is_active', 'is_business_owner', 'last_login_at', 'deleted_at',
            ]);
        });
    }
};
