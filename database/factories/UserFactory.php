<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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

    /**
     * 모든 프로필 필드가 채워진 완전한 상태를 만듭니다.
     *
     * API 문서 실측 시 응답 필드의 예시값이 null 이 되지 않도록, 모델 로직상
     * 유효한 값으로 nullable 프로필 컬럼을 전수 채웁니다.
     * (language=지원 로케일, country=ISO alpha-2, status=UserStatus enum 등)
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'nickname' => fake()->userName(),
            'language' => 'ko',
            'timezone' => 'Asia/Seoul',
            'country' => 'KR',
            'homepage' => 'https://example.com',
            'mobile' => '010-'.fake()->numerify('####-####'),
            'phone' => '02-'.fake()->numerify('###-####'),
            'zipcode' => fake()->numerify('#####'),
            'address' => fake()->address(),
            'address_detail' => fake()->numerify('##동 ###호'),
            'signature' => fake()->sentence(),
            'bio' => fake()->paragraph(),
            // avatar 는 users 테이블 컬럼이 아니라 avatarAttachment 관계에서 파생되는
            // accessor(getAvatarUrl) 이므로 factory 에서 직접 세팅하지 않는다.
            'admin_memo' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
            'last_login_at' => now()->subDays(1),
            'identity_verified_at' => now()->subDays(5),
            'mobile_verified_at' => now()->subDays(5),
            'failed_login_attempts' => 0,
        ]);
    }
}
