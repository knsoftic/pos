<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product categories, with subcategories (#26).
 *
 * One self-referencing table rather than two (`categories` + `subcategories`):
 * a shop that today files everything under "Drinks" may tomorrow want
 * "Drinks → Cold → Cans", and a two-level schema would have to be migrated to
 * allow it. The UI presents two levels; the data allows more.
 *
 * `parent_id` cascades on delete: removing a parent takes its children with it,
 * which is only ever reachable when nothing references any of them (#104).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 140);
            $table->string('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'parent_id']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
