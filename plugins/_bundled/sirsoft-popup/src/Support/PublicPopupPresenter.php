<?php

namespace Plugins\Sirsoft\Popup\Support;

use App\Support\LocalizedValue;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 공개 응답 한 건의 모양을 만든다 — 요청 언어로 alt·본문을 고르고(요청 언어 → 사이트 기본 언어),
 * 운영 정보(관리용 제목·사용 여부·기간·작성자)는 싣지 않는다. 이미지 위 글 상자도 글을 같은 순서로 골라 문자열 하나로 싣고,
 * 모바일 배치는 모바일 이미지가 있을 때만 싣는다(없으면 휴대폰도 PC 이미지와 PC 배치).
 *
 * `_period` 는 캐시에서 꺼낼 때 다시 판정하려고 서비스 안에서만 쓰는 값이며 응답 직전에 뺀다.
 */
class PublicPopupPresenter
{
    /**
     * 팝업 한 건을 공개 응답 모양으로 바꿉니다.
     *
     * @param  Popup  $popup  팝업 (pcImage·mobileImage 로드)
     * @param  string  $locale  요청 언어
     * @return array<string, mixed> 공개 팝업 (+ 내부용 `_period`)
     */
    public static function present(Popup $popup, string $locale): array
    {
        $isImage = $popup->content_type === ContentType::Image->value;
        $alt = $isImage ? self::pick($popup->image_alt, $locale) : '';

        return [
            'id' => $popup->id,
            'content_type' => $popup->content_type,
            'page_scope' => $popup->page_scope,
            'routes' => array_values($popup->routes ?? []),
            'device' => $popup->device,
            'image' => $isImage ? [
                'pc' => self::image($popup->pcImage),
                'mobile' => self::image($popup->mobileImage),
            ] : null,
            // alt → 관리용 제목 순으로 대체 — 링크 팝업의 이름이 비지 않게
            'image_alt' => $isImage ? ($alt !== '' ? $alt : (string) $popup->title) : '',
            'link_url' => $isImage ? $popup->link_url : null,
            'link_new_window' => $isImage && (bool) $popup->link_new_window,
            'overlays' => $isImage ? self::overlays($popup, $locale) : null,
            'body_html' => $isImage ? '' : self::pick($popup->body_html, $locale),
            '_period' => [
                'is_active' => (bool) $popup->is_active,
                'display_period' => (string) $popup->display_period,
                'starts_at' => $popup->starts_at?->toIso8601String(),
                'ends_at' => $popup->ends_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * 다국어 값에서 요청 언어 값을 고릅니다(없으면 사이트 기본 언어).
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string  $locale  요청 언어
     * @return string 고른 값 (없으면 빈 문자열)
     */
    private static function pick(mixed $value, string $locale): string
    {
        if ($value === null) {
            return '';
        }

        return (string) (LocalizedValue::pick($value, $locale) ?? '');
    }

    /**
     * 이미지 위 글 상자를 공개 모양으로 만듭니다 — 글은 요청 언어로 고른 문자열, 글이 빈 상자는 뺍니다.
     *
     * @param  Popup  $popup  이미지형 팝업 (mobileImage 로드)
     * @param  string  $locale  요청 언어
     * @return array{pc: array<int, array<string, mixed>>, mobile: array<int, array<string, mixed>>|null}|null 공개 글 상자 (없으면 null)
     */
    private static function overlays(Popup $popup, string $locale): ?array
    {
        $overlays = $popup->overlays;
        if (! is_array($overlays)) {
            return null;
        }

        $pc = Overlays::present($overlays['pc'] ?? [], $locale);
        $mobile = $popup->mobileImage !== null && is_array($overlays['mobile'] ?? null)
            ? Overlays::present($overlays['mobile'], $locale)
            : null;

        return $pc === [] && ($mobile === null || $mobile === []) ? null : ['pc' => $pc, 'mobile' => $mobile];
    }

    /**
     * 이미지 한 장의 공개 정보를 만듭니다.
     *
     * @param  PopupImage|null  $image  이미지
     * @return array{url: string, width: int|null, height: int|null}|null 공개 이미지 (없으면 null)
     */
    private static function image(?PopupImage $image): ?array
    {
        if ($image === null) {
            return null;
        }

        return ['url' => $image->publicUrl(), 'width' => $image->width, 'height' => $image->height];
    }
}
