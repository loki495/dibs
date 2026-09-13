<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TodoCaptureRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TodoCaptureRequest> */
class TodoCaptureRequestFactory extends Factory
{
    protected $model = TodoCaptureRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'raw_text' => fake()->sentence(8),
            'status' => TodoCaptureRequest::STATUS_PENDING,
        ];
    }
}
