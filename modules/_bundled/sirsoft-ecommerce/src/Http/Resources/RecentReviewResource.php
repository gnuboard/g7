<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 대시보드 최신 리뷰 리소스
 *
 * 관리자 대시보드 최신 리뷰 카드에 표시할 최소 필드만 노출합니다.
 */
class RecentReviewResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->getLocalizedName() ?? '',
            'rating' => (int) $this->rating,
            // 회원 후기는 이커머스 관리자 영역의 표시 이름, 회원 없이 등록한 후기(다른 쇼핑몰에서 이관)는 저장된 작성자 이름
            'author_name' => $this->resource->displayAuthorName(MemberDisplayName::AREA_ADMIN),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
