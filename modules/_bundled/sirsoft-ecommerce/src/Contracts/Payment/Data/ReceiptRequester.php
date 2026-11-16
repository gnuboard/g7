<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 영수증 조회 요청자 (공개 계약 DTO)
 *
 * 세 가지 중 하나로 만든다: 회원 본인, 비회원 조회 토큰(이커머스가 검증), 결제사가 자체 쿠키로 확인한 비회원.
 *
 * @since 1.4.0
 */
final readonly class ReceiptRequester
{
    /** 회원 본인 */
    public const MEMBER = 'member';

    /** 비회원 조회 토큰 */
    public const GUEST_TOKEN = 'guest_token';

    /** 결제사가 확인한 비회원 */
    public const GUEST_VERIFIED_BY_PG = 'guest_verified_by_pg';

    /**
     * ReceiptRequester 생성자
     *
     * @param  string  $kind  요청자 종류(위 상수)
     * @param  int|null  $userId  회원 id(MEMBER)
     * @param  string|null  $token  비회원 조회 토큰(GUEST_TOKEN)
     */
    private function __construct(
        public string $kind,
        public ?int $userId = null,
        public ?string $token = null,
    ) {}

    /**
     * 회원 본인 요청자를 만듭니다.
     *
     * @param  int  $userId  회원 id
     * @return self 요청자
     */
    public static function member(int $userId): self
    {
        return new self(self::MEMBER, userId: $userId);
    }

    /**
     * 비회원 조회 토큰 요청자를 만듭니다(토큰 검증은 이커머스가 한다).
     *
     * @param  string|null  $token  `X-Guest-Order-Token` 값
     * @return self 요청자
     */
    public static function guestToken(?string $token): self
    {
        return new self(self::GUEST_TOKEN, token: $token);
    }

    /**
     * 결제사가 자체 수단(영수증 쿠키 등)으로 확인한 비회원 요청자를 만듭니다. 이커머스는 비회원 주문인지만 확인한다.
     *
     * @return self 요청자
     */
    public static function guestVerifiedByPg(): self
    {
        return new self(self::GUEST_VERIFIED_BY_PG);
    }
}
