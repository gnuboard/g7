<?php

namespace Plugins\Sirsoft\HeroSection\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\HeroSection\Support\Positions\TargetLabels;

/**
 * 배너 목록 컬렉션 — 목록 행·쪽·컬렉션 능력·통계.
 */
class BannerCollection extends BaseApiCollection
{
    /**
     * 통계 (위치별·상태별 건수)
     *
     * @var array<string, mixed>
     */
    private array $statistics = [];

    /**
     * 통계를 함께 싣습니다.
     *
     * @param  array<string, mixed>  $statistics  통계
     * @return static 자기 자신
     */
    public function withStatistics(array $statistics): static
    {
        $this->statistics = $statistics;

        return $this;
    }

    /**
     * 컬렉션 능력 매핑을 반환합니다 — 목록 단추 표시용.
     *
     * @return array<string, string> 능력 => 권한
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-hero_section.banners.update',
            'can_update' => 'sirsoft-hero_section.banners.update',
            'can_delete' => 'sirsoft-hero_section.banners.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 바꿉니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록 응답
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->rows($request),
            'abilities' => $this->resolveCollectionAbilities($request),
            'statistics' => $this->statistics,
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }

    /**
     * 목록 행을 만듭니다 — 동적 위치의 대상 이름은 위치마다 한 번에 푼다(행마다 대상 테이블을 묻지 않게).
     *
     * @param  Request  $request  요청
     * @return array<int, array<string, mixed>> 목록 행
     */
    private function rows(Request $request): array
    {
        $labels = TargetLabels::forBanners($this->collection);

        return $this->collection->map(function ($banner) use ($request, $labels) {
            $resource = new BannerResource($banner);
            if (isset($labels[$banner->position])) {
                $resource->withTargetLabels($labels[$banner->position]);
            }

            return $resource->toListArray($request);
        })->values()->all();
    }
}
