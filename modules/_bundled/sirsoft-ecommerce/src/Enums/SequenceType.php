<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 시퀀스 타입 Enum
 *
 * 채번 시스템에서 사용하는 시퀀스 타입을 정의합니다.
 */
enum SequenceType: string
{
    /**
     * 상품 코드
     */
    case PRODUCT = 'product';

    /**
     * 주문 번호
     */
    case ORDER = 'order';

    /**
     * 배송 번호
     */
    case SHIPPING = 'shipping';

    /**
     * 취소 번호
     */
    case CANCEL = 'cancel';

    /**
     * 환불 번호
     */
    case REFUND = 'refund';

    /**
     * 교환 번호
     */
    case EXCHANGE = 'exchange';

    /**
     * 반품 번호
     *
     * `return` 은 PHP 예약어라 케이스 이름에 쓸 수 없어 ORDER_RETURN 으로 둔다.
     */
    case ORDER_RETURN = 'order_return';

    /**
     * 클레임 추가결제 번호 (PG merchant_order_id 로도 사용)
     */
    case CLAIM_PAYMENT = 'claim_payment';

    /**
     * 모든 타입 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 타입 값인지 확인
     *
     * @param  string  $value  확인할 값
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 타입별 기본 설정 반환
     *
     * @return array{algorithm: SequenceAlgorithm, prefix: string|null, pad_length: int, max_value: int, max_history_count: int, nanoid_length?: int, nanoid_alphabet?: string}
     */
    public function getDefaultConfig(): array
    {
        return match ($this) {
            self::PRODUCT => [
                'algorithm' => SequenceAlgorithm::NANOID,
                'prefix' => null,
                'pad_length' => 0,
                'max_value' => 0,
                'max_history_count' => 0,
                'nanoid_length' => 16,
                'nanoid_alphabet' => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            ],
            self::ORDER => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => null,
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
            self::SHIPPING => [
                'algorithm' => SequenceAlgorithm::SEQUENTIAL,
                'prefix' => 'SHP-',
                'pad_length' => 8,
                'max_value' => 99999999,
                'max_history_count' => 0,
            ],
            self::CANCEL => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => 'CN',
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
            self::REFUND => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => 'RF',
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
            self::EXCHANGE => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => 'EX',
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
            self::ORDER_RETURN => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => 'RT',
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
            self::CLAIM_PAYMENT => [
                'algorithm' => SequenceAlgorithm::TIMESTAMP,
                'prefix' => 'CP',
                'pad_length' => 0,
                'max_value' => PHP_INT_MAX,
                'max_history_count' => 0,
            ],
        };
    }
}
