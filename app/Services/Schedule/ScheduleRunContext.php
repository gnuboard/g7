<?php

namespace App\Services\Schedule;

use App\Enums\ScheduleTriggerType;
use App\Models\ScheduleHistory;
use Throwable;

/**
 * DB 스케줄 이벤트를 바깥 러너가 돌리는 동안의 실행 문맥.
 *
 * 등록기는 행마다 인자 없는 `Schedule::call` 콜백을 등록한다 — 프레임워크 스케줄러가 부르는 모양이
 * 그렇다. 그래서 웹 러너(`App\Scheduling\ScheduleRunner`)가 「관리자가 [지금 실행]을 눌렀다」는 계기와
 * 누른 사람을 콜백에 넘길 통로가 따로 있어야 하고, 콜백이 실행기에서 받은 결과(이력·거부 예외)를
 * 러너에 돌려줄 통로도 있어야 한다. 이 통로가 없으면 같은 실행이 실행기 기록에는 「예약 실행」으로
 * 남고, 실행기가 실패로 마감한 실행을 러너 요약은 성공으로 센다.
 *
 * 문맥은 `within()` 이 감싼 동안만 있다. 그 밖(서버 크론의 `schedule:run`)에서는 기본값 —
 * 예약 실행, 실행자 없음 — 이고 결과 보고는 버려진다.
 */
final class ScheduleRunContext
{
    /**
     * 겹쳐 쌓인 문맥 (안쪽이 끝).
     *
     * @var list<array{trigger: ScheduleTriggerType, user_id: int|null, outcome: ScheduleHistory|Throwable|null}>
     */
    private static array $frames = [];

    /**
     * 주어진 계기·실행자로 콜백을 실행합니다.
     *
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID (예약 실행이면 null)
     * @param  callable(): mixed  $callback  문맥 안에서 실행할 콜백
     * @return mixed 콜백의 반환값
     */
    public static function within(ScheduleTriggerType $trigger, ?int $userId, callable $callback): mixed
    {
        self::$frames[] = ['trigger' => $trigger, 'user_id' => $userId, 'outcome' => null];

        try {
            return $callback();
        } finally {
            array_pop(self::$frames);
        }
    }

    /**
     * 지금 문맥의 실행 계기 (문맥 밖이면 예약 실행).
     *
     * @return ScheduleTriggerType 실행 계기
     */
    public static function trigger(): ScheduleTriggerType
    {
        return self::current()['trigger'] ?? ScheduleTriggerType::Scheduled;
    }

    /**
     * 지금 문맥의 수동 실행자 ID (문맥 밖이면 null).
     *
     * @return int|null 실행자 ID
     */
    public static function userId(): ?int
    {
        return self::current()['user_id'] ?? null;
    }

    /**
     * 콜백이 받은 실행 결과를 지금 문맥에 남깁니다 (문맥 밖이면 버림).
     *
     * @param  ScheduleHistory|Throwable|null  $outcome  실행 이력, 실행 전 거부 예외, 또는 null(큐 투입·기록 없음)
     * @return void 반환값 없음
     */
    public static function report(ScheduleHistory|Throwable|null $outcome): void
    {
        $last = array_key_last(self::$frames);

        if ($last !== null) {
            self::$frames[$last]['outcome'] = $outcome;
        }
    }

    /**
     * 지금 문맥에 남은 실행 결과.
     *
     * @return ScheduleHistory|Throwable|null 실행 결과 (보고가 없었으면 null)
     */
    public static function outcome(): ScheduleHistory|Throwable|null
    {
        return self::current()['outcome'] ?? null;
    }

    /**
     * 가장 안쪽 문맥.
     *
     * @return array{trigger: ScheduleTriggerType, user_id: int|null, outcome: ScheduleHistory|Throwable|null}|null 문맥 (없으면 null)
     */
    private static function current(): ?array
    {
        $last = array_key_last(self::$frames);

        return $last === null ? null : self::$frames[$last];
    }
}
