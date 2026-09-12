<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SyncState> */
class SyncStateFactory extends Factory
{
    protected $model = SyncState::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['resource_key' => fake()->unique()->slug(3)];
    }
}
