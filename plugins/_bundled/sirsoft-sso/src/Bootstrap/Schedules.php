<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

use Plugins\Sirsoft\Sso\Bootstrap\Schedules\Maintenance;
use Plugins\Sirsoft\Sso\Bootstrap\Schedules\Provisioning;

/**
 * 스케줄 정의를 영역별 파일에서 모읍니다 (`Plugin::getSchedules()` 가 부른다).
 *
 * @since 1.0.0
 */
final class Schedules
{
    /** @var array<int, class-string> 영역 목록 */
    public const AREAS = [
        Provisioning::class,
        Maintenance::class,
    ];

    /**
     * 모든 영역의 스케줄 정의를 합칩니다.
     *
     * @return array<int, array<string, mixed>> 스케줄 정의
     */
    public static function all(): array
    {
        $schedules = [];
        foreach (self::AREAS as $area) {
            foreach ($area::all() as $schedule) {
                $schedules[] = $schedule;
            }
        }

        return $schedules;
    }
}
