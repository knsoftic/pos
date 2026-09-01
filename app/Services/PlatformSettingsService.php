<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\PlatformSettingRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The operator's own settings (#110, #111, #160).
 *
 * Same shape as {@see SettingsService}, one level up: the key is the config key
 * it overrides, only changes are stored, and `apply()` overlays them onto the
 * config repository so nothing downstream has to know this exists.
 *
 * ================= WHY IT IS A MIDDLEWARE AND NOT A PROVIDER =================
 * These have to be in place before ANY page renders — the login screen reads
 * the brand name, the public site reads whether sign-up is open. That is one
 * query per request, which is why it is memoised for the life of the request
 * and why {@see ApplyPlatformSettings} runs first in the web stack rather than
 * inside a provider, where it would also fire during migrations and console
 * commands that have no table to read yet.
 *
 * ⚠️ `apply()` survives a missing table on purpose. A deployment runs the new
 * code before the new migration, and a platform whose every page 500s in that
 * window is worse than one running on its shipped defaults for a few seconds.
 */
class PlatformSettingsService
{
    /** @var array<string, mixed>|null per-request memo */
    protected ?array $memo = null;

    /**
     * The values the config FILES ship with — see SettingsService for why this
     * has to be a snapshot rather than a read of the live repository.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $shipped = null;

    public static function snapshotDefaults(): void
    {
        $shipped = [];

        foreach (PlatformSettingRegistry::keys() as $key) {
            $shipped[$key] = Config::get($key);
        }

        self::$shipped = $shipped;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->memo ??= array_merge($this->defaults(), $this->overrides());
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? Config::get($key);
    }

    public function apply(): void
    {
        foreach ($this->all() as $key => $value) {
            Config::set($key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function put(array $values): array
    {
        $clean = $this->validate($values);
        $defaults = $this->defaults();
        $adminId = Auth::guard('admin')->id();

        DB::transaction(function () use ($clean, $defaults, $adminId): void {
            foreach ($clean as $key => $value) {
                if ($this->sameAsDefault($value, $defaults[$key] ?? null)) {
                    PlatformSetting::query()->where('key', $key)->delete();

                    continue;
                }

                PlatformSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => json_encode($value), 'updated_by' => $adminId],
                );
            }
        });

        $this->flush();
        $this->apply();

        return $this->all();
    }

    public function forget(string $key): void
    {
        PlatformSetting::query()->where('key', $key)->delete();

        $this->flush();
        $this->apply();
    }

    /** @return list<string> */
    public function customised(): array
    {
        return $this->tableMissing() ? [] : PlatformSetting::query()->pluck('key')->all();
    }

    public function flush(): void
    {
        $this->memo = null;
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        if (self::$shipped === null) {
            self::snapshotDefaults();
        }

        $defaults = [];

        foreach (PlatformSettingRegistry::all() as $key => $definition) {
            $defaults[$key] = $this->cast(self::$shipped[$key] ?? null, $definition['type']);
        }

        return $defaults;
    }

    /** @return array<string, mixed> */
    protected function overrides(): array
    {
        if ($this->tableMissing()) {
            return [];
        }

        $out = [];

        foreach (PlatformSetting::query()->pluck('value', 'key') as $key => $raw) {
            if (! PlatformSettingRegistry::exists($key)) {
                continue;
            }

            $out[$key] = $this->cast(json_decode((string) $raw, true), PlatformSettingRegistry::definition($key)['type']);
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
        $flat = [];

        foreach ($values as $key => $value) {
            $definition = PlatformSettingRegistry::definition($key);

            $field = str_replace('.', '__', $key);
            $rules[$field] = $definition['rules'];
            $labels[$field] = strtolower($definition['label']);
            $flat[$field] = $this->cast($value, $definition['type']);
        }

        Validator::make($flat, $rules, [], $labels)->validate();

        $clean = [];

        foreach ($values as $key => $value) {
            $clean[$key] = $flat[str_replace('.', '__', $key)];
        }

        return $clean;
    }

    protected function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => $value === null || $value === '' ? null : (int) $value,
            'decimal' => $value === null || $value === '' ? null : (float) $value,
            default => $value === null ? null : (string) $value,
        };
    }

    protected function sameAsDefault(mixed $value, mixed $default): bool
    {
        return $value === $default || (string) $value === (string) $default;
    }

    /**
     * Before the migration has run, there is nothing to read — and a platform
     * that 500s on every page during a deploy is worse than one on defaults.
     */
    protected function tableMissing(): bool
    {
        static $exists = null;

        try {
            return ! ($exists ??= Schema::hasTable('platform_settings'));
        } catch (\Throwable) {
            return true;
        }
    }
}
