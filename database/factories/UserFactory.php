<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // status: DB default 는 'active' 이지만 factory create 시 in-memory 인스턴스에는
        // default 가 반영되지 않아 `$user->status === null` 상태가 된다. 이 인스턴스를
        // actingAs() 로 세팅하면 CheckUserStatus 미들웨어가 null !== 'active' 로 판정해
        // 403 을 반환하므로, factory 단에서 명시 지정하여 production-like 완전 상태로 생성한다.
        return [
            'uuid' => Str::orderedUuid()->toString(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_super' => false,
            'status' => UserStatus::Active->value,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
