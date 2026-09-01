<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Setting;
use App\Support\SettingRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * What a shop has changed, and making the rest of the system see it (#57, #190).
 *
 * ================= THE OVERLAY IS THE WHOLE TRICK =================
 * `apply()` writes the business's overrides straight into Laravel's config
 * repository at the start of the request. From that moment `config('pos.cash_rounding')`
 * returns the shop's value, and the sale engine, the till, the receipt and the
 * reports all follow it without a single call site changing.
 *
 * The alternative — a `setting()` helper called everywhere — would have meant
 * editing every file that reads a knob, and would have left two ways to ask the
 * same question with no rule about which one wins. There would then be a bug
 * for every place somebody forgot to switch.
 *
 * ⚠️ Config is process-wide, so `apply()` must run per request (it is called
 * from SetBusinessTenant, right after the tenant is resolved) and
 * {@see TenantContext::runFor} re-applies it when code crosses into another
 * business. Anything running outside a request — a queue job, a console
 * command — has to apply it deliberately.
 *
 * ================= ONLY OVERRIDES ARE STORED =================
 * An untouched setting has no row and keeps following `config/`. That is what
 * lets a better default ship to every shop that never changed it, and it makes
 * "what has this shop customised?" answerable by looking at the table.
 */
class SettingsService
{
    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    /**
     * The values the config FILES ship with, captured before any shop's
     * overrides are overlaid onto them.
     *
     * ⚠️ This has to be a snapshot. `apply()` writes a business's settings into
     * the config repository, so by the time a settings form is submitted,
     * `config('format.decimals')` is that shop's answer and not the default any
     * more. Reading "the default" from the live repository would mean a shop
     * could never get back to standard: setting a value equal to the shipped
     * one would look like a change, keep its row, and the next tenant in the
     * same process would inherit it.
     *
     * Taken once at boot (see AppServiceProvider), before any request has had a
     * tenant.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $shipped = null;

    public function __construct(protected TenantContext $tenant) {}

    /**
     * Capture the shipped defaults. Called from the service provider, before
     * any middleware has had the chance to overlay a business.
     */
    public static function snapshotDefaults(): void
    {
        $shipped = [];

        foreach (SettingRegistry::keys() as $key) {
            $shipped[$key] = Config::get($key);
        }

        self::$shipped = $shipped;
    }

    /** For tests that need to prove the overlay does not leak between tenants. */
    public static function forgetSnapshot(): void
    {
        self::$shipped = null;
    }

    /**
     * Every setting, resolved: the shop's value where it has one, the shipped
     * default where it has not.
     *
     * @return array<string, mixed>
     */
    public function all(int|Business|null $business = null): array
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return $this->defaults();
        }

        if (array_key_exists($businessId, $this->cache)) {
            return $this->cache[$businessId];
        }

        /*
        | Cached across requests, not just memoised within one (#96, #168).
        |
        | `apply()` runs on EVERY tenant request, so without this the overlay
        | costs a query per page for a table that changes perhaps twice a year.
        |
        | ⚠️ Unlike an entitlement, a stale SETTING is a display bug rather than
        | a security one — the worst case is a receipt footer being a minute out
        | of date. It is still flushed on every write (see `put()` and
        | `forget()`), because "a minute" is not a promise anybody should have to
        | explain to a shopkeeper who just pressed Save.
        */
        $ttl = (int) config('subscription.cache_ttl');

        $resolved = $ttl > 0
            ? Cache::remember($this->cacheKey($businessId), $ttl, fn () => array_merge(
                $this->defaults(),
                $this->overridesFor($businessId),
            ))
            : array_merge($this->defaults(), $this->overridesFor($businessId));

        return $this->cache[$businessId] = $resolved;
    }

    public function get(string $key, int|Business|null $business = null): mixed
    {
        return $this->all($business)[$key] ?? Config::get($key);
    }

    /**
     * Overlay this business's settings onto the config repository.
     *
     * Called once per request. Everything downstream reads config as it always
     * has and never learns that a settings table exists.
     */
    public function apply(int|Business|null $business = null): void
    {
        foreach ($this->all($business) as $key => $value) {
            Config::set($key, $value);
        }
    }

    /**
     * Write a group of settings.
     *
     * Validated against the registry BEFORE anything is stored: these values
     * are read later by the sale engine and the receipt, so a bad one surfaces
     * as a broken checkout far away from the screen that caused it.
     *
     * A value equal to the shipped default deletes its row rather than storing
     * a copy — see the migration for why that matters.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the resolved settings after the write
     */
    public function put(array $values, int|Business|null $business = null): array
    {
        $businessId = $this->resolveBusinessId($business);

        abort_if($businessId === null, 422, 'Settings need a business.');

        $clean = $this->validate($values);
        $defaults = $this->defaults();
        $userId = Auth::guard('web')->id();

        DB::transaction(function () use ($clean, $defaults, $businessId, $userId): void {
            foreach ($clean as $key => $value) {
                if ($this->sameAsDefault($value, $defaults[$key] ?? null)) {
                    Setting::query()->allTenants()
                        ->where('business_id', $businessId)
                        ->where('key', $key)
                        ->delete();

                    continue;
                }

                Setting::query()->allTenants()->updateOrCreate(
                    ['business_id' => $businessId, 'key' => $key],
                    ['value' => json_encode($value), 'updated_by' => $userId],
                );
            }
        });

        $this->flush($businessId);
        $this->apply($businessId);

        return $this->all($businessId);
    }

    /** Put one setting back to the shipped default. */
    public function forget(string $key, int|Business|null $business = null): void
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return;
        }

        Setting::query()->allTenants()
            ->where('business_id', $businessId)
            ->where('key', $key)
            ->delete();

        $this->flush($businessId);

        // ⚠️ Re-overlay, exactly as `put()` does. Dropping the row without this
        // leaves the live config still holding the value that was just removed,
        // so anything reading a knob later in the SAME request would act on a
        // setting the shop has already reset.
        $this->apply($businessId);
    }

    /**
     * Which keys this shop has actually changed — for the "reset" affordance
     * and for support, who always want to know what is not standard.
     *
     * @return list<string>
     */
    public function customised(int|Business|null $business = null): array
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return [];
        }

        return Setting::query()->allTenants()
            ->where('business_id', $businessId)
            ->pluck('key')
            ->all();
    }

    /**
     * Drop the resolved map.
     *
     * MUST be called after any settings write — the overlay is what every other
     * service reads, so a stale entry here is a shop operating on a value it
     * has already changed.
     */
    public function flush(int|Business|null $business = null): void
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$businessId]);
        Cache::forget($this->cacheKey($businessId));
    }

    /**
     * The cache key.
     *
     * ⚠️ Versioned by the REGISTRY, not just the business. Shipping a release
     * that adds or renames a setting would otherwise leave every tenant reading
     * a cached map that predates it, with the new key simply missing — and the
     * bug would look like "the new setting does nothing" for as long as the TTL
     * lasts.
     */
    protected function cacheKey(int $businessId): string
    {
        return sprintf('settings.%d.%s', $businessId, substr(md5(implode('|', SettingRegistry::keys())), 0, 8));
    }

    // ------------------------------------------------------------- internals

    /**
     * The shipped defaults — from the snapshot, never from the live repository.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        if (self::$shipped === null) {
            self::snapshotDefaults();
        }

        $defaults = [];

        foreach (SettingRegistry::all() as $key => $definition) {
            $defaults[$key] = $this->cast(self::$shipped[$key] ?? null, $definition['type']);
        }

        return $defaults;
    }

    /** @return array<string, mixed> */
    protected function overridesFor(int $businessId): array
    {
        $rows = Setting::query()->allTenants()
            ->where('business_id', $businessId)
            ->pluck('value', 'key');

        $out = [];

        foreach ($rows as $key => $raw) {
            // A setting that has been retired from the registry is ignored
            // rather than pushed into config, so removing one is safe even
            // while old rows are still in the table.
            if (! SettingRegistry::exists($key)) {
                continue;
            }

            $out[$key] = $this->cast(json_decode((string) $raw, true), SettingRegistry::definition($key)['type']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function validate(array $values): array
    {
        $rules = [];
        $labels = [];
        $known = [];

        foreach ($values as $key => $value) {
            // Silently ignoring an unknown key would let a hand-crafted form
            // post write rows nothing ever reads.
            $definition = SettingRegistry::definition($key);

            $field = $this->field($key);
            $rules[$field] = $definition['rules'];
            $labels[$field] = strtolower($definition['label']);
            $known[$field] = $this->cast($value, $definition['type']);

            if ($definition['type'] === 'list') {
                $rules[$field.'.*'] = ['string', 'max:40', 'regex:/^[a-z0-9_\-]+$/'];
                $labels[$field.'.*'] = 'entry';
            }
        }

        Validator::make($known, $rules, [], $labels)->validate();

        $clean = [];

        foreach ($values as $key => $value) {
            $clean[$key] = $known[$this->field($key)];
        }

        return $clean;
    }

    /**
     * Dots are Laravel's nesting operator, so a validator asked about
     * `pos.invoice.prefix` would go looking for a nested array. Flattened for
     * validation and put back afterwards.
     */
    protected function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    protected function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => $value === null || $value === '' ? null : (int) $value,
            'decimal' => $value === null || $value === '' ? null : (float) $value,
            'list' => $this->castList($value),
            default => $value === null ? null : (string) $value,
        };
    }

    /** @return list<string> */
    protected function castList(mixed $value): array
    {
        // The form sends one entry a line; everything else sends an array.
        $items = is_array($value)
            ? $value
            : (preg_split('/[\r\n,]+/', (string) $value) ?: []);

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => strtolower(trim((string) $item)),
            $items,
        ), fn ($item) => $item !== '')));
    }

    protected function sameAsDefault(mixed $value, mixed $default): bool
    {
        if (is_array($value) || is_array($default)) {
            return json_encode($value) === json_encode($default);
        }

        // Loose on purpose for numbers: 0 typed into a form arrives as "0" and
        // is the same setting as the config's 0.
        return $value === $default || (string) $value === (string) $default;
    }

    protected function resolveBusinessId(int|Business|null $business): ?int
    {
        if ($business instanceof Business) {
            return $business->id;
        }

        return $business ?? $this->tenant->businessId();
    }
}
