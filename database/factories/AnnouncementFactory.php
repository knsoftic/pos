<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'title' => 'Scheduled maintenance',
            'body' => 'We will be carrying out maintenance on Sunday night.',
            'level' => 'info',
            'is_active' => true,
            'is_dismissible' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => ['starts_at' => now()->addDays(3)]);
    }
}
