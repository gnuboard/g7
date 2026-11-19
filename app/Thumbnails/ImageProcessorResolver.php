<?php

namespace App\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\Engines\GdImageProcessor;
use App\Thumbnails\Engines\ImagickImageProcessor;

/**
 * 드라이버 카테고리 `image` 값 → 이미지 처리 엔진
 *
 * 고른 엔진을 이 서버에서 쓸 수 없으면 `gd`, gd 도 없으면 null 이다 — null 이면 썸네일 없이 원본을 내고
 * 업로드 축소도 하지 않는다. 플러그인 엔진은 필터 `core.thumbnail.filter_processors`(값: `[id => 구현체]`)로 넘긴다.
 * 필터가 배열이 아니면 코어 엔진만 쓰고, 엔진이 아닌 항목은 버리며, 비어 있는 코어 id(`gd`·`imagick`) 자리는 코어 엔진이다.
 */
class ImageProcessorResolver
{
    /**
     * 현재 설정의 엔진을 돌려줍니다.
     *
     * @return ImageProcessorInterface|null 사용 가능한 엔진 (없으면 null)
     */
    public function resolve(): ?ImageProcessorInterface
    {
        $processors = $this->processors();
        $selected = (string) config('core.image.driver', 'gd');

        $candidate = $processors[$selected] ?? null;

        if ($candidate instanceof ImageProcessorInterface && $candidate->isAvailable()) {
            return $candidate;
        }

        $fallback = $processors['gd'] ?? null;

        return $fallback instanceof ImageProcessorInterface && $fallback->isAvailable() ? $fallback : null;
    }

    /**
     * 등록된 엔진 전부를 돌려줍니다.
     *
     * @return array<string, ImageProcessorInterface> id → 엔진
     */
    public function processors(): array
    {
        $core = [
            'gd' => app(GdImageProcessor::class),
            'imagick' => app(ImagickImageProcessor::class),
        ];

        $filtered = HookManager::applyFilters('core.thumbnail.filter_processors', $core);

        if (! is_array($filtered)) {
            return $core;
        }

        // 엔진이 아닌 항목은 버리고, 그래서 비거나 구독자가 빠뜨린 코어 id 자리는 코어 엔진으로 채운다 —
        // `gd` 가 사라지면 기본 엔진 복귀가 없어져 썸네일과 업로드 축소가 모두 꺼진다.
        return array_filter($filtered, fn ($processor) => $processor instanceof ImageProcessorInterface) + $core;
    }
}
