<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Slug;
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
        /*
         | Faker's longest job titles run to nearly sixty characters, and both
         | `roles.name` and `roles.slug` stop at sixty — so an unbounded name
         | here failed the suite roughly one run in three with "Data too long",
         | which reads as flakiness and is not. Same off-by-a-suffix that
         | {@see \App\Support\Slug} fixes in the services.
         */
        $name = Str::limit(fake()->unique()->jobTitle(), 50, '');

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Slug::base($name, 60, 'role').'-'.Str::lower(Str::random(4)),
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
