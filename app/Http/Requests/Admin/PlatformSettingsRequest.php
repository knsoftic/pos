<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A platform settings post (#110).
 *
 * Thin for the same reason as the tenant one: the rules live in the registry
 * and are applied by the service, so a form, a seeder and a console command are
 * all checked the same way.
 *
 * ⚠️ Only the keys the group being saved actually offers are read. Without
 * that, a post to the branding form could switch maintenance mode on.
 */
class PlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    public function settingsFor(array $definitions): array
    {
        $values = [];

        foreach ($definitions as $key => $definition) {
            $field = str_replace('.', '__', $key);

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
