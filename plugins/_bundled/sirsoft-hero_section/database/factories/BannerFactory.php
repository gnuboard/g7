<?php

namespace Plugins\Sirsoft\HeroSection\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Plugins\Sirsoft\HeroSection\Enums\DisplayPeriod;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;

/**
 * 배너 팩토리 — 노출 상태(노출중·예약·종료·비활성)마다 state 를 둔다.
 *
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    /**
     * 팩토리 대상 모델
     *
     * @var class-string<Banner>
     */
    protected $model = Banner::class;

    /**
     * 기본 속성을 반환합니다 — 사이트 홈, 상시 노출, 사용 중.
     *
     * @return array<string, mixed> 기본 속성
     */
    public function definition(): array
    {
        return [
            'position' => HeroPosition::HomeHero->value,
            'is_active' => true,
            'sort_order' => 0,
            'display_period' => DisplayPeriod::Always->value,
            'starts_at' => null,
            'ends_at' => null,
            'title' => ['ko' => '배너 '.$this->faker->unique()->numberBetween(1, 999999), 'en' => 'Banner'],
            'description' => null,
            'button_text' => null,
            'image_alt' => null,
            'title_visible' => true,
            'title_color' => '#FFFFFF',
            'title_align' => 'left',
            'description_visible' => true,
            'description_color' => '#E5E7EB',
            'description_align' => 'left',
            'link_url' => null,
            'link_new_window' => false,
        ];
    }

    /**
     * 사용하지 않는(비활성) 배너로 만듭니다.
     *
     * @return static 비활성 상태 팩토리
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * 상시 노출 배너로 만듭니다.
     *
     * @return static 상시 노출 팩토리
     */
    public function always(): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Always->value, 'starts_at' => null, 'ends_at' => null]);
    }

    /**
     * 지금 기간 안에서 노출 중인 기간 지정 배너로 만듭니다.
     *
     * @return static 기간 내 노출 팩토리
     */
    public function running(): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Range->value, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    }

    /**
     * 시작 전(예약) 배너로 만듭니다.
     *
     * @return static 예약 상태 팩토리
     */
    public function scheduled(): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Range->value, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    }

    /**
     * 종료된 배너로 만듭니다.
     *
     * @return static 종료 상태 팩토리
     */
    public function ended(): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Range->value, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    }

    /**
     * 노출 위치를 정합니다.
     *
     * @param  HeroPosition  $position  노출 위치
     * @return static 위치를 정한 팩토리
     */
    public function forPosition(HeroPosition $position): static
    {
        return $this->state(fn () => ['position' => $position->value]);
    }

    /**
     * PC 이미지(와 원하면 모바일 이미지)를 함께 만듭니다.
     *
     * @param  bool  $mobile  모바일 이미지도 만들지 여부
     * @return static 이미지를 붙이는 팩토리
     */
    public function withImages(bool $mobile = false): static
    {
        return $this->afterCreating(function (Banner $banner) use ($mobile) {
            BannerImage::factory()->pc()->create(['banner_id' => $banner->id]);
            if ($mobile) {
                BannerImage::factory()->mobile()->create(['banner_id' => $banner->id]);
            }
        });
    }
}
