<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admins get their OWN password-reset token table.
 *
 * The default `password_reset_tokens` table is keyed by email. Since admins and
 * business users live in separate tables, the same email could legitimately
 * exist in both — sharing one token table would make their reset requests
 * overwrite each other. Separate tables keep the two brokers independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_tokens');
    }
};
