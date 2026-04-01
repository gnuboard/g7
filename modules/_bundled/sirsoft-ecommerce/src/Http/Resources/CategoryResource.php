<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 카테고리 API 리소스
 */
class CategoryResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'localized_name' => $this->getLocalizedName(),
            'parent_id' => $this->parent_id,
            'path' => $this->path,
            'depth' => $this->depth,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'slug' => $this->slug,
            'url' => $this->slug, // SortableMenuItem에서 slug를 url로 표시
            'icon' => 'folder', // SortableMenuList 컴포넌트용 아이콘
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            // 관계
            'parent' => $this->whenLoaded('parent', function () {
                return new CategoryResource($this->parent);
            }),
            'children' => $this->whenLoaded('children', function () {
                return CategoryResource::collection($this->children);
            }),
            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'hash' => $image->hash,
                        'original_filename' => $image->original_filename,
                        'mime_type' => $image->mime_type,
                        'size' => $image->file_size,
                        'size_formatted' => $this->formatFileSize($image->file_size),
                        'download_url' => $image->download_url,
                        'order' => $image->sort_order,
                        'is_image' => str_starts_with($image->mime_type ?? '', 'image/'),
                        'alt_text' => $image->alt_text,
                    ];
                });
            }),

            // 통계 (withCount와 whenLoaded 모두 지원)
            'products_count' => $this->products_count ?? ($this->whenLoaded('products', fn () => $this->products->count(), 0)),
            'children_count' => $this->children_count ?? ($this->whenLoaded('children', fn () => $this->children->count(), 0)),

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
            'can_create' => 'sirsoft-ecommerce.categories.create',
            'can_update' => 'sirsoft-ecommerce.categories.update',
            'can_delete' => 'sirsoft-ecommerce.categories.delete',
        ];
    }

    /**
     * 파일 크기를 읽기 쉬운 형식으로 변환합니다.
     *
     * @param int|null $bytes 바이트 크기
     * @return string 포맷된 크기 문자열
     */
    protected function formatFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }
}
