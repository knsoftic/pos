<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admins (SaaS operators) live in their OWN table, separate from business
 * `users`. They authenticate on the `admin` guard and are NEVER tenant-scoped —
 * they operate across all businesses. This hard separation prevents a super
 * admin from ever being accidentally filtered by a business_id, and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
