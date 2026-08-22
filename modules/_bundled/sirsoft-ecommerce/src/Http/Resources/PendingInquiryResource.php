<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 대시보드 미답변 문의 리소스
 *
 * 관리자 대시보드 미답변 문의 카드에 표시할 최소 필드만 노출합니다.
 */
class PendingInquiryResource extends BaseApiResource
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
            // 상품 문의 원본은 게시판 Post (inquirable). 관리자 상세는 게시판 글 상세로 이동한다.
            'inquirable_id' => $this->inquirable_id,
            'product_name' => $this->getLocalizedProductName(),
            'author_name' => $this->user?->name ?? '',
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }

    /**
     * 현재 로케일에 맞는 상품명 스냅샷을 반환합니다.
     *
     * @return string 로케일별 상품명 (없으면 빈 문자열)
     */
    private function getLocalizedProductName(): string
    {
        $snapshot = $this->product_name_snapshot;

        if (empty($snapshot)) {
            return $this->product?->getLocalizedName() ?? '';
        }

        $locale = app()->getLocale();

        return $snapshot[$locale]
            ?? $snapshot[config('app.fallback_locale', 'ko')]
            ?? array_values($snapshot)[0]
            ?? '';
    }
}
