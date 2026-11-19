<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 브랜드 API 리소스
 */
class BrandResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'localized_name' => $this->getLocalizedName(),
            'slug' => $this->slug,
            'url' => $this->slug, // SortableMenuItem에서 slug를 url로 표시
            'website' => $this->website,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'icon' => 'tag', // SortableMenuList 컴포넌트용 아이콘
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 관계
            // 등록자와 수정자의 이름은 표시 이름이다(관리자 화면은 이커머스 관리자 영역). 실명은 회원 조회 권한이 있는 관리자에게만 `real_name` 으로 더해진다.
            'creator' => $this->whenLoaded('creator', function () use ($request) {
                return [
                    'id' => $this->creator->id,
                    ...MemberDisplayName::staffFields($this->creator, $request),
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () use ($request) {
                return [
                    'id' => $this->updater->id,
                    ...MemberDisplayName::staffFields($this->updater, $request),
                ];
            }),

            // 통계 (withCount 또는 whenLoaded 지원)
            'products_count' => $this->products_count ?? ($this->whenLoaded('products', fn () => $this->products->count(), 0)),

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.brands.create',
            'can_update' => 'sirsoft-ecommerce.brands.update',
            'can_delete' => 'sirsoft-ecommerce.brands.delete',
        ];
    }
}
