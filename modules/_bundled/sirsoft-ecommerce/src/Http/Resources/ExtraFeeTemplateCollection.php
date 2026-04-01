<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use App\Http\Resources\Traits\HasRowNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 추가배송비 템플릿 컬렉션 리소스
 *
 * 추가배송비 템플릿 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class ExtraFeeTemplateCollection extends ResourceCollection
{
    use HasAbilityCheck;
    use HasRowNumber;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.shipping-policies.create',
            'can_update' => 'sirsoft-ecommerce.shipping-policies.update',
            'can_delete' => 'sirsoft-ecommerce.shipping-policies.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->mapWithRowNumber(function ($template) {
                return (new ExtraFeeTemplateResource($template))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
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
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param array $statistics 통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $result = [
            'data' => $this->mapWithRowNumber(function ($template) {
                return (new ExtraFeeTemplateResource($template))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'statistics' => $statistics,
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
     * 배송정책 extra_fee_settings용 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 우편번호-배송비 매핑 배열
     */
    public function toExtraFeeSettings(): array
    {
        return $this->collection->map(function ($template) {
            return [
                'zipcode' => $template->zipcode,
                'fee' => (float) $template->fee,
                'region' => $template->region ?? '',
            ];
        })->toArray();
    }

    /**
     * 지역별 통계 형태의 배열을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 지역별 통계 배열
     */
    public function groupByRegion(): array
    {
        return $this->collection->groupBy('region')
            ->map(function ($items, $region) {
                return [
                    'region' => $region ?: '미지정',
                    'count' => $items->count(),
                    'min_fee' => (float) $items->min('fee'),
                    'max_fee' => (float) $items->max('fee'),
                    'avg_fee' => round($items->avg('fee'), 2),
                ];
            })
            ->values()
            ->toArray();
    }
}
