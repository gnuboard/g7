<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Support\LocalizedText;

/**
 * 상품 리뷰 리소스
 */
class ProductReviewResource extends BaseApiResource
{
    /** 능력 → 권한 식별자 (행·목록 공용 — 라우트 reviews.update / reviews.delete 와 같다) */
    private const ABILITY_MAP = [
        'can_update' => 'sirsoft-ecommerce.reviews.update',
        'can_delete' => 'sirsoft-ecommerce.reviews.delete',
    ];

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
            // 이메일은 관리자 화면 응답과 본인 열람에만 싣는다 — 상품 리뷰 목록은 인증 없이
            // 열리므로, 이메일을 그대로 실으면 구매자 주소록이 응답 본문으로 수집된다.
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->canExposeMemberEmail($request, $this->user->id) ? $this->user->email : null,
            ]),

            // 상품 정보
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'product_code' => $this->product->product_code,
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

            // 이미지 — 숨김(HIDDEN) 리뷰의 이미지는 한시 서명 download URL 로 직렬화한다.
            // 숨김 리뷰가 이미지와 함께 직렬화되는 경로는 관리자(권한 미들웨어) 응답뿐이므로
            // (공개 목록은 VISIBLE 필터, 작성 직후는 이미지 미로드) 서명 발급이 곧
            // 게이트 통과 자격의 위임이다. 전시중 리뷰는 무서명 공개 URL 유지.
            'images' => $this->whenLoaded('images', fn () => ProductReviewImageResource::collectionFor(
                $this->images,
                signedDownload: $this->status === ReviewStatus::HIDDEN
            )),
            // 첨부 개수는 목록 쿼리의 DB 집계(`withCount('images as image_count')`)를 우선 쓴다.
            // 관계 기반 계산만 두면, 이미지를 로드하지 않는 목록에서 개수까지 함께 사라진다.
            'image_count' => $this->image_count !== null
                ? (int) $this->image_count
                : $this->whenLoaded('images', fn () => $this->images->count()),

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
                'email' => $this->canExposeMemberEmail($request, $this->replyAdmin->id) ? $this->replyAdmin->email : null,
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

            return LocalizedText::pick($optionName, $locale);
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
        return self::ABILITY_MAP;
    }

    /**
     * 소유자 필드명을 반환합니다. (응답의 `is_owner` — 화면이 본인 리뷰에만 수정·삭제를 붙이는 근거)
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }

    /**
     * 관리자 리뷰 목록 수준 능력 맵을 반환합니다. (행 능력과 같은 식별자)
     *
     * @param  User|null  $user  현재 사용자
     * @return array<string, bool> 능력 불리언 맵
     */
    public static function collectionAbilities(?User $user): array
    {
        $abilities = [];

        foreach (self::ABILITY_MAP as $key => $identifier) {
            $abilities[$key] = PermissionHelper::check($identifier, $user);
        }

        return $abilities;
    }
}
