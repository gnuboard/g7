<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\System\WebCronRunRequest;
use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronPolicy;
use App\Scheduling\WebCronTokenStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 웹 크론 진입점 — 서버 크론을 걸 수 없는 호스팅의 예약 작업 실행 (A-5).
 *
 * 호스팅 제어판의 "URL 호출형 예약 실행" 이나 외부 크론 서비스가 이 주소를 주기적으로
 * 부른다. 본체는 ScheduleRunner 하나이고, 이 컨트롤러는 **누가 부를 수 있는가**와
 * **얼마나 자주 부를 수 있는가**만 판정한다.
 *
 * 응답 규약:
 *
 * - 인증을 통과하면 **언제나 200 + JSON 요약**이다. 실행 0건이어도 200 이다 —
 *   외부 크론 서비스는 비-200 을 장애로 보고 알림을 쏟는데, "지금은 돌 것이 없다" 는
 *   장애가 아니다.
 * - 인증 실패만 401(번호 불일치)·403(허용 IP 밖)이다.
 * - 토큰은 **어떤 로그에도 남기지 않는다**. 접근 로그에 쿼리스트링이 남는 환경을
 *   전제로 화면이 재발급 안내를 함께 둔다.
 *
 * 토큰은 쿼리스트링과 헤더 양쪽으로 받는다 — 외부 크론 서비스 중 헤더를 설정할 수
 * 없는 곳이 많아 쿼리스트링 지원을 유지해야 하고, 설정할 수 있는 곳은 접근 로그에
 * 남지 않는 헤더를 쓰는 편이 낫다.
 *
 * @since 7.1.0
 */
class WebCronController extends PublicBaseController
{
    /**
     * @param  ScheduleRunner  $runner  스케줄 러너 본체
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @param  WebCronPolicy  $policy  호출 정책
     * @param  WebCronTokenStore  $tokens  호출 번호 보관처
     */
    public function __construct(
        private readonly ScheduleRunner $runner,
        private readonly ScheduleRunState $state,
        private readonly WebCronPolicy $policy,
        private readonly WebCronTokenStore $tokens,
    ) {}

    /**
     * 밀린 예약 작업을 실행합니다.
     *
     * @param  WebCronRunRequest  $request  요청
     * @return JsonResponse 실행 요약 (인증 통과 시 언제나 200)
     */
    public function run(WebCronRunRequest $request): JsonResponse
    {
        $token = $request->presentedToken();

        // 상수 시간 비교 — 조기 반환 비교는 앞자리부터 맞춰 가는 추측을 가능하게 한다.
        if (! $this->tokens->matches($token)) {
            return $this->error('schedule.cron.invalid_token', 401);
        }

        if (! $this->policy->ipAllowed($request->ip())) {
            return $this->error('schedule.cron.ip_not_allowed', 403);
        }

        // 실행 방식이 "서버 크론만" 이면 인증을 통과해도 실행하지 않는다.
        if (! $this->policy->webCallAllowed()) {
            return $this->success('schedule.cron.disabled', [
                'ran' => false,
                'reason' => 'web_call_disabled',
                'mode' => $this->policy->mode(),
            ]);
        }

        $now = CarbonImmutable::now();
        $throttle = $this->throttleRemaining($now);

        if ($throttle > 0) {
            // 최소 간격 미만 호출은 **실행 없이 정상 응답**한다. 이 스킵은 작업을
            // 누락시키지 않는다 — 다음 유효 호출의 소급 창이 그 사이 시각을 덮는다 (D17).
            return $this->success('schedule.cron.throttled', [
                'ran' => false,
                'reason' => 'min_interval',
                'retry_after_seconds' => $throttle,
            ]);
        }

        $this->state->recordInvocation($now);

        // 인증을 통과한 호출은 **언제나 200** 이다 (A-5). 러너 안에서 터진 예외를 그대로
        // 흘려보내면 500 이 나가는데, 이 주소를 부르는 쪽은 외부 크론 서비스라 비-200 을
        // 「사이트 장애」로 읽고 알림을 쏟는다 — 운영자는 예약이 아니라 사이트가 죽었다고
        // 판단한다. 실행 실패는 장애가 아니라 **요약에 실어 보고할 사실**이다.
        try {
            $summary = $this->runner->run($now);
        } catch (\Throwable $e) {
            Log::error('예약 작업 러너가 중단되었습니다.', [
                'error' => $e->getMessage(),
            ]);

            // 원문은 로그에만 남긴다 — 이 면은 무인증 표면이다.
            return $this->success('schedule.cron.completed', [
                'ran' => true,
                'aborted' => true,
                'due' => 0,
                'executed' => 0,
                'failed' => 0,
                'skipped' => 0,
                'truncated' => false,
                'events' => [],
            ]);
        }

        return $this->success('schedule.cron.completed', array_merge(
            ['ran' => true],
            $summary->toArray(),
        ));
    }

    /**
     * 최소 호출 간격까지 남은 시간(초)을 계산합니다.
     *
     * @param  CarbonImmutable  $now  기준 시각
     * @return int 남은 초 (0 이면 실행 가능)
     */
    private function throttleRemaining(CarbonImmutable $now): int
    {
        $interval = $this->policy->minIntervalMinutes();

        if ($interval <= 0) {
            return 0;
        }

        $lastInvoked = $this->state->lastInvokedAt();

        if ($lastInvoked === null) {
            return 0;
        }

        $elapsed = $lastInvoked->diffInSeconds($now, absolute: false);
        $remaining = ($interval * 60) - (int) $elapsed;

        return max(0, $remaining);
    }
}
