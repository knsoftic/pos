<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-business settings (#57, #190).
 *
 * ================= ONLY THE OVERRIDES LIVE HERE =================
 * The config files stay the source of DEFAULTS — `config/pos.php`,
 * `config/inventory.php` and the rest already carry every knob with a sensible
 * value and an explanation of why it is what it is. This table holds only what
 * a shop has actually CHANGED.
 *
 * That is deliberate. A row per setting per business would mean 40 rows for
 * every tenant on the day they sign up, all of them repeating the defaults, and
 * shipping a better default would then require a data migration to reach the
 * shops that never touched it. Storing only overrides means an untouched
 * setting keeps following the config file, and it also makes "what has this
 * shop changed?" a question the table can answer on its own.
 *
 * ================= WHY value IS TEXT =================
 * A setting is a boolean, a number, a string or a list depending on which one
 * it is, and the type belongs to the SettingRegistry, not to the column. Values
 * are stored JSON-encoded and cast back on the way out, so a list of payment
 * methods and a rounding increment can share one table without four nullable
 * typed columns that are empty three times out of four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Matches a key in SettingRegistry, which is also the config key it
            // overlays — `pos.cash_rounding` overrides config('pos.cash_rounding').
            $table->string('key', 100);

            $table->text('value')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
