<?php

namespace App\Enums;

/**
 * 업그레이드 스텝 실행 대장의 상태 Enum
 *
 * @since 7.2.0
 */
enum UpgradeStepRunStatus: string
{
    /**
     * 실행 중 (한 서버가 선점함)
     */
    case Running = 'running';

    /**
     * 끝남
     */
    case Done = 'done';

    /**
     * 실패 (다음 실행이 다시 선점할 수 있다)
     */
    case Failed = 'failed';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 상태 값
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
