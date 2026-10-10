<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 첨부 이미지 Factory
 */
class ClaimImageFactory extends Factory
{
    protected $model = ClaimImage::class;

    /**
     * 기본 정의
     *
     * @return array 기본 속성 배열
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            'claimable_type' => ClaimTypeEnum::EXCHANGE->value,
            'claimable_id' => OrderExchange::factory(),
            'disk' => 'public',
            'path' => 'claims/'.$faker->uuid().'.jpg',
            'original_name' => $faker->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => $faker->numberBetween(10000, 2000000),
            'width' => 1200,
            'height' => 800,
            'sort_order' => 0,
            'created_by' => null,
            // 기본값은 `unknown` 이다 — 주체를 단정할 근거가 없는 픽스처가 "고객 증빙" 으로
            // 보이면 그 표기를 믿는 테스트가 생긴다. 주체가 필요하면 state 로 명시한다.
            'uploader_type' => ClaimImageUploaderTypeEnum::UNKNOWN->value,
        ];
    }

    /**
     * 고객이 올린 첨부로 설정합니다.
     *
     * @return static
     */
    public function byCustomer(): static
    {
        return $this->state(fn () => ['uploader_type' => ClaimImageUploaderTypeEnum::CUSTOMER->value]);
    }

    /**
     * 운영자가 대리로 올린 첨부로 설정합니다.
     *
     * @return static
     */
    public function byAdmin(): static
    {
        return $this->state(fn () => ['uploader_type' => ClaimImageUploaderTypeEnum::ADMIN->value]);
    }

    /**
     * 교환 신청서 첨부로 설정합니다.
     *
     * @param  OrderExchange|int|null  $exchange  교환 신청서 (미지정 시 신규 생성)
     * @return static
     */
    public function forExchange(OrderExchange|int|null $exchange = null): static
    {
        return $this->state(fn () => [
            'claimable_type' => ClaimTypeEnum::EXCHANGE->value,
            'claimable_id' => $exchange instanceof OrderExchange
                ? $exchange->id
                : ($exchange ?? OrderExchange::factory()),
        ]);
    }

    /**
     * 반품 신청서 첨부로 설정합니다.
     *
     * @param  OrderReturn|int|null  $return  반품 신청서 (미지정 시 신규 생성)
     * @return static
     */
    public function forReturn(OrderReturn|int|null $return = null): static
    {
        return $this->state(fn () => [
            'claimable_type' => ClaimTypeEnum::RETURN->value,
            'claimable_id' => $return instanceof OrderReturn
                ? $return->id
                : ($return ?? OrderReturn::factory()),
        ]);
    }
}
