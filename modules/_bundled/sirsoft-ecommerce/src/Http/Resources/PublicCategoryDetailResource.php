<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 공개 카테고리 상세 API 리소스
 *
 * 단일 카테고리 조회 시 사용하며, 브레드크럼과 활성 자식 카테고리를 포함합니다.
 */
class PublicCategoryDetailResource extends BaseApiResource
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
            'description' => $this->description,
            'description_localized' => $this->getLocalizedField('description'),
            'slug' => $this->slug,
            'depth' => $this->depth,
            'parent_id' => $this->parent_id,
            'products_count' => $this->products_count ?? 0,
            'breadcrumb' => $this->getBreadcrumb(),
            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'hash' => $image->hash,
                        'original_filename' => $image->original_filename,
                        'download_url' => $image->download_url,
                        'alt_text' => $image->alt_text,
                    ];
                });
            }),
            'children' => $this->whenLoaded('activeChildren', function () {
                return $this->activeChildren->map(fn ($child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'name_localized' => $child->getLocalizedName(),
                    'slug' => $child->slug,
                    'depth' => $child->depth,
                    'products_count' => $child->products_count ?? 0,
                ]);
            }),
        ];
    }
}
