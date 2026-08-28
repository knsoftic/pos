<?php

namespace Database\Seeders;

use App\Models\Limit;
use App\Support\LimitRegistry;
use Illuminate\Database\Seeder;

/**
 * Syncs {@see LimitRegistry} into the `limits` table. Same contract as
 * {@see FeatureSeeder}: idempotent, retires rather than deletes, and does not
 * overwrite the default values of a row that already exists.
 */
class LimitSeeder extends Seeder
{
    public function run(): void
    {
        $sortOrder = 0;
        $codes = [];

        foreach (LimitRegistry::all() as $code => $meta) {
            $codes[] = $code;

            $limit = Limit::query()->firstOrNew(['code' => $code]);

            $limit->fill([
                'name' => $meta['name'],
                'description' => $meta['description'],
                'group' => $meta['group'],
                'unit' => $meta['unit'],
                'is_monthly' => $meta['is_monthly'],
                'sort_order' => $sortOrder += 10,
            ]);

            if (! $limit->exists) {
                $limit->default_value = $meta['default_value'];
                $limit->default_unlimited = $meta['default_unlimited'];
                $limit->is_active = true;
            }

            $limit->save();
        }

        $retired = Limit::query()
            ->whereNotIn('code', $codes)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->command?->info(sprintf(
            '  Limits synced: %d in registry%s',
            count($codes),
            $retired > 0 ? ", {$retired} retired" : '',
        ));
    }
}
