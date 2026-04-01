<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 시퀀스 알고리즘 Enum
 *
 * 채번 시스템에서 사용할 수 있는 다양한 알고리즘을 정의합니다.
 */
enum SequenceAlgorithm: string
{
    /**
     * 하이브리드: max(timestamp, lastValue) + increment
     *
     * - 개별 등록: 시간이 지났으면 새 타임스탬프 기준
     * - 일괄 등록: 빠르게 연속 생성 시 시퀀스 증가
     * - 상품코드에 적합
     */
    case HYBRID = 'hybrid';

    /**
     * 순수 시퀀스: current_value + increment
     *
     * - 단순히 현재 값에서 증가
     * - 주문번호, 배송번호 등에 적합
     */
    case SEQUENTIAL = 'sequential';

    /**
     * 일별 리셋: 날짜 변경 시 min_value부터 시작
     *
     * - 매일 1번부터 시작
     * - 일별 정산번호 등에 적합
     */
    case DAILY = 'daily';

    /**
     * 타임스탬프: Ymd-His + 밀리초 + 랜덤 보정
     *
     * - 형식: 20260208-1435226549
     * - 주문번호 등 시간 기반 고유 코드에 적합
     * - 중복 시 재시도 (최대 10회)
     */
    case TIMESTAMP = 'timestamp';

    /**
     * NanoID: 랜덤 문자열 기반 고유 코드
     *
     * - 채번테이블 미사용 (DB 트랜잭션/락 불필요)
     * - 기본 알파벳: 0-9, A-Z (36자), 기본 길이: 16자
     * - 상품코드 등 테이블 기반 채번이 불필요한 경우에 적합
     */
    case NANOID = 'nanoid';

    /**
     * 모든 알고리즘 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 알고리즘 값인지 확인
     *
     * @param string $value 확인할 값
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
