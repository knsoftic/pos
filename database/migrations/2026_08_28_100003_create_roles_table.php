<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles belong to a BUSINESS, not to the system (#51). Every tenant builds its
 * own — one shop's "Manager" may do things another's may not — so there is no
 * global role table and no role constant anywhere in the code.
 *
 * Three presets (Manager, Cashier, Stock Keeper) are copied into each new
 * business as ordinary editable rows. `is_system` only means "came from the
 * preset": the owner may rename it and re-tick its permissions, but not delete
 * it, so a business can never end up with no way back to a sane setup.
 *
 * The OWNER is deliberately not a role. `users.is_business_owner` is a property
 * of the account, checked before any role is consulted — a role that could be
 * edited into locking the owner out of their own business would be a footgun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 60);
            $table->string('description')->nullable();

            $table->boolean('is_system')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
