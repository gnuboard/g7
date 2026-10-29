<?php

namespace Plugins\Sirsoft\Popup\Http\Resources;

use App\Helpers\TimezoneHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 팝업 리소스 — 상세(수정 화면)와 목록 행 두 모양을 낸다.
 *
 * 기간은 수정 화면 입력용(`*_local`, 사이트 시간대 datetime-local)과 표시용(`*_display`)을 함께 싣는다.
 * 작성자 id 는 싣지 않는다.
 *
 * @mixin Popup
 */
class PopupResource extends BaseApiResource
{
    /**
     * 상세 응답을 반환합니다(다국어 원본·이미지 2종·세대 번호·abilities).
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 상세 팝업
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->baseFields(),
            'revision' => (int) $this->lock_version,
            'image_alt' => $this->image_alt ?? (object) [],
            'body_html' => $this->body_html ?? (object) [],
            'link_url' => $this->link_url,
            'link_new_window' => (bool) $this->link_new_window,
            'overlays' => $this->overlays,
            'starts_at_local' => TimezoneHelper::toSiteDateTimeLocalString($this->starts_at),
            'ends_at_local' => TimezoneHelper::toSiteDateTimeLocalString($this->ends_at),
            'images' => [
                'pc' => $this->image($request, $this->resource->relationLoaded('pcImage') ? $this->pcImage : null),
                'mobile' => $this->image($request, $this->resource->relationLoaded('mobileImage') ? $this->mobileImage : null),
            ],
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록 행 응답을 반환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록 팝업
     */
    public function toListArray(Request $request): array
    {
        return [
            ...$this->baseFields(),
            'thumbnail' => $this->image($request, $this->resource->relationLoaded('pcImage') ? $this->pcImage : null),
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
            'can_update' => 'sirsoft-popup.popups.update',
            'can_delete' => 'sirsoft-popup.popups.delete',
        ];
    }

    /**
     * 상세·목록 공통 필드를 반환합니다.
     *
     * @return array<string, mixed> 공통 필드
     */
    private function baseFields(): array
    {
        $translations = $this->content_type === ContentType::Html->value ? $this->body_html : $this->image_alt;
        $filled = is_array($translations)
            ? array_keys(array_filter($translations, fn ($v) => is_string($v) && trim($v) !== ''))
            : [];

        return [
            'id' => $this->id,
            'title' => (string) $this->title,
            'is_active' => (bool) $this->is_active,
            'status' => $this->resource->statusAt()->value,
            'sort_order' => (int) $this->sort_order,
            'content_type' => $this->content_type,
            'display_period' => $this->display_period,
            'starts_at_display' => TimezoneHelper::toSiteDateTimeString($this->starts_at),
            'ends_at_display' => TimezoneHelper::toSiteDateTimeString($this->ends_at),
            'page_scope' => $this->page_scope,
            'routes' => array_values($this->routes ?? []),
            'audience' => $this->audience,
            'device' => $this->device,
            // 입력된 언어 — 목록 「{유형} · {언어들}」 표시용(이미지형은 alt, 직접 작성형은 본문 기준)
            'filled_locales' => $filled,
            'created_at_display' => TimezoneHelper::toSiteDateTimeString($this->created_at),
        ];
    }

    /**
     * 이미지 한 장의 관리자 응답을 만듭니다 — 업로드 응답과 같은 모양(`PopupImageResource`).
     *
     * @param  Request  $request  요청
     * @param  PopupImage|null  $image  이미지
     * @return array<string, mixed>|null 이미지 정보 (없으면 null)
     */
    private function image(Request $request, ?PopupImage $image): ?array
    {
        return $image === null ? null : (new PopupImageResource($image))->toArray($request);
    }
}
