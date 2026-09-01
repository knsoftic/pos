<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;
use App\Support\ProtectedBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes "financial records are reversed, never erased" a rule instead of a
 * habit (#133, #198).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * Until now the guarantee rested on there being no delete route and every
 * service remembering. That held, and it is worth nothing: it is one route and
 * one `->delete()` away from not holding, and the day it breaks is the day a
 * shop's figures stop reconciling with no trace of why. A rule that lives in
 * everyone's memory is a rule the newest contributor has never heard.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE IS ABOUT STATUS, NOT ABOUT TABLES
 *
 * The obvious version — "a Sale can never be deleted" — is wrong, and wrongly
 * enough to break the till. A HELD sale is a basket: it posted no stock, no
 * ledger line and no money, and abandoning one is the correct thing to do. A
 * COMPLETED sale is a document somebody was handed. Same table, opposite
 * answers, so each model answers for itself in {@see isDeletableRecord()} and
 * the default here is NO.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ WHAT THIS DOES AND DOES NOT COVER
 *
 *  ✅ `$model->delete()`         — the `deleting` event.
 *  ✅ `$model->forceDelete()`    — soft-delete force still fires `deleting`.
 *  ✅ `$sale->items()->delete()` — a mass delete would normally skip model
 *     events entirely, which is exactly the hole worth closing; the builder is
 *     replaced so it deletes row by row and every row is checked. These tables
 *     are never mass-deleted in bulk by this app, so the cost is nil.
 *  ❌ `DB::table('sales')->delete()` — a raw query builder never reaches
 *     Eloquent. Nothing in PHP can stop that, and neither can anything stop
 *     someone with a MySQL prompt. This trait raises the floor; it is not a
 *     vault, and pretending otherwise would be the more dangerous claim.
 */
trait ProtectsFinancialRecords
{
    public static function bootProtectsFinancialRecords(): void
    {
        static::deleting(function (Model $model): void {
            if ($model->isDeletableRecord()) {
                return;
            }

            throw new ImmutableRecordException($model);
        });
    }

    /**
     * Can this particular row go?
     *
     * NO by default, deliberately: a model that joins the trait and forgets to
     * answer is protected rather than exposed, which is the right way round for
     * a mistake to fall.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }

    /**
     * Route mass deletes through the model, so `deleting` cannot be skipped.
     *
     * Untyped to match `Model::newEloquentBuilder()`, which Laravel leaves
     * untyped; narrowing it here is a fatal signature clash.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new ProtectedBuilder($query);
    }
}
