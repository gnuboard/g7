<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 쿠폰 발급 경로 Enum (#952)
 *
 * 발급 내역(`ecommerce_promotion_coupon_issues.issue_source`)에 기록한다. 이 컬럼이 생기기 전의 행은 NULL 이며,
 * 화면은 쿠폰 코드 접두(DL·DR)로 경로를 추정한다(`fromLegacyCode()`).
 */
enum CouponIssueSource: string
{
    case DOWNLOAD = 'download';               // 회원이 직접 받음
    case DIRECT = 'direct';                   // 관리자 직접발급
    case SIGNUP = 'signup';                   // 가입 자동발급
    case FIRST_PURCHASE = 'first_purchase';   // 첫구매 자동발급
    case BIRTHDAY = 'birthday';               // 생일 자동발급

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.coupon_issue_source.'.$this->value;
    }

    /**
     * 발급 코드 접두를 반환합니다. (DL=다운로드, DR=직접발급, AU=자동발급)
     *
     * @return string 코드 접두
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::DOWNLOAD => 'DL',
            self::DIRECT => 'DR',
            self::SIGNUP, self::FIRST_PURCHASE, self::BIRTHDAY => 'AU',
        };
    }

    /**
     * 자동발급 경로인지 반환합니다.
     *
     * @return bool 가입·첫구매·생일이면 true
     */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::SIGNUP, self::FIRST_PURCHASE, self::BIRTHDAY], true);
    }

    /**
     * 발급 조건에 대응하는 자동발급 경로를 반환합니다.
     *
     * @param  CouponIssueCondition  $condition  쿠폰 발급 조건
     * @return self|null 자동발급 조건이 아니면 null
     */
    public static function fromCondition(CouponIssueCondition $condition): ?self
    {
        return match ($condition) {
            CouponIssueCondition::SIGNUP => self::SIGNUP,
            CouponIssueCondition::FIRST_PURCHASE => self::FIRST_PURCHASE,
            CouponIssueCondition::BIRTHDAY => self::BIRTHDAY,
            CouponIssueCondition::MANUAL => null,
        };
    }

    /**
     * 경로 컬럼이 비어 있는 옛 발급 건의 경로를 쿠폰 코드 접두로 추정합니다.
     *
     * @param  string|null  $couponCode  쿠폰 코드
     * @return self|null 추정할 수 없으면 null
     */
    public static function fromLegacyCode(?string $couponCode): ?self
    {
        $prefix = strtoupper(substr((string) $couponCode, 0, 2));

        return match ($prefix) {
            'DL' => self::DOWNLOAD,
            'DR' => self::DIRECT,
            default => null,
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
