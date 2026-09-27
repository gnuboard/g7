<?php

namespace App\Enums\Analytics;

/**
 * 적재 실패 사유 Enum
 *
 * 수집은 예외를 삼키고 응답을 그대로 돌려주므로, 이 사유값이 방문이 왜 비는지
 * 알 수 있는 유일한 단서입니다.
 */
enum CollectFailureReason: string
{
    /**
     * 요청 형식이 규칙에 맞지 않음
     */
    case Validation = 'validation';

    /**
     * 한 방문의 액션 수 상한 초과
     */
    case MaxActions = 'max_actions';

    /**
     * 데이터베이스 오류
     */
    case DbError = 'db_error';

    /**
     * 확장 리스너에서 발생한 예외
     */
    case ListenerException = 'listener_exception';

    /**
     * 등록되지 않은 목표 키
     */
    case UnknownGoal = 'unknown_goal';

    /**
     * 등록되지 않은 차원 키
     */
    case UnknownDimension = 'unknown_dimension';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('analytics.collect_failure_reason.'.$this->value);
    }
}
