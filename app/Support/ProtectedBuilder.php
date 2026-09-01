<?php

namespace App\Support;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\ProtectsFinancialRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query builder used by every model with {@see ProtectsFinancialRecords}.
 *
 * It exists to close one hole. Eloquent's `Builder::delete()` compiles a single
 * `DELETE ... WHERE ...` and never loads a model, which means **model events do
 * not fire** — so `$sale->items()->delete()` would walk straight past the
 * `deleting` hook the whole guarantee rests on. That is not a loophole anyone
 * would have to go looking for; it is the ordinary way to delete a relation's
 * rows, and it is written all over this codebase.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ IT CHECKS THE ROWS; IT DOES NOT DELETE THEM ONE BY ONE
 *
 * The obvious implementation — iterate and call `$model->delete()` on each —
 * hangs. `Model::performDeleteOnModel()` issues its own single-row delete
 * through `newModelQuery()`, which is THIS builder, so every row re-enters this
 * method and recurses until the process runs out of memory. (It did, on the
 * first run, which is why this note is here rather than in a commit message.)
 *
 * So: load the affected rows, ask each one whether it may go, and then hand the
 * real DELETE back to Eloquent untouched.
 *
 * The cost is one extra SELECT, and on a mass delete it holds the matched rows
 * in memory. Affordable because the only bulk deletes this app performs on
 * protected tables are the lines of ONE held sale or ONE draft purchase.
 * Nothing prunes these tables, and nothing ever should.
 */
class ProtectedBuilder extends Builder
{
    public function delete()
    {
        foreach ((clone $this)->get() as $model) {
            if (! $model->isDeletableRecord()) {
                throw new ImmutableRecordException($model);
            }
        }

        return parent::delete();
    }
}
