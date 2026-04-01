<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 상품 1:1 문의 리소스
 */
class ProductInquiryResource extends BaseApiResource
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
            'product_id' => $this->product_id,
            'inquirable_type' => $this->inquirable_type,
            'inquirable_id' => $this->inquirable_id,
            'user_id' => $this->user_id,

            // 작성자 정보
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            // 상품 정보
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->getLocalizedName(),
                'thumbnail_url' => $this->product->getThumbnailUrl(),
            ] : null),

            // 상품명 스냅샷 (다국어)
            'product_name_snapshot' => $this->product_name_snapshot,
            'product_name' => $this->getLocalizedProductName(),

            // 답변 상태
            'is_answered' => $this->is_answered,
            'is_answered_label' => $this->is_answered
                ? __('sirsoft-ecommerce::admin.inquiries.status.answered')
                : __('sirsoft-ecommerce::admin.inquiries.status.pending'),
            'is_answered_badge_color' => $this->is_answered ? 'green' : 'gray',
            'answered_at' => $this->formatDateTimeStringForUser($this->answered_at),

            // 시스템
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 현재 로케일에 맞는 상품명 스냅샷 반환
     *
     * @return string
     */
    private function getLocalizedProductName(): string
    {
        $snapshot = $this->product_name_snapshot;

        if (empty($snapshot)) {
            return '';
        }

        $locale = app()->getLocale();

        return $snapshot[$locale] ?? $snapshot['ko'] ?? array_values($snapshot)[0] ?? '';
    }

    /**
     * 리소스별 권한 매핑 반환
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.inquiries.update',
            'can_delete' => 'sirsoft-ecommerce.inquiries.delete',
        ];
    }
}
