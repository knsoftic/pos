<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's own settings (#110, #111, #160).
 *
 * ================= NOT THE SAME TABLE AS `settings` =================
 * `settings` is per business and answers "how does this SHOP operate?".
 * This one is the whole platform and answers "how does this INSTALLATION
 * behave?" — what it is called, whether anyone may sign up, whether it is in
 * maintenance. There is exactly one of each, for everybody.
 *
 * Sharing one table with a nullable `business_id` would have made every tenant
 * query say `WHERE business_id = ? OR business_id IS NULL`, and the first place
 * that forgot the second half would either miss a platform setting or — far
 * worse — let a tenant write one.
 *
 * Same storage idea as `settings`: only what the operator has CHANGED, so an
 * untouched key keeps following `config/` and a better default still lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();

            // Matches PlatformSettingRegistry, which is also the config key it
            // overlays — `brand.name` overrides config('brand.name').
            $table->string('key', 100)->unique();

            $table->text('value')->nullable();

            // Admins, not users: a tenant can never be the author of one.
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
