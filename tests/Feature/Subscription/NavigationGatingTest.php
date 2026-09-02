<?php

namespace Tests\Feature\Subscription;

use App\Support\FeatureRegistry;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * The sidebar must hide exactly what the app refuses — no more, no less.
 *
 * ================= WHY THIS EXISTS =================
 * A menu item and the screen behind it are gated in two different places, and
 * when they disagree the result is one of two silent failures:
 *
 *   nav stricter than the app   the screen works but nobody can find it. This
 *                               is how "Suppliers sidebar mein show nahi ho
 *                               raha" was reported — the whole supplier module
 *                               sat behind `purchases.supplier_ledger`, which
 *                               is off by default, while purchase orders (on by
 *                               default) cannot exist without a supplier.
 *
 *   nav looser than the app     a dead link: the item is there, clicking it is
 *                               refused, and the shop believes the product is
 *                               broken rather than unbought.
 *
 * ⚠️ ENFORCEMENT IS NOT ONLY THE `feature:` MIDDLEWARE. A permission carries its
 * own feature anchor in {@see PermissionRegistry}, and `PermissionService`
 * checks all three layers — so `permission:roles.manage` already refuses when
 * `team.roles` is off, with no `feature:` middleware in sight. A check that
 * looked only at route middleware would report four false alarms here; this one
 * asks what is ACTUALLY enforced.
 */
class NavigationGatingTest extends TestCase
{
    /**
     * The nav lives as an array inside the layout, so it is read from there.
     * Parsing a Blade file is not lovely; a test that quietly drifted from the
     * real menu would be worse.
     *
     * @return list<array{label: string, route: string, feature: ?string, permission: ?string}>
     */
    protected function navItems(): array
    {
        $blade = File::get(resource_path('views/components/layouts/app.blade.php'));

        preg_match_all(
            "/\['label' => '([^']+)',.*?'route' => '([a-z0-9._-]+)'.*?'feature' => (null|FeatureRegistry::[A-Z_]+), 'permission' => (null|PermissionRegistry::[A-Z_]+)/",
            $blade,
            $matches,
            PREG_SET_ORDER,
        );

        $features = new ReflectionClass(FeatureRegistry::class);
        $permissions = new ReflectionClass(PermissionRegistry::class);

        return array_map(fn (array $m) => [
            'label' => $m[1],
            'route' => $m[2],
            'feature' => $m[3] === 'null' ? null : $features->getConstant(substr($m[3], 17)),
            'permission' => $m[4] === 'null' ? null : $permissions->getConstant(substr($m[4], 20)),
        ], $matches);
    }

    public function test_the_menu_was_actually_found(): void
    {
        // If the layout is restructured and the parse silently returns nothing,
        // every other test here would pass by examining an empty list.
        $items = $this->navItems();

        $this->assertGreaterThan(15, count($items), 'The nav parse found almost nothing — it has drifted from the layout.');
        $this->assertContains('Suppliers', array_column($items, 'label'));
    }

    public function test_every_menu_item_points_at_a_real_route(): void
    {
        foreach ($this->navItems() as $item) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($item['route']),
                "The menu links to {$item['route']}, which does not exist.",
            );
        }
    }

    public function test_what_the_menu_hides_is_what_the_app_refuses(): void
    {
        $permissions = PermissionRegistry::all();
        $disagreements = [];

        foreach ($this->navItems() as $item) {
            $route = Route::getRoutes()->getByName($item['route']);

            // Layer one: an explicit feature: middleware on the route.
            $enforced = null;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'feature:')) {
                    $enforced = substr($middleware, 8);
                }
            }

            // Layer two, and the one easy to miss: the permission's own anchor.
            if ($enforced === null && $item['permission'] !== null) {
                $enforced = $permissions[$item['permission']]['feature'] ?? null;
            }

            if ($item['feature'] !== $enforced) {
                $disagreements[] = sprintf(
                    '%s → menu gates on %s, the app enforces %s',
                    $item['label'],
                    $item['feature'] ?? 'nothing',
                    $enforced ?? 'nothing',
                );
            }
        }

        $this->assertSame([], $disagreements,
            "A menu that disagrees with the app is either a hidden working screen or a dead link:\n"
            .implode("\n", $disagreements));
    }

    public function test_every_feature_named_on_a_route_actually_exists(): void
    {
        $known = array_keys(FeatureRegistry::all());
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with($middleware, 'feature:')) {
                    continue;
                }

                $code = substr($middleware, 8);

                // A typo here fails CLOSED — the feature is never enabled on any
                // plan, so the screen is refused to everybody, for ever.
                if (! in_array($code, $known, true)) {
                    $unknown[] = $route->getName().' → '.$code;
                }
            }
        }

        $this->assertSame([], array_unique($unknown));
    }
}
