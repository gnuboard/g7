<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 공개 카테고리 API 리소스
 *
 * 프론트엔드 사용자 페이지에서 카테고리 트리를 표시하기 위한 리소스입니다.
 * 관리자용 CategoryResource와 달리, 공개에 필요한 최소 필드만 포함합니다.
 */
class PublicCategoryResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request 요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),
            'slug' => $this->slug,
            'depth' => $this->depth,
            'parent_id' => $this->parent_id,
            'products_count' => $this->products_count ?? 0,

            // 자식 카테고리 (재귀)
            'children' => $this->whenLoaded('children', function () {
                return PublicCategoryResource::collection($this->children);
            }),
        ];
    }
}
