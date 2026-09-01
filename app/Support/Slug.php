<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Slugs that always fit their column (#100).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE BUG THIS EXISTS TO FIX
 *
 * Every `uniqueSlug()` in this codebase does the same sensible thing: slug the
 * name, and if that slug is taken, append `-2`, `-3` and so on. What none of
 * them did was leave room for the suffix.
 *
 * `roles.name` validates at 60 characters and `roles.slug` is a 60-character
 * column, so a 60-character name whose slug is already taken produced a
 * 62-character slug and a "Data too long" error — a 500 on an ordinary form,
 * from a name the form itself said was acceptable. `products` had the same
 * off-by-a-suffix at 180.
 *
 * It surfaced as an intermittent test failure, because it needs a long name AND
 * a collision. That is the worst way for a bug to live: rare enough to be
 * dismissed as flakiness, and certain to happen to somebody eventually.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The fix is to reserve the room up front, before the first attempt, so the
 * unsuffixed slug and the hundredth suffixed one are both valid.
 */
class Slug
{
    /** Enough for `-2` through `-99999`, which no name will ever exhaust. */
    public const SUFFIX_ROOM = 7;

    /**
     * The base slug for a name, trimmed so any collision suffix still fits.
     *
     * @param  int  $columnLength  the database column's own limit
     * @param  string  $fallback  used when the name slugs to nothing at all —
     *                            a name of only punctuation or of a script this
     *                            transliterator does not know
     */
    public static function base(string $name, int $columnLength, string $fallback = 'item'): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return $fallback;
        }

        // rtrim: cutting mid-word can leave a trailing dash, and `shop-` then
        // becomes `shop--2` once a suffix lands on it.
        return rtrim(Str::substr($slug, 0, max(1, $columnLength - self::SUFFIX_ROOM)), '-');
    }
}
