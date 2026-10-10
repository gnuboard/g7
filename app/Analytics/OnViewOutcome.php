<?php

namespace App\Analytics;

use App\Models\Analytics\AnalyticsArchiveRun;

/**
 * 화면 열 때 집계 한 번의 결과
 *
 * 통계 화면 요청 하나가 집계를 돌렸는지, 돌리지 않았다면 왜인지를 담습니다. 화면은 이 값으로
 * 「오늘 방문이 상한을 넘어 집계하지 않았다」 같은 안내를 고릅니다.
 */
readonly class OnViewOutcome
{
    /** 설정에서 꺼져 있다 */
    public const DISABLED = 'disabled';

    /** 1분 안에 다른 요청이 이미 집계를 시작했다 */
    public const THROTTLED = 'throttled';

    /** 마지막 집계가 1분 안에 끝나 다시 계산할 필요가 없다 */
    public const FRESH = 'fresh';

    /** 오늘 방문이 상한을 넘어 요청 안에서 집계하지 않았다 */
    public const TOO_MANY_VISITS = 'too_many_visits';

    /** 같은 날을 다른 실행이 계산하고 있다 */
    public const LOCKED = 'locked';

    /** 집계를 돌렸다 */
    public const RAN = 'ran';

    /** 집계 중 예외가 났다 (응답은 그대로 나간다) */
    public const FAILED = 'failed';

    /**
     * 결과를 만듭니다.
     *
     * @param  string  $status  결과 (이 클래스의 상수 중 하나)
     * @param  int|null  $todayVisits  오늘 방문 수 (세지 않았으면 null)
     * @param  int  $maxVisits  오늘 방문 상한
     * @param  array<int, AnalyticsArchiveRun>  $runs  이번에 만든 실행 이력
     */
    public function __construct(
        public string $status,
        public ?int $todayVisits,
        public int $maxVisits,
        public array $runs = [],
    ) {}
}
