<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 검증 오류 DTO
 *
 * 쿠폰, 할인코드 등의 검증 실패 정보를 담습니다.
 */
class ValidationError
{
    /**
     * 검증 오류 타입 상수
     */
    public const TYPE_COUPON = 'coupon';

    public const TYPE_DISCOUNT_CODE = 'discount_code';

    public const TYPE_POINTS = 'points';

    /**
     * 검증 오류 코드 상수
     */
    public const CODE_EXPIRED = 'expired';

    public const CODE_MIN_AMOUNT = 'min_amount';

    public const CODE_NOT_COMBINABLE = 'not_combinable';

    public const CODE_INVALID_TARGET = 'invalid_target';

    public const CODE_ALREADY_USED = 'already_used';

    public const CODE_NOT_FOUND = 'not_found';

    public const CODE_INSUFFICIENT_POINTS = 'insufficient_points';

    public const CODE_INVALID_SCOPE = 'invalid_scope';

    /**
     * @param  string  $type  오류 타입 (coupon, discount_code, points)
     * @param  int  $couponId  쿠폰/코드 ID
     * @param  string  $code  오류 코드
     * @param  string  $message  오류 메시지 (다국어 키)
     * @param  array  $context  추가 컨텍스트 정보
     */
    public function __construct(
        public string $type = '',
        public int $couponId = 0,
        public string $code = '',
        public string $message = '',
        public array $context = [],
    ) {}

    /**
     * 쿠폰 검증 오류인지 확인합니다.
     */
    public function isCouponError(): bool
    {
        return $this->type === self::TYPE_COUPON;
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'coupon_id' => $this->couponId,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            couponId: $data['coupon_id'] ?? 0,
            code: $data['code'] ?? '',
            message: $data['message'] ?? '',
            context: $data['context'] ?? [],
        );
    }

    /**
     * 쿠폰 만료 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public static function couponExpired(int $couponId): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_EXPIRED,
            message: 'sirsoft-ecommerce::messages.coupon.expired',
        );
    }

    /**
     * 최소주문금액 미달 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     * @param  int  $minAmount  최소 주문금액
     * @param  int  $currentAmount  현재 금액
     */
    public static function minAmountNotMet(int $couponId, int $minAmount, int $currentAmount): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_MIN_AMOUNT,
            message: 'sirsoft-ecommerce::messages.coupon.min_amount_not_met',
            context: [
                'min_amount' => $minAmount,
                'current_amount' => $currentAmount,
            ],
        );
    }

    /**
     * 중복할인 불가 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public static function notCombinable(int $couponId): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_NOT_COMBINABLE,
            message: 'sirsoft-ecommerce::messages.coupon.not_combinable',
        );
    }

    /**
     * 적용 대상 없음 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public static function invalidTarget(int $couponId): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_INVALID_TARGET,
            message: 'sirsoft-ecommerce::messages.coupon.invalid_target',
        );
    }

    /**
     * 이미 사용된 쿠폰 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public static function alreadyUsed(int $couponId): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_ALREADY_USED,
            message: 'sirsoft-ecommerce::messages.coupon.already_used',
        );
    }

    /**
     * 쿠폰을 찾을 수 없음 오류를 생성합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public static function notFound(int $couponId): self
    {
        return new self(
            type: self::TYPE_COUPON,
            couponId: $couponId,
            code: self::CODE_NOT_FOUND,
            message: 'sirsoft-ecommerce::messages.coupon.not_found',
        );
    }
}
