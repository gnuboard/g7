<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 현금영수증 식별번호 종류 Enum
 *
 * 주민등록번호는 수집하지 않는다. 개인정보 보호법 제24조의2 는 법령상 구체적 근거가 없는
 * 주민등록번호 처리를 원칙 금지하며, 아래 3종으로 소득공제/지출증빙 양쪽 용도를 대체할 수 있다.
 */
enum CashReceiptIdentifierType: string
{
    /**
     * 휴대폰번호 (소득공제/지출증빙 공통)
     */
    case PHONE = 'phone';

    /**
     * 현금영수증 카드번호 (소득공제/지출증빙 공통)
     */
    case CARD = 'card';

    /**
     * 사업자등록번호 (지출증빙 전용)
     */
    case BUSINESS = 'business';

    /**
     * 국세청 자진발급 지정번호 (소득공제용 전용)
     *
     * 구매자가 현금영수증을 신청하지 않은 건에 대해 사업자가 자진발급할 때 사용한다.
     */
    public const SELF_ISSUE_NUMBER = '0100001234';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 식별번호를 마스킹합니다 (뒤 4자리만 노출).
     *
     * 식별번호의 형태를 아는 것은 이 Enum 의 책임이므로 마스킹 규칙도 여기에 둔다.
     * 주문 생성 시점(신청 저장)과 발급 시점(이력 기록)이 동일한 마스킹을 써야 하므로 SSoT 로 유지한다.
     *
     * @param  string  $identifier  식별번호 원본
     * @return string 마스킹된 식별번호
     */
    public static function mask(string $identifier): string
    {
        $length = strlen($identifier);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($identifier, -4);
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

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
        return 'sirsoft-ecommerce::cash_receipt.identifier_type.'.$this->value;
    }

    /**
     * 식별번호 형식이 유효한지 확인합니다.
     *
     * 하이픈·공백은 호출부에서 제거한 뒤 전달한다.
     * 현금영수증 카드번호는 공개된 체크섬 규격을 확인하지 못해 자릿수만 검증한다.
     *
     * @param  string  $identifier  하이픈 제거된 식별번호
     * @return bool 형식 유효 여부
     */
    public function matches(string $identifier): bool
    {
        return match ($this) {
            self::PHONE => (bool) preg_match('/^01[016789]\d{7,8}$/', $identifier),
            self::CARD => (bool) preg_match('/^\d{13,19}$/', $identifier),
            self::BUSINESS => self::isValidBusinessNumber($identifier),
        };
    }

    /**
     * 사업자등록번호 체크섬을 검증합니다.
     *
     * 가중치 [1,3,7,1,3,7,1,3,5] 를 앞 9자리에 곱하고, 9번째 자리 곱 결과의 십의 자리를 가산한 뒤
     * (10 - 합 % 10) % 10 이 마지막 자리와 일치해야 한다.
     *
     * 앞 3자리(세무서 코드)가 000 인 번호는 체크섬을 수학적으로 항상 만족(0000000000)하지만
     * 발급될 수 없는 번호이므로 별도로 배제한다.
     *
     * @param  string  $number  하이픈 제거된 10자리 사업자등록번호
     * @return bool 체크섬 유효 여부
     */
    public static function isValidBusinessNumber(string $number): bool
    {
        if (! preg_match('/^\d{10}$/', $number)) {
            return false;
        }

        if (substr($number, 0, 3) === '000') {
            return false;
        }

        $weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $number[$index]) * $weight;
        }

        // 9번째 자리(index 8) 곱 결과의 십의 자리를 가산
        $sum += intdiv(((int) $number[8]) * 5, 10);

        return ((10 - ($sum % 10)) % 10) === (int) $number[9];
    }

    /**
     * 자진발급 지정번호인지 확인합니다.
     *
     * @param  string  $identifier  하이픈 제거된 식별번호
     * @return bool 자진발급 지정번호 여부
     */
    public static function isSelfIssueNumber(string $identifier): bool
    {
        return $identifier === self::SELF_ISSUE_NUMBER;
    }
}
