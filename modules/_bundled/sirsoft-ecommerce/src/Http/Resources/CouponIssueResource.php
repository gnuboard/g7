<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 쿠폰 발급 내역 API 리소스
 */
class CouponIssueResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 쿠폰 발급 내역 리소스 배열
     */
    public function toArray($request): array
    {
        // 유효기간이 지난 available 행은 저장소 필터에서 '만료'로 분류되므로,
        // 표시 상태도 같은 기준을 따라야 한다. 원시 status 만 쓰면 만료 목록에
        // "사용가능" 라벨이 붙는다.
        $displayStatus = $this->isExpired()
            ? CouponIssueRecordStatus::EXPIRED
            : $this->status;

        return [
            'id' => $this->id,
            'coupon_id' => $this->coupon_id,
            'user_id' => $this->user?->uuid,
            'coupon_code' => $this->coupon_code,

            // 발급 경로 — 경로 컬럼 이전 행은 코드 접두(DL/DR)로 추정한다
            'issue_source' => $this->resource->resolveIssueSource()?->value,
            'issue_source_label' => $this->resource->resolveIssueSource()?->label(),

            // 상태 — status 는 저장된 원시값, *_label/badge 는 유효기간을 반영한 표시값
            'status' => $this->status?->value,
            'status_label' => $displayStatus?->label(),
            'status_badge_color' => $displayStatus?->badgeColor(),

            // 날짜
            'issued_at' => $this->formatDateTimeStringForUser($this->issued_at),
            'expired_at' => $this->formatDateTimeStringForUser($this->expired_at),
            'used_at' => $this->formatDateTimeStringForUser($this->used_at),

            // 사용 정보
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'discount_amount' => $this->roundToBaseCurrency($this->discount_amount),

            // 상태 확인
            'is_used' => $this->status?->value === 'used',
            'is_expired' => $this->isExpired(),
            'is_usable' => $this->isUsable(),
            'is_cancellable' => $this->status?->value === 'available' && ! $this->isExpired(),

            // 회원 이름은 표시 이름이다(관리자 화면은 이커머스 관리자 영역). 실명은 회원 조회 권한이 있는 관리자에게만
            // `user_real_name` 으로 더해진다. 관계를 읽어 두지 않은 응답에는 종전처럼 키가 없다.
            ...($this->resource->relationLoaded('user')
                ? MemberDisplayName::staffFields($this->resource->user, $request, 'user_name')
                : []),
        ];
    }
}
