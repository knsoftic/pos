<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Support\FeatureRegistry;
use Illuminate\Database\Seeder;

/**
 * Syncs {@see FeatureRegistry} into the `features` table.
 *
 * IDEMPOTENT and safe to re-run: adding a constant to the registry and running
 * this seeder is the entire workflow for shipping a new flag.
 *
 * A code that disappears from the registry is DEACTIVATED, not deleted —
 * `plan_feature` and `business_feature_overrides` reference these rows, and a
 * cascade would quietly rewrite what customers are paying for (#104).
 *
 * `name`/`description`/`group` are refreshed on every run, but `default_enabled`
 * is only written when the row is created: once live, that value is operator
 * data and a deploy must not silently re-grant a flag they turned off.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $sortOrder = 0;
        $codes = [];

        foreach (FeatureRegistry::all() as $code => $meta) {
            $codes[] = $code;

            $feature = Feature::query()->firstOrNew(['code' => $code]);

            $feature->fill([
                'name' => $meta['name'],
                'description' => $meta['description'],
                'group' => $meta['group'],
                'sort_order' => $sortOrder += 10,
            ]);

            if (! $feature->exists) {
                $feature->default_enabled = $meta['default_enabled'];
                $feature->is_active = true;
            }

            $feature->save();
        }

        $retired = Feature::query()
            ->whereNotIn('code', $codes)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->command?->info(sprintf(
            '  Features synced: %d in registry%s',
            count($codes),
            $retired > 0 ? ", {$retired} retired" : '',
        ));
    }
}
