<?php

namespace Plugins\Sirsoft\HeroSection\Http\Resources;

use App\Helpers\TimezoneHelper;
use App\Http\Resources\BaseApiResource;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Plugins\Sirsoft\HeroSection\Enums\BannerMediaType;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 배너 리소스 — 상세(수정 화면)와 목록 행 두 모양을 낸다.
 *
 * 기간은 수정 화면 입력용(`*_local`, 사이트 시간대 datetime-local)과 표시용(`*_display`)을 함께 싣는다.
 * 작성자 id 는 싣지 않는다.
 *
 * 위치·대상 상태를 함께 싣는다 — `position_registered`(위치가 지금 등록돼 있는지), `target_key`·`target_label`·`target_exists`
 * (동적 위치의 대상과 그 이름, 지워진 「없는 대상」이면 false). 목록 행의 대상 이름은 컬렉션이 한 번에 풀어 넘긴다.
 *
 * @mixin Banner
 */
class BannerResource extends BaseApiResource
{
    /**
     * 컬렉션이 미리 푼 대상 이름 (대상 키 => 이름, null 이면 이 리소스가 직접 푼다)
     *
     * @var array<string, string>|null
     */
    private ?array $targetLabels = null;

    /**
     * 미리 푼 대상 이름을 넘깁니다(목록·미리보기·순서 창 행마다 대상을 묻지 않게 — `TargetLabels::forBanners`).
     *
     * @param  array<string, string>  $labels  대상 키 => 이름 (이 배너 위치의 해석 결과)
     * @return static 자기 자신
     */
    public function withTargetLabels(array $labels): static
    {
        $this->targetLabels = $labels;

        return $this;
    }

    /**
     * 상세 응답을 반환합니다(다국어 원본·이미지 2종·abilities).
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 상세 배너
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->baseFields(),
            'eyebrow' => $this->eyebrow,
            'eyebrow_visible' => $this->eyebrow_visible ?? true,
            'eyebrow_color' => $this->eyebrow_color ?? '#FFFFFF',
            'description' => $this->description,
            'presentation' => array_replace(Banner::DEFAULT_PRESENTATION, $this->presentation ?? []),
            'revision' => (int) $this->lock_version,
            'button_text' => $this->button_text,
            'image_alt' => $this->image_alt,
            'title_visible' => (bool) $this->title_visible,
            'title_color' => $this->title_color,
            'title_align' => $this->title_align,
            'description_visible' => (bool) $this->description_visible,
            'description_color' => $this->description_color,
            'description_align' => $this->description_align,
            'link_url' => $this->link_url,
            'link_new_window' => (bool) $this->link_new_window,
            'starts_at_local' => TimezoneHelper::toSiteDateTimeLocalString($this->starts_at),
            'ends_at_local' => TimezoneHelper::toSiteDateTimeLocalString($this->ends_at),
            'images' => [
                'pc' => $this->image($request, $this->resource->relationLoaded('pcImage') ? $this->pcImage : null),
                'mobile' => $this->image($request, $this->resource->relationLoaded('mobileImage') ? $this->mobileImage : null),
            ],
            'videos' => [
                'pc' => $this->image($request, $this->resource->relationLoaded('pcVideo') ? $this->pcVideo : null),
                'mobile' => $this->image($request, $this->resource->relationLoaded('mobileVideo') ? $this->mobileVideo : null),
            ],
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록 행 응답을 반환합니다.
     *
     * 제작 상태 두 가지를 함께 싣는다 — 모바일 이미지가 없으면 사이트가 PC 이미지에서 잘라 쓰고(`has_mobile_image`), 버튼 문구가
     * 어느 언어에든 있는데 이동 주소가 없으면 사이트에 버튼이 보이지 않는다(`button_without_link`, 사용자 슬라이더의 버튼 조건과 같다).
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록 배너
     */
    public function toListArray(Request $request): array
    {
        $buttonText = is_array($this->button_text) ? $this->button_text : [];

        return [
            ...$this->baseFields(),
            'thumbnail' => $this->image($request, $this->resource->relationLoaded('pcImage') ? $this->pcImage : null),
            'has_mobile_image' => (bool) $this->resource->getAttribute('has_mobile_image'),
            'button_without_link' => ($this->link_url === null || $this->link_url === '')
                && array_filter($buttonText, fn ($v) => is_string($v) && trim($v) !== '') !== [],
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 => 권한
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-hero_section.banners.update',
            'can_delete' => 'sirsoft-hero_section.banners.delete',
        ];
    }

    /**
     * 상세·목록 공통 필드를 반환합니다.
     *
     * @return array<string, mixed> 공통 필드
     */
    private function baseFields(): array
    {
        $title = is_array($this->title) ? $this->title : [];

        return [
            'id' => $this->id,
            'position' => $this->position,
            ...$this->targetFields(),
            'media_type' => $this->media_type ?? BannerMediaType::Image->value,
            'is_active' => (bool) $this->is_active,
            'status' => $this->resource->statusAt()->value,
            'sort_order' => (int) $this->sort_order,
            'display_period' => $this->display_period,
            'starts_at_display' => TimezoneHelper::toSiteDateTimeString($this->starts_at),
            'ends_at_display' => TimezoneHelper::toSiteDateTimeString($this->ends_at),
            'title' => $title,
            'title_localized' => (string) (LocalizedValue::pick($title, app()->getLocale()) ?? ''),
            'title_locales' => array_keys(array_filter($title, fn ($v) => is_string($v) && $v !== '')),
            'created_at_display' => TimezoneHelper::toSiteDateTimeString($this->created_at),
        ];
    }

    /**
     * 위치·대상 상태 필드를 반환합니다 — 정적 위치·등록되지 않은 위치는 대상 이름·존재 여부가 null 이다.
     *
     * @return array{position_registered: bool, target_key: string|null, target_label: string|null, target_exists: bool|null} 위치·대상 필드
     */
    private function targetFields(): array
    {
        $registry = app(PositionRegistry::class);
        $key = $this->target_key === null ? null : (string) $this->target_key;
        $resolver = $key === null ? null : $registry->resolver((string) $this->position);
        $labels = $resolver === null ? [] : ($this->targetLabels ?? $resolver->labels([$key]));

        return [
            'position_registered' => $registry->isRegistered((string) $this->position),
            'target_key' => $key,
            'target_label' => $labels[$key] ?? null,
            'target_exists' => $resolver === null ? null : isset($labels[$key]),
        ];
    }

    /**
     * 이미지 한 장의 관리자 응답을 만듭니다 — 업로드 응답과 같은 모양(`BannerImageResource`).
     *
     * @param  Request  $request  요청
     * @param  BannerImage|null  $image  이미지
     * @return array<string, mixed>|null 이미지 정보 (없으면 null)
     */
    private function image(Request $request, ?BannerImage $image): ?array
    {
        return $image === null ? null : (new BannerImageResource($image))->toArray($request);
    }
}
