<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    /**
     * Grant permission codes straight from the factory, so a test can set up a
     * role in one line. Codes are written as given — the factory is not the
     * place to second-guess what a test is arranging.
     *
     * @param  list<string>  $codes
     */
    public function withPermissions(array $codes): static
    {
        return $this->afterCreating(function (Role $role) use ($codes): void {
            foreach (array_unique($codes) as $code) {
                RolePermission::create(['role_id' => $role->id, 'permission' => $code]);
            }

            $role->unsetRelation('permissions');
        });
    }
}
