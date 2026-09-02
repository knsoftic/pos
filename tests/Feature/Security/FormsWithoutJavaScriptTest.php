<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A form control whose state lives only in JavaScript submits the wrong thing
 * when the JavaScript does not arrive.
 *
 * ================= WHY THIS FILE EXISTS =================
 * This is not a hypothetical. On the live site, Livewire's JS asset 404'd — and
 * Alpine ships inside Livewire here, so Alpine never loaded either. The admin
 * plan form's price checkboxes were bound with `x-model` and had no `@checked`,
 * so every one of them rendered UNCHECKED beside a hidden `enabled=0`. Saving a
 * plan turned OFF every billing cycle. The limit dropdowns had no `@selected`,
 * so a plan with a custom quota read as "Inherit" and saving kept that lie.
 *
 * Nobody saw an error. The page looked fine and quietly destroyed the pricing.
 *
 * THE RULE: the server renders the truth into the HTML; Alpine only enhances it.
 * `@checked` / `@selected` beside `x-model` is never redundant.
 */
class FormsWithoutJavaScriptTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->admin = Admin::factory()->create();
    }

    public function test_a_plans_enabled_cycles_survive_a_page_with_no_javascript(): void
    {
        $plan = Plan::factory()->monthly()->create(['name' => 'Checked Plan']);

        $html = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.plans.edit', $plan))
            ->assertOk()
            ->getContent();

        /*
         | The browser decides a checkbox from the `checked` ATTRIBUTE. If the
         | only thing setting it is x-model, a page without Alpine renders it
         | off — and the hidden `enabled=0` beside it saves that.
         */
        $this->assertStringContainsString('name="prices', $html);
        $this->assertMatchesRegularExpression(
            '/name="prices\[[^"]+\]\[enabled\]" value="1"[^>]*\bchecked\b/s',
            $html,
            'At least one enabled cycle must carry a real `checked` attribute, not just x-model.',
        );
    }

    public function test_a_limits_mode_is_marked_selected_in_the_html(): void
    {
        $plan = Plan::factory()->monthly()->create(['name' => 'Selected Plan']);

        $html = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.plans.edit', $plan))
            ->getContent();

        // Without `selected`, the browser shows the first option — "Inherit" —
        // for every limit, whatever the plan actually holds.
        $this->assertMatchesRegularExpression(
            '/<option value="(inherit|unlimited|custom)"[^>]*\bselected\b/s',
            $html,
            'The limit mode must be marked selected server-side.',
        );
    }

    public function test_no_checkbox_or_select_relies_on_alpine_alone(): void
    {
        /*
         | ⚠️ A SWEEP, NOT A SPOT CHECK. The bug appeared in one file and the
         | same shape existed in three others. Anything server-rendered that
         | carries `x-model` must also carry the server's own answer.
         |
         | Alpine-BUILT rows are exempt: their `:name` is a bound expression, so
         | without Alpine the row does not exist at all — a loud failure, not a
         | silent wrong value.
         */
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = $file->getContents();

            preg_match_all('/<input\b[^>]*x-model[^>]*>/s', $html, $inputs);

            foreach ($inputs[0] as $tag) {
                if (! preg_match('/type="(checkbox|radio)"/', $tag)) {
                    continue;
                }

                // Alpine-generated row: the name itself is a bound expression.
                if (str_contains($tag, ':name=')) {
                    continue;
                }

                if (! str_contains($tag, '@checked') && ! preg_match('/\bchecked\b/', $tag)) {
                    $offenders[] = $file->getRelativePathname().' → '.trim(preg_replace('/\s+/', ' ', $tag));
                }
            }
        }

        $this->assertSame([], $offenders,
            "These submit \"off\" on any page where Alpine fails to load:\n".implode("\n", $offenders));
    }

    public function test_a_business_override_does_not_silently_lose_unlimited(): void
    {
        $business = Business::factory()->create();
        $limit = Limit::query()->firstOrFail();

        $business->limitOverrides()->create(['limit_id' => $limit->id, 'value' => null]);

        $html = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.businesses.overrides.index', $business))
            ->assertOk()
            ->getContent();

        // `value === null` IS unlimited. Rendering that box unticked and saving
        // would quietly replace "unlimited" with "not unlimited".
        $this->assertMatchesRegularExpression(
            '/name="unlimited" value="1"[^>]*\bchecked\b/s',
            $html,
            'An unlimited override must render as ticked without any JavaScript.',
        );

        $this->assertGreaterThan(0, Feature::query()->count());
    }
}
