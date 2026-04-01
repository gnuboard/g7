<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 적용된 프로모션 DTO (상품별 또는 주문별)
 */
class AppliedPromotions
{
    /**
     * @param  CouponApplication[]  $coupons  적용된 쿠폰 목록
     * @param  DiscountCodeApplication[]  $discountCodes  적용된 할인코드 목록
     * @param  EventApplication[]  $events  적용된 이벤트 목록
     */
    public function __construct(
        public array $coupons = [],
        public array $discountCodes = [],
        public array $events = [],
    ) {}

    /**
     * 쿠폰을 추가합니다.
     *
     * @param  CouponApplication  $coupon  쿠폰 적용 정보
     */
    public function addCoupon(CouponApplication $coupon): void
    {
        $this->coupons[] = $coupon;
    }

    /**
     * 할인코드를 추가합니다.
     *
     * @param  DiscountCodeApplication  $discountCode  할인코드 적용 정보
     */
    public function addDiscountCode(DiscountCodeApplication $discountCode): void
    {
        $this->discountCodes[] = $discountCode;
    }

    /**
     * 이벤트를 추가합니다.
     *
     * @param  EventApplication  $event  이벤트 적용 정보
     */
    public function addEvent(EventApplication $event): void
    {
        $this->events[] = $event;
    }

    /**
     * 쿠폰 할인 총액을 반환합니다.
     */
    public function getCouponDiscount(): int
    {
        return array_reduce(
            $this->coupons,
            fn (int $sum, CouponApplication $coupon) => $sum + $coupon->totalDiscount,
            0
        );
    }

    /**
     * 할인코드 할인 총액을 반환합니다.
     */
    public function getCodeDiscount(): int
    {
        return array_reduce(
            $this->discountCodes,
            fn (int $sum, DiscountCodeApplication $code) => $sum + $code->totalDiscount,
            0
        );
    }

    /**
     * 총 할인금액을 반환합니다.
     */
    public function getTotalDiscount(): int
    {
        return $this->getCouponDiscount() + $this->getCodeDiscount();
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'coupons' => array_map(fn (CouponApplication $coupon) => $coupon->toArray(), $this->coupons),
            'discount_codes' => array_map(fn (DiscountCodeApplication $code) => $code->toArray(), $this->discountCodes),
            'events' => array_map(fn (EventApplication $event) => $event->toArray(), $this->events),
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        $coupons = array_map(
            fn (array $coupon) => CouponApplication::fromArray($coupon),
            $data['coupons'] ?? []
        );

        $discountCodes = array_map(
            fn (array $code) => DiscountCodeApplication::fromArray($code),
            $data['discount_codes'] ?? []
        );

        $events = array_map(
            fn (array $event) => EventApplication::fromArray($event),
            $data['events'] ?? []
        );

        return new self(
            coupons: $coupons,
            discountCodes: $discountCodes,
            events: $events,
        );
    }
}
