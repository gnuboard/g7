<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

/**
 * 주문 주소 Factory
 */
class OrderAddressFactory extends Factory
{
    protected $model = OrderAddress::class;

    /**
     * 기본 정의
     */
    public function definition(): array
    {
        $faker = \fake();

        // 배송 메모: 70% 프리셋 키, 20% 자유 텍스트(custom), 10% 미입력 — 실제 주문 분포 재현.
        // delivery_memo_label 은 DeliveryMemoPresetEnum::resolveLabel SSoT 로 산출(프리셋=라벨, custom=원문).
        $memo = $this->generateDeliveryMemo($faker);

        // 주문자 선호 언어 스냅샷 (비회원 알림 발송 언어 결정용) — 지원 로케일 중 랜덤
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);

        return [
            'order_id' => Order::factory(),
            'address_type' => 'shipping',
            'orderer_name' => $faker->name(),
            'orderer_phone' => '010-'.$faker->numerify('####-####'),
            'orderer_email' => $faker->email(),
            'orderer_locale' => $supportedLocales[array_rand($supportedLocales)],
            'recipient_name' => $faker->name(),
            'recipient_phone' => '010-'.$faker->numerify('####-####'),
            'zipcode' => $faker->numerify('#####'),
            'address' => $faker->address(),
            'address_detail' => $faker->optional()->sentence(3),
            'delivery_memo' => $memo,
            'delivery_memo_label' => DeliveryMemoPresetEnum::resolveLabel($memo),
            'recipient_country_code' => 'KR',
        ];
    }

    /**
     * 배송 메모를 생성합니다 (프리셋 키 / 자유 텍스트 / 미입력).
     *
     * @param  Generator  $faker
     * @return string|null 프리셋 키, 자유 텍스트, 또는 null
     */
    private function generateDeliveryMemo($faker): ?string
    {
        $roll = $faker->numberBetween(1, 100);

        if ($roll <= 70) {
            // 프리셋 키 (door/security/parcel_box/call)
            $presets = DeliveryMemoPresetEnum::values();

            return $presets[array_rand($presets)];
        }

        if ($roll <= 90) {
            // 자유 텍스트 (custom)
            return $faker->sentence();
        }

        // 미입력
        return null;
    }

    /**
     * 배송지 주소
     */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_type' => 'shipping',
        ]);
    }

    /**
     * 청구지 주소
     */
    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_type' => 'billing',
        ]);
    }

    /**
     * 특정 주문의 주소
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
        ]);
    }
}
