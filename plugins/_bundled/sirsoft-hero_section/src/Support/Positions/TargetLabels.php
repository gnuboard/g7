<?php

namespace Plugins\Sirsoft\HeroSection\Support\Positions;

use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 배너 묶음의 대상 이름을 위치마다 한 번에 푼다 — 목록·미리보기·순서 창 행마다 대상 테이블을 묻지 않게.
 */
final class TargetLabels
{
    /**
     * 배너들의 대상 이름을 위치별로 반환합니다(정적 위치·등록되지 않은 위치는 빠진다).
     *
     * @param  iterable<int, Banner>  $banners  배너 묶음
     * @return array<string, array<string, string>> 위치 키 => (대상 키 => 이름)
     */
    public static function forBanners(iterable $banners): array
    {
        $keys = [];
        foreach ($banners as $banner) {
            if ($banner->target_key !== null) {
                $keys[(string) $banner->position][(string) $banner->target_key] = true;
            }
        }

        $registry = app(PositionRegistry::class);
        $labels = [];
        foreach ($keys as $position => $targets) {
            $resolver = $registry->resolver($position);
            if ($resolver !== null) {
                $labels[$position] = $resolver->labels(array_map('strval', array_keys($targets)));
            }
        }

        return $labels;
    }
}
