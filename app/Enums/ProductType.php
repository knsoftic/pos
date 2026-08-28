<?php

namespace App\Enums;

/**
 * What kind of thing is being sold (#25).
 *
 * This is a fixed system concept, not tenant data: each type changes how the
 * rest of the app behaves, so the code has to know the whole set.
 *
 *   Standard — one item, one price, one stock figure.
 *   Service  — labour, delivery, a repair. Sold like anything else but there is
 *              nothing to count, so inventory never touches it.
 *   Variable — one product, many variants (size / colour), each with its own
 *              SKU, price and stock. Needs the `catalog.variants` feature.
 */
enum ProductType: string
{
    case Standard = 'standard';
    case Service = 'service';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Service => 'Service',
            self::Variable => 'Variable',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standard => 'A single item with one price and one stock figure.',
            self::Service => 'Something you do, not something you stock — labour, delivery, repairs.',
            self::Variable => 'One product with variants: size, colour, pack. Each keeps its own price and stock.',
        };
    }

    /**
     * Does this type carry stock at all?
     *
     * The single source of truth for the question. A service must never appear
     * in an inventory count, a low-stock alert or a stock-out block, and having
     * one method say so keeps every module agreeing.
     */
    public function tracksStock(): bool
    {
        return $this !== self::Service;
    }

    /** Are prices and stock held on variants rather than the product itself? */
    public function hasVariants(): bool
    {
        return $this === self::Variable;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Standard => 'badge-slate',
            self::Service => 'badge-brand',
            self::Variable => 'badge-amber',
        };
    }

    /** @return array<string, string> value => label, for <select>s. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
