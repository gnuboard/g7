<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 사용자 배송지 Factory
 */
class UserAddressFactory extends Factory
{
    protected $model = UserAddress::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->randomElement(['집', '회사', '본가', '학교']),
            'recipient_name' => $this->faker->name(),
            'recipient_phone' => '010-'.$this->faker->numerify('####-####'),
            'country_code' => 'KR',
            'zipcode' => $this->faker->numerify('#####'),
            'address' => $this->faker->address(),
            'address_detail' => $this->faker->optional()->sentence(3),
            'is_default' => false,
        ];
    }

    /**
     * 기본 배송지
     *
     * @return static
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * 특정 사용자의 배송지
     *
     * @param User $user
     * @return static
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * 해외 배송지
     *
     * @return static
     */
    public function international(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => $this->faker->randomElement(['US', 'JP', 'CN', 'GB']),
            'zipcode' => null,
            'address' => null,
            'address_detail' => null,
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->optional()->randomElement(['Apt 101', 'Suite 200', 'Unit 3B', 'Floor 5']),
            'city' => $this->faker->city(),
            'state' => $this->faker->randomElement(['CA', 'NY', 'TX', 'FL', 'WA', 'Tokyo', 'Osaka', 'Shanghai', 'London']),
            'postal_code' => $this->faker->postcode(),
        ]);
    }
}
