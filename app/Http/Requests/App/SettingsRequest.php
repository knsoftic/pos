<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A settings form post (#57).
 *
 * ⚠️ Deliberately thin. The rules for a setting live in SettingRegistry and are
 * applied by SettingsService, because the same value can arrive from a form, a
 * seeder or a console command and all three must be checked the same way. A
 * second copy of the rules here would be the copy that goes out of date.
 *
 * What this class DOES do is decide which keys a post is allowed to touch: only
 * the ones the group being saved actually offers. Without that, a hand-crafted
 * post to the receipt form could rewrite the discount ceiling.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions  what this group offers
     * @return array<string, mixed>
     */
    public function settingsFor(array $definitions): array
    {
        $values = [];

        foreach ($definitions as $key => $definition) {
            $field = str_replace('.', '__', $key);

            // A checkbox that is off sends nothing, so a boolean is read from
            // presence rather than from the payload having the key.
            if ($definition['type'] === 'bool') {
                $values[$key] = $this->boolean($field);

                continue;
            }

            if (! $this->has($field)) {
                continue;
            }

            $values[$key] = $this->input($field);
        }

        return $values;
    }
}
