<?php

namespace Plugins\Sirsoft\HeroSection\Support;

use App\Support\LocalizedValue;
use Plugins\Sirsoft\HeroSection\Enums\BannerMediaType;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;

/**
 * 공개 응답 한 건의 모양을 만든다 — 요청 언어로 문구를 고르고(요청 언어 → 사이트 기본 언어 → en),
 * 운영 정보(사용 여부·기간·작성자)는 싣지 않는다.
 *
 * `_period` 는 캐시에서 꺼낼 때 다시 판정하려고 서비스 안에서만 쓰는 값이며 응답 직전에 뺀다.
 */
class PublicBannerPresenter
{
    /**
     * 배너 한 건을 공개 응답 모양으로 바꿉니다.
     *
     * @param  Banner  $banner  배너 (pcImage·mobileImage 로드)
     * @param  string  $locale  요청 언어
     * @return array<string, mixed> 공개 배너 (+ 내부용 `_period`)
     */
    public static function present(Banner $banner, string $locale): array
    {
        $title = self::pick($banner->title, $locale);
        $alt = self::pick($banner->image_alt, $locale);

        return [
            'id' => $banner->id,
            'presentation' => array_replace(Banner::DEFAULT_PRESENTATION, $banner->presentation ?? []),
            'title' => $title,
            'eyebrow' => self::pick($banner->eyebrow, $locale),
            'eyebrow_visible' => $banner->eyebrow_visible ?? true,
            'eyebrow_color' => $banner->eyebrow_color ?? '#FFFFFF',
            'description' => self::pick($banner->description, $locale),
            'button_text' => self::pick($banner->button_text, $locale),
            // alt → 제목 순으로 대체 — 링크 배너라 이름이 필요하다
            'image_alt' => $alt !== '' ? $alt : $title,
            'title_visible' => (bool) $banner->title_visible,
            'title_color' => $banner->title_color,
            'title_align' => $banner->title_align,
            'description_visible' => (bool) $banner->description_visible,
            'description_color' => $banner->description_color,
            'description_align' => $banner->description_align,
            'link_url' => $banner->link_url,
            'link_new_window' => (bool) $banner->link_new_window,
            'media_type' => $banner->media_type ?? BannerMediaType::Image->value,
            'youtube_id' => $banner->media_type === BannerMediaType::Youtube->value ? $banner->youtube_id : null,
            'images' => [
                'pc' => self::image($banner->pcImage),
                'mobile' => self::image($banner->mobileImage),
            ],
            // 영상 파일은 미디어 종류가 영상일 때만 싣는다 — 종류를 바꿔도 남은 파일이 사이트에 나오지 않게
            'videos' => $banner->media_type === BannerMediaType::Video->value ? [
                'pc' => self::video($banner->pcVideo),
                'mobile' => self::video($banner->mobileVideo),
            ] : null,
            '_period' => [
                'is_active' => (bool) $banner->is_active,
                'display_period' => (string) $banner->display_period,
                'starts_at' => $banner->starts_at?->toIso8601String(),
                'ends_at' => $banner->ends_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * 다국어 값에서 요청 언어 문구를 고릅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string  $locale  요청 언어
     * @return string 고른 문구 (없으면 빈 문자열)
     */
    private static function pick(mixed $value, string $locale): string
    {
        if ($value === null) {
            return '';
        }

        return (string) (LocalizedValue::pick($value, $locale) ?? '');
    }

    /**
     * 이미지 한 장의 공개 정보를 만듭니다.
     *
     * @param  BannerImage|null  $image  이미지
     * @return array{url: string, width: int|null, height: int|null}|null 공개 이미지 (없으면 null)
     */
    private static function image(?BannerImage $image): ?array
    {
        if ($image === null) {
            return null;
        }

        return ['url' => $image->publicUrl(), 'width' => $image->width, 'height' => $image->height];
    }

    /**
     * 공개 영상 정보를 만듭니다.
     *
     * @param  BannerImage|null  $video  영상
     * @return array{url: string, mime: string}|null 영상 정보
     */
    private static function video(?BannerImage $video): ?array
    {
        if ($video === null) {
            return null;
        }

        return ['url' => $video->publicUrl(), 'mime' => $video->mime];
    }
}
