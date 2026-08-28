<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which permission codes a role grants (#51).
 *
 * The codes live in {@see PermissionRegistry}, not in a `permissions`
 * table, for the same reason feature codes do: a permission only exists if some
 * code path checks it, so its home is the code. This table is the tenant's
 * ANSWERS, and an unknown code here simply resolves to false — deploying a
 * release that removes a permission cannot break a tenant's roles.
 *
 * One row per granted permission. Absence is denial: there is no "deny" row, so
 * there is no allow-vs-deny precedence puzzle to get wrong later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 80);

            $table->unique(['role_id', 'permission']);
            $table->index('permission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
