<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 주문 계산 입력 DTO
 *
 * 계산 대상 아이템과 옵션을 담습니다.
 */
class CalculationInput
{
    /**
     * @param  CalculationItem[]  $items  계산 대상 아이템 배열
     * @param  int[]  $couponIssueIds  적용할 쿠폰 발급 ID 배열 (주문/배송비 쿠폰)
     * @param  array<int, int[]>  $itemCoupons  상품옵션별 쿠폰 매핑 [상품옵션ID => [쿠폰발급ID, ...]]
     * @param  string|null  $discountCode  적용할 할인코드
     * @param  int  $usePoints  사용할 마일리지
     * @param  ShippingAddress|null  $shippingAddress  배송 주소 정보 (배송비 계산용)
     * @param  string|null  $paymentCurrency  결제 선택 통화 (예: 'USD', 'KRW')
     * @param  array<string, mixed>  $metadata  플러그인 확장용 메타데이터
     *         - user_id: 사용자 ID (자동쿠폰 조회, 회원등급 할인 등)
     *         - user_grade_id: 회원등급 ID (회원등급 할인 플러그인)
     *         - referral_code: 유입경로 코드 (유입경로 할인 플러그인)
     *         - campaign_id: 캠페인 ID (프로모션 플러그인)
     * @param  array|null  $shippingPolicySnapshots  배송정책 스냅샷 (환불 재계산용)
     * @param  array|null  $promotionSnapshots  프로모션 스냅샷 (환불 재계산용)
     */
    public function __construct(
        public array $items = [],
        public array $couponIssueIds = [],
        public array $itemCoupons = [],
        public ?string $discountCode = null,
        public int $usePoints = 0,
        public ?ShippingAddress $shippingAddress = null,
        public ?string $paymentCurrency = null,
        public array $metadata = [],
        public ?array $shippingPolicySnapshots = null,
        public ?array $promotionSnapshots = null,
    ) {}

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            fn (array $item) => CalculationItem::fromArray($item),
            $data['items'] ?? []
        );

        $shippingAddress = null;
        if (isset($data['shipping_address']) && is_array($data['shipping_address'])) {
            $shippingAddress = ShippingAddress::fromArray($data['shipping_address']);
        }

        return new self(
            items: $items,
            couponIssueIds: $data['coupon_issue_ids'] ?? [],
            itemCoupons: $data['item_coupons'] ?? [],
            discountCode: $data['discount_code'] ?? null,
            usePoints: $data['use_points'] ?? 0,
            shippingAddress: $shippingAddress,
            paymentCurrency: $data['payment_currency'] ?? null,
            metadata: $data['metadata'] ?? [],
            shippingPolicySnapshots: $data['shipping_policy_snapshots'] ?? null,
            promotionSnapshots: $data['promotion_snapshots'] ?? null,
        );
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn (CalculationItem $item) => $item->toArray(), $this->items),
            'coupon_issue_ids' => $this->couponIssueIds,
            'item_coupons' => $this->itemCoupons,
            'discount_code' => $this->discountCode,
            'use_points' => $this->usePoints,
            'shipping_address' => $this->shippingAddress?->toArray(),
            'payment_currency' => $this->paymentCurrency,
            'metadata' => $this->metadata,
            'shipping_policy_snapshots' => $this->shippingPolicySnapshots,
            'promotion_snapshots' => $this->promotionSnapshots,
        ];
    }
}
