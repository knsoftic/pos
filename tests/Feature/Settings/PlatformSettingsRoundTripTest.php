<?php

namespace Tests\Feature\Settings;

use App\Models\Admin;
use App\Services\PlatformSettingsService;
use App\Support\PlatformSettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every knob on /admin/settings, saved and read back — all four tabs.
 *
 * "Settings thik se kaam nahi kar rahi" is a report that names no field, so
 * this names all of them: for each group it loads the page, changes every knob
 * to something new, saves, and looks for that value in three places —
 *
 *   1. the database row,
 *   2. the config repository the rest of the app reads,
 *   3. the form when it is loaded again.
 *
 * ⚠️ (2) is the one that matters and the one a manual click-through misses. A
 * setting is stored under the config key it OVERRIDES, and the overlay is put
 * in place by middleware on each request. Store it without applying it and the
 * screen says "saved", the row is right, and nothing in the application
 * changes — which is exactly what a broken settings page feels like.
 */
class PlatformSettingsRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = Admin::factory()->create();
    }

    public static function settingGroups(): array
    {
        return [
            'branding' => ['branding'],
            'signup' => ['signup'],
            'billing' => ['billing'],
            'maintenance' => ['maintenance'],
        ];
    }

    // ═══════════════════════════════════════════════════════ every tab opens

    /** @dataProvider settingGroups */
    public function test_the_tab_opens_and_shows_a_control_for_every_knob(string $group): void
    {
        $page = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', $group))
            ->assertOk();

        foreach ($this->visible($group) as $key => $definition) {
            // The form names fields after the config key with dots swapped,
            // because a dot in a field name is an array in PHP.
            $page->assertSee('name="'.str_replace('.', '__', $key).'"', escape: false);
            $page->assertSee($definition['label']);
        }
    }

    // ═══════════════════════════════════════════════ save it, then find it

    /** @dataProvider settingGroups */
    public function test_every_knob_survives_a_save(string $group): void
    {
        $definitions = $this->visible($group);
        $this->assertNotEmpty($definitions, "Group \"{$group}\" has nothing on it.");

        $payload = [];
        $expected = [];

        foreach ($definitions as $key => $definition) {
            $value = $this->somethingDifferent($key, $definition);
            $payload[str_replace('.', '__', $key)] = $value;
            $expected[$key] = $value;
        }

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.update', $group), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 1. stored
        $platform = app(PlatformSettingsService::class);
        $platform->flush();

        foreach ($expected as $key => $value) {
            $this->assertSame(
                $this->comparable($value),
                $this->comparable($platform->all()[$key] ?? null),
                "\"{$key}\" did not survive the save.",
            );
        }

        // 2. applied to config — what the REST of the application reads
        $platform->apply();

        foreach ($expected as $key => $value) {
            $this->assertSame(
                $this->comparable($value),
                $this->comparable(config($key)),
                "\"{$key}\" was stored but never reached config, so nothing downstream sees it.",
            );
        }

        // 3. shown again on the form
        $page = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', $group))
            ->assertOk();

        foreach ($definitions as $key => $definition) {
            if ($definition['type'] === 'bool') {
                continue;
            }

            $page->assertSee((string) $expected[$key], escape: false);
        }
    }

    // ═══════════════════════════════════════════════════ the awkward values

    public function test_switching_a_toggle_off_actually_switches_it_off(): void
    {
        // ⚠️ The classic. An unticked checkbox posts NOTHING, so a form that
        // reads only what arrived can never turn anything off — it reads the
        // absence as "not mentioned" and leaves the old value alone. Turning
        // maintenance ON and being unable to turn it OFF would close every shop
        // on the platform with no way back but a database edit.
        $this->save('maintenance', ['platform__maintenance' => '1']);
        $this->assertTrue((bool) app(PlatformSettingsService::class)->all()['platform.maintenance']);

        $this->save('maintenance', []);   // nothing posted at all — box unticked

        app(PlatformSettingsService::class)->flush();
        $this->assertFalse((bool) app(PlatformSettingsService::class)->all()['platform.maintenance']);
    }

    public function test_a_value_equal_to_the_shipped_default_stores_no_row(): void
    {
        $default = config('subscription.trial_days');

        $this->save('billing', ['subscription__trial_days' => (string) $default] + $this->keep('billing'));

        // Only differences are stored — otherwise "changed" badges would appear
        // on knobs nobody touched, and a later change to a shipped default
        // would be silently overridden by a row that agrees with the old one.
        $this->assertDatabaseMissing('platform_settings', ['key' => 'subscription.trial_days']);
    }

    public function test_clearing_an_optional_text_field_is_allowed_and_sticks(): void
    {
        // A support phone is optional. Typing one in and then wanting it gone
        // is ordinary, and an empty field that silently keeps the old value is
        // a settings page nobody can trust.
        $this->save('branding', $this->keep('branding', ['brand__support_phone' => '0300-1234567']));
        $this->assertSame('0300-1234567', app(PlatformSettingsService::class)->get('brand.support_phone'));

        $this->save('branding', $this->keep('branding', ['brand__support_phone' => '']));

        app(PlatformSettingsService::class)->flush();
        $this->assertSame('', (string) app(PlatformSettingsService::class)->get('brand.support_phone'));
    }

    public function test_back_to_defaults_puts_the_whole_tab_back(): void
    {
        $before = config('brand.name');

        $this->save('branding', $this->keep('branding', ['brand__name' => 'Someone Else Ltd']));
        $this->assertSame('Someone Else Ltd', app(PlatformSettingsService::class)->get('brand.name'));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.settings.reset', 'branding'))
            ->assertRedirect();

        app(PlatformSettingsService::class)->flush();
        $this->assertSame($before, app(PlatformSettingsService::class)->get('brand.name'));
        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_one_tab_cannot_write_another_tabs_settings(): void
    {
        // ⚠️ Posting to the branding form must not be able to close the
        // platform. The group decides which keys are read, not the payload.
        $this->save('branding', $this->keep('branding', ['platform__maintenance' => '1']));

        $this->assertFalse((bool) app(PlatformSettingsService::class)->all()['platform.maintenance']);
        $this->assertDatabaseMissing('platform_settings', ['key' => 'platform.maintenance']);
    }

    public function test_a_bad_value_is_refused_with_the_field_named(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.update', 'billing'), $this->keep('billing', [
                'subscription__currency' => 'RUPEES',   // must be 3 letters
            ]))
            ->assertSessionHasErrors('subscription__currency');

        $this->assertDatabaseMissing('platform_settings', ['key' => 'subscription.currency']);
    }

    public function test_an_unknown_tab_is_a_404_not_a_blank_page(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', 'nonsense'))
            ->assertNotFound();
    }

    public function test_a_shopkeeper_cannot_reach_the_operators_settings(): void
    {
        $this->get(route('admin.settings.show', 'billing'))->assertRedirect();
    }

    // ══════════════════════════════════ maintenance, through the real screen

    public function test_ticking_the_box_on_the_screen_really_closes_the_site(): void
    {
        /*
         | ⚠️ The other maintenance tests call the SERVICE. This one clicks the
         | SCREEN, which is a different journey with more places to come apart:
         | the form field is named platform__maintenance, the request has to map
         | it back to a config key, the value has to reach the database, the
         | overlay middleware has to put it on config, and only then does the
         | maintenance middleware get to see it.
         |
         | "Maintenance on karne se site maintenance mein nahi jati" is a report
         | about that whole journey, not about the switch.
         */
        $this->save('maintenance', ['platform__maintenance' => '1']);

        // A shopkeeper, not signed in, on a fresh request.
        $this->get(route('home'))->assertStatus(503);
        $this->get(route('pricing'))->assertStatus(503);
        $this->get(route('login'))->assertStatus(503);
    }

    public function test_unticking_it_opens_the_site_again(): void
    {
        $this->save('maintenance', ['platform__maintenance' => '1']);
        $this->get(route('home'))->assertStatus(503);

        // An unticked box posts nothing at all.
        $this->save('maintenance', []);

        $this->get(route('home'))->assertOk();
    }

    public function test_the_operator_never_sees_their_own_maintenance_page(): void
    {
        /*
         | ⚠️ THE MOST LIKELY REASON SOMEBODY REPORTS THIS AS BROKEN.
         |
         | A signed-in operator passes straight through, by design — otherwise
         | you could not check that a shop works before letting everyone back
         | in. So the person who just ticked the box is the one person on the
         | platform who cannot see its effect, and the site looks unchanged to
         | exactly the eyes that went looking.
         |
         | The way to check is a private window, or any browser not signed in
         | to /admin.
         */
        $this->save('maintenance', ['platform__maintenance' => '1']);

        $this->actingAs($this->admin, 'admin')->get(route('home'))->assertOk();

        // Take the operator's hat off before asking again -- otherwise the
        // second request is the same person and answers the same way.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // The very same URL, to somebody who is not the operator.
        $this->get(route('home'))->assertStatus(503);
    }

    public function test_the_banner_warns_that_the_operator_cannot_see_it(): void
    {
        // The fix for the report, as opposed to the fix for a bug: the switch
        // worked all along, the screen just never said who it works on.
        $this->save('maintenance', ['platform__maintenance' => '1']);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', 'maintenance'))
            ->assertOk()
            ->assertSee('Maintenance mode is ON.')
            ->assertSee('You will not see it yourself')
            ->assertSee('private window');
    }

    public function test_the_banner_hands_over_the_preview_token_when_there_is_one(): void
    {
        $this->save('maintenance', [
            'platform__maintenance' => '1',
            'platform__maintenance_token' => 'deploy-123',
        ]);

        // Copying it out of the field below and pasting it into a URL is a step
        // that invites a typo, and a mistyped token is indistinguishable from a
        // working maintenance page.
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', 'maintenance'))
            ->assertOk()
            ->assertSee('?maintenance_token=deploy-123');
    }

    // ═══════════════════════════════════════════════════════════ helpers

    /** @return array<string, array<string, mixed>> */
    protected function visible(string $group): array
    {
        // The logo is a file, drawn by its own control on the branding tab.
        return array_filter(
            PlatformSettingRegistry::group($group),
            fn (array $d) => ! ($d['hidden'] ?? false),
        );
    }

    /**
     * Post to a group as the operator, then stop being the operator.
     *
     * ⚠️ forgetGuards() is the point of this helper. actingAs() keeps the
     * admin signed in for every later request in the test, and a signed-in
     * admin walks straight through maintenance mode by design — so without
     * this, a maintenance test would assert 200 and "prove" a bug that is
     * really the test still wearing the operator's hat.
     *
     * The real world does the same thing: the operator ticks the box in their
     * browser, and the shopkeeper arrives in a different one.
     */
    protected function save(string $group, array $payload): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.update', $group), $payload)
            ->assertSessionHasNoErrors();

        app(PlatformSettingsService::class)->flush();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * The group's current values as form fields, so a test can change one knob
     * without blanking every other required one on the same tab.
     */
    protected function keep(string $group, array $overrides = []): array
    {
        $platform = app(PlatformSettingsService::class);
        $payload = [];

        foreach ($this->visible($group) as $key => $definition) {
            $payload[str_replace('.', '__', $key)] = $definition['type'] === 'bool'
                ? ($platform->get($key) ? '1' : '0')
                : (string) $platform->get($key);
        }

        return array_merge($payload, $overrides);
    }

    /**
     * A valid value that is not the current one, shaped for this knob.
     *
     * ⚠️ It has to READ the rules, not guess from the type. A currency symbol
     * caps at 8 characters, decimal places at 4, and the preview token only
     * accepts letters, digits, dash and underscore because it travels in a
     * query string. A generator that ignored all that would fail on the rules
     * rather than on the thing being tested, and the failure would look like a
     * bug in the settings screen.
     */
    protected function somethingDifferent(string $key, array $definition): string
    {
        $rules = implode('|', $definition['rules']);
        $max = preg_match('/(?:^|\|)max:(\d+)/', $rules, $m) ? (int) $m[1] : null;

        // A regex rule means the value is going somewhere fussy — a URL, a
        // query string — so keep to the safest alphabet there is.
        $restricted = str_contains($rules, 'regex:') || str_contains($rules, 'alpha_dash');

        $value = match (true) {
            $definition['type'] === 'bool' => '1',
            str_contains($rules, 'size:3') => 'PKR',
            str_contains($rules, 'email') => 'help@example.test',
            str_contains($rules, 'url') => 'https://example.test',
            $definition['type'] === 'int', $definition['type'] === 'decimal' => (string) min(7, $max ?? 7),
            $restricted => 'round-trip-'.substr(md5($key), 0, 6),
            default => 'Round Trip '.substr(md5($key), 0, 6),
        };

        return $max !== null && in_array($definition['type'], ['string', 'text'], true)
            ? substr($value, 0, $max)
            : $value;
    }

    /** Compare as strings: '1' and true mean the same thing on a form. */
    protected function comparable(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
