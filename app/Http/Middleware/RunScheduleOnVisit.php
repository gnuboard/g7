<?php

namespace App\Http\Middleware;

use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronPolicy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 방문 트리거 폴백 — 방문자의 요청이 끝난 뒤 밀린 예약 작업을 한 번 돌린다 (D5).
 *
 * **기본은 꺼짐이다.** 이 방식은 예약을 방문 트래픽에 의존시키므로, 방문자가 없으면
 * 예약도 돌지 않는다 — 화면과 문서가 그 성질을 함께 알린다. 호스팅 제어판의 예약
 * 실행이나 외부 크론 서비스를 걸 수 없는 사이트의 마지막 수단이다.
 *
 * 실행은 응답을 보낸 **뒤**(terminate)에 한다. 방문자는 자기 요청이 예약 작업을
 * 기다리는 것을 느끼지 않아야 한다. 다만 PHP-FPM 이 아닌 SAPI 에서는 응답이 완전히
 * 닫히기 전에 이 구간이 돌 수 있으므로, 러너의 실행 상한이 그대로 방어선이 된다.
 *
 * 최소 호출 간격은 웹 크론과 같은 설정을 쓴다 — 그러지 않으면 방문마다 러너가 돌아
 * 트래픽이 있는 사이트에서 상시 부하가 된다.
 *
 * @since 7.1.0
 */
class RunScheduleOnVisit
{
    /**
     * @param  ScheduleRunner  $runner  스케줄 러너 본체
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @param  WebCronPolicy  $policy  호출 정책
     */
    public function __construct(
        private readonly ScheduleRunner $runner,
        private readonly ScheduleRunState $state,
        private readonly WebCronPolicy $policy,
    ) {}

    /**
     * 요청을 그대로 통과시킵니다 (실행은 terminate 에서).
     *
     * @param  Request  $request  요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * 응답을 보낸 뒤 밀린 예약 작업을 실행합니다.
     *
     * @param  Request  $request  요청
     * @param  Response  $response  응답
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldRun($request)) {
                return;
            }

            $now = CarbonImmutable::now();

            $this->state->recordInvocation($now);
            $this->runner->run($now);
        } catch (\Throwable $e) {
            // 방문자의 요청은 이미 끝났다 — 여기서 터진 예외가 다른 요청에 영향을
            // 주지 않도록 기록만 남긴다.
            Log::warning('방문 트리거 예약 실행 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 이 요청이 방문 트리거 실행 대상인지 판정합니다.
     *
     * @param  Request  $request  요청
     * @return bool 실행 여부
     */
    private function shouldRun(Request $request): bool
    {
        if (! $this->policy->visitFallbackEnabled() || ! $this->policy->webCallAllowed()) {
            return false;
        }

        // 웹 크론 주소 자신의 요청에서는 돌지 않는다 — 그 경로는 이미 러너를 부른다.
        if ($request->routeIs('api.public.system.cron.run')) {
            return false;
        }

        $interval = $this->policy->minIntervalMinutes();

        if ($interval <= 0) {
            // 간격 제한이 없어도 방문 트리거는 최소 1분을 둔다 — 그러지 않으면
            // 방문 하나마다 러너가 돌아 트래픽이 곧 부하가 된다.
            $interval = 1;
        }

        $this->state->refresh();
        $lastInvoked = $this->state->lastInvokedAt();

        return $lastInvoked === null
            || $lastInvoked->addMinutes($interval)->isPast();
    }
}
