<?php

namespace Database\Factories;

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangelogEntry>
 */
class ChangelogEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'category' => fake()->randomElement(['operations', 'imports', 'catalog', 'pos', 'pricing', 'stability', 'general']),
            'version' => null,
            'status' => ChangelogEntry::STATUS_DRAFT,
            'published_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChangelogEntry::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
