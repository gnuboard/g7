<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 상품 리뷰 리소스
 */
class ProductReviewResource extends BaseApiResource
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
            'order_option_id' => $this->order_option_id,
            'user_id' => $this->user?->uuid,

            // 작성자 정보
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),

            // 상품 정보
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->getLocalizedName(),
                'thumbnail_url' => $this->product->getThumbnailUrl(),
            ]),

            // 옵션 스냅샷 (원본 배열 + 표시용 문자열)
            'option_snapshot' => $this->option_snapshot,
            'option_snapshot_label' => $this->getOptionSnapshotLabel(),

            // 평점
            'rating' => $this->rating,

            // 내용
            'content' => $this->content,
            'content_mode' => $this->content_mode,

            // 상태
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_badge_color' => $this->status->badgeColor(),

            // 이미지
            'images' => ProductReviewImageResource::collection($this->whenLoaded('images')),
            'image_count' => $this->whenLoaded('images', fn () => $this->images->count()),

            // 주문 옵션 정보
            'orderOption' => $this->whenLoaded('orderOption', fn () => [
                'id' => $this->orderOption->id,
                'order_id' => $this->orderOption->order_id,
                'order_number' => $this->orderOption->order?->order_number,
                'quantity' => $this->orderOption->quantity,
                'created_at' => $this->formatDateTimeStringForUser($this->orderOption->created_at),
            ]),

            // 답글
            'has_reply' => ! is_null($this->reply_content),
            'has_reply_label' => ! is_null($this->reply_content)
                ? __('sirsoft-ecommerce::enums.has_reply.replied')
                : __('sirsoft-ecommerce::enums.has_reply.not_replied'),
            'has_reply_badge_color' => ! is_null($this->reply_content) ? 'green' : 'gray',
            'reply_content' => $this->reply_content,
            'reply_content_mode' => $this->reply_content_mode,
            'reply_admin_uuid' => $this->whenLoaded('replyAdmin', fn () => $this->replyAdmin->uuid),
            'reply_admin' => $this->whenLoaded('replyAdmin', fn () => [
                'uuid' => $this->replyAdmin->uuid,
                'name' => $this->replyAdmin->name,
                'email' => $this->replyAdmin->email,
            ]),
            'replied_at' => $this->formatDateTimeStringForUser($this->replied_at),
            'reply_updated_at' => $this->formatDateTimeStringForUser($this->reply_updated_at),

            // 시스템
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 옵션 스냅샷에서 표시용 문자열을 반환합니다.
     *
     * option_snapshot['option_name']이 다국어 배열인 경우 현재 로케일의 값을 반환합니다.
     *
     * @return string
     */
    private function getOptionSnapshotLabel(): string
    {
        $snapshot = $this->option_snapshot;
        if (empty($snapshot)) {
            return '';
        }

        $optionName = $snapshot['option_name'] ?? '';

        if (is_array($optionName)) {
            $locale = app()->getLocale();

            return $optionName[$locale] ?? $optionName['ko'] ?? array_values($optionName)[0] ?? '';
        }

        return (string) $optionName;
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.reviews.update',
            'can_delete' => 'sirsoft-ecommerce.reviews.delete',
        ];
    }
}
