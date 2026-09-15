<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;

/**
 * 리뷰 이미지 리소스
 */
class ProductReviewImageResource extends BaseApiResource
{
    /**
     * 숨김 리뷰 이미지의 <img> 렌더용 한시 서명 download URL 발급 여부.
     *
     * 게이트(관리자 권한 미들웨어)를 통과한 응답의 직렬화 시점에만 참이 된다 —
     * 전시중(VISIBLE) 리뷰는 무서명 공개 URL 을 유지한다 (CDN·캐시 안정성).
     */
    private bool $signedDownload = false;

    /**
     * 리뷰 이미지 목록을 리소스 배열로 변환합니다.
     *
     * @param  iterable<int, ProductReviewImage>|null  $images  리뷰 이미지 목록
     * @param  bool  $signedDownload  한시 서명 download URL 발급 여부 (숨김 리뷰 전용)
     * @return array<int, self>
     */
    public static function collectionFor($images, bool $signedDownload = false): array
    {
        return Collection::make($images ?? [])
            ->map(function ($image) use ($signedDownload) {
                $resource = new self($image);
                $resource->signedDownload = $signedDownload;

                return $resource;
            })
            ->values()
            ->all();
    }

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
            'download_url' => $this->signedDownload
                ? $this->resource->signedDownloadUrl()
                : $this->download_url,
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
