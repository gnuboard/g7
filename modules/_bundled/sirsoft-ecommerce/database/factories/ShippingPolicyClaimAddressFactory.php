<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;

/**
 * 배송정책 클레임 회수지 Factory
 */
class ShippingPolicyClaimAddressFactory extends Factory
{
    protected $model = ShippingPolicyClaimAddress::class;

    /**
     * 기본 정의
     *
     * @return array 기본 속성 배열
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            // ShippingPolicy 는 전용 Factory 가 없어(모듈 모델 기본 해석 불가) 최소 정책을
            // 직접 만든다. 호출부가 shipping_policy_id 를 지정하면 이 클로저는 실행되지 않는다.
            'shipping_policy_id' => fn () => ShippingPolicy::create([
                'name' => ['ko' => '클레임 회수지 테스트 정책', 'en' => 'Claim Address Test Policy'],
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 0,
            ])->id,
            'claim_type' => ClaimTypeEnum::RETURN,
            'recipient_name' => $faker->name(),
            'phone' => $faker->numerify('010-####-####'),
            'zipcode' => $faker->numerify('#####'),
            'address1' => $faker->streetAddress(),
            'address2' => $faker->randomElement(['101동 202호', '3층', 'A동 501호', '지하 1층']),
            'country_code' => 'KR',
        ];
    }

    /**
     * 교환 회수지로 설정합니다.
     *
     * @return static
     */
    public function forExchange(): static
    {
        return $this->state(fn () => ['claim_type' => ClaimTypeEnum::EXCHANGE]);
    }

    /**
     * 반품 회수지로 설정합니다.
     *
     * @return static
     */
    public function forReturn(): static
    {
        return $this->state(fn () => ['claim_type' => ClaimTypeEnum::RETURN]);
    }
}
