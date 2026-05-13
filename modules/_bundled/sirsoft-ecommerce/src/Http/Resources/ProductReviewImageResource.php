<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 리뷰 이미지 리소스
 */
class ProductReviewImageResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'hash' => $this->hash,
            'original_filename' => $this->original_filename,
            'download_url' => $this->download_url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'is_thumbnail' => $this->is_thumbnail,
            'sort_order' => $this->sort_order,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),

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
            'can_delete' => 'sirsoft-ecommerce.reviews.delete',
        ];
    }
}
