<?php

namespace Plugins\Sirsoft\Popup\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Plugins\Sirsoft\Popup\Enums\Audience;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Enums\DisplayPeriod;
use Plugins\Sirsoft\Popup\Enums\PageScope;
use Plugins\Sirsoft\Popup\Enums\PopupDevice;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 팝업 팩토리 — 기본은 사용 중·상시·전체 페이지·전체 대상·전체 기기 이미지형.
 *
 * @extends Factory<Popup>
 */
class PopupFactory extends Factory
{
    /**
     * 팩토리 대상 모델
     *
     * @var class-string<Popup>
     */
    protected $model = Popup::class;

    /**
     * 기본 속성을 반환합니다.
     *
     * @return array<string, mixed> 기본 속성
     */
    public function definition(): array
    {
        return [
            'title' => '팝업 '.$this->faker->unique()->numberBetween(1, 999999),
            'is_active' => true,
            'sort_order' => 0,
            'content_type' => ContentType::Image->value,
            'display_period' => DisplayPeriod::Always->value,
            'starts_at' => null,
            'ends_at' => null,
            'page_scope' => PageScope::All->value,
            'routes' => null,
            'audience' => Audience::All->value,
            'device' => PopupDevice::All->value,
            'image_alt' => ['ko' => '팝업 이미지'],
            'link_url' => null,
            'link_new_window' => false,
            'body_html' => null,
        ];
    }

    /**
     * 사용하지 않는 팝업으로 만듭니다.
     *
     * @return static 비활성 팩토리
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * 상시 노출로 만듭니다.
     *
     * @return static 상시 팩토리
     */
    public function always(): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Always->value, 'starts_at' => null, 'ends_at' => null]);
    }

    /**
     * 시작 전(예약) 기간으로 만듭니다.
     *
     * @return static 예약 팩토리
     */
    public function scheduled(): static
    {
        return $this->period(now()->addDay(), now()->addDays(5));
    }

    /**
     * 끝난 기간으로 만듭니다.
     *
     * @return static 종료 팩토리
     */
    public function ended(): static
    {
        return $this->period(now()->subDays(5), now()->subDay());
    }

    /**
     * 지금 노출 중인 기간으로 만듭니다.
     *
     * @return static 노출 중 팩토리
     */
    public function running(): static
    {
        return $this->period(now()->subDay(), now()->addDay());
    }

    /**
     * 기간 지정으로 만듭니다.
     *
     * @param  \DateTimeInterface  $startsAt  시작 (UTC)
     * @param  \DateTimeInterface  $endsAt  종료 (UTC)
     * @return static 기간 팩토리
     */
    public function period(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(fn () => ['display_period' => DisplayPeriod::Range->value, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    /**
     * 직접 작성형으로 만듭니다.
     *
     * @param  array<string, string>  $bodies  언어 => 본문 HTML
     * @return static 직접 작성형 팩토리
     */
    public function html(array $bodies = ['ko' => '<p>공지</p>']): static
    {
        return $this->state(fn () => ['content_type' => ContentType::Html->value, 'body_html' => $bodies, 'image_alt' => null, 'link_url' => null]);
    }

    /**
     * 라우트 지정으로 만듭니다.
     *
     * @param  array<int, string>  $routes  정규화된 경로 목록
     * @return static 라우트 지정 팩토리
     */
    public function routes(array $routes): static
    {
        return $this->state(fn () => ['page_scope' => PageScope::Routes->value, 'routes' => $routes]);
    }

    /**
     * 노출 대상을 정합니다.
     *
     * @param  string  $audience  all, guest, member
     * @return static 대상 팩토리
     */
    public function audience(string $audience): static
    {
        return $this->state(fn () => ['audience' => $audience]);
    }

    /**
     * 노출 기기를 정합니다.
     *
     * @param  string  $device  all, pc, mobile
     * @return static 기기 팩토리
     */
    public function device(string $device): static
    {
        return $this->state(fn () => ['device' => $device]);
    }

    /**
     * 이미지 대체 텍스트를 정합니다.
     *
     * @param  array<string, string>  $alts  언어 => alt
     * @return static alt 팩토리
     */
    public function withAlts(array $alts): static
    {
        return $this->state(fn () => ['image_alt' => $alts]);
    }

    /**
     * PC 이미지(원하면 모바일도) 행을 붙입니다 — 파일은 만들지 않는다.
     *
     * @param  bool  $mobile  모바일 이미지도 붙일지
     * @return static 이미지 포함 팩토리
     */
    public function withImages(bool $mobile = false): static
    {
        return $this->afterCreating(function (Popup $popup) use ($mobile) {
            PopupImage::factory()->pc()->create(['popup_id' => $popup->id]);
            if ($mobile) {
                PopupImage::factory()->mobile()->create(['popup_id' => $popup->id]);
            }
        });
    }
}
