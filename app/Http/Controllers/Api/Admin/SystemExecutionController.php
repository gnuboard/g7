<?php

namespace App\Http\Controllers\Api\Admin;

use App\Execution\CommandRunnerResolver;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\SystemExecutionService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 — 실행 방식·예약 작업 화면(W5)의 서버 표면.
 *
 * 네 가지를 다룬다:
 *
 * 1. **실행 방식 판정 근거** — 지금 어떤 모드로 도는지와 그 근거(차단된 함수 목록·PHP
 *    실행 파일 확인 결과). 화면 상단 카드가 소비한다.
 * 2. **다시 감지** — 호스팅이 설정을 바꾼 뒤 운영자가 그 자리에서 재판정하는 버튼.
 * 3. **호출 번호(토큰)** — 조회와 재발급. 일반 설정 조회 응답에는 실리지 않으므로
 *    (D20) 이 전용 경로가 유일한 통로다.
 * 4. **지금 실행** — 운영자가 화면에서 한 번 돌려 보는 버튼.
 *
 * 토큰 조회에 `core.settings.update` 를 요구하는 이유는 그 값이 **인증 없는 실행
 * 자격증명**이기 때문이다. 설정을 읽을 수만 있는 계정이 그것을 얻으면 등급 구분이
 * 사라진다 — 화면의 [보기]·[복사] 도 읽기 전용 사용자에게는 보이지 않는다.
 *
 * @since 7.1.0
 */
class SystemExecutionController extends AdminBaseController
{
    /**
     * @param  SystemExecutionService  $service  실행 방식·예약 작업 서비스
     * @param  CommandRunnerResolver  $resolver  실행 모드 해석기
     */
    public function __construct(
        private readonly SystemExecutionService $service,
        private readonly CommandRunnerResolver $resolver,
    ) {}

    /**
     * 실행 방식 판정 근거와 예약 작업 상태를 반환합니다.
     *
     * @return JsonResponse 상태 JSON 응답
     */
    public function show(): JsonResponse
    {
        try {
            return $this->success('settings.execution.fetch_success', $this->service->status());
        } catch (\Throwable $e) {
            return $this->error('settings.execution.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 실행 환경을 다시 감지합니다.
     *
     * 저장된 설정이 `auto` 가 아니면 그 고정값이 계속 우선한다 — 재감지는 **근거를
     * 갱신할 뿐** 운영자의 선택을 덮지 않는다. 응답이 감지 결과와 유효 모드를 함께
     * 실어 화면이 그 차이를 보일 수 있게 한다.
     *
     * @return JsonResponse 감지 결과 JSON 응답
     */
    public function detect(): JsonResponse
    {
        try {
            return $this->success('settings.execution.detect_success', [
                'detection' => $this->service->detectionReport(),
                'effective_mode' => $this->resolver->resolveMode()->value,
                'configured_mode' => $this->service->configuredMode()->value,
            ]);
        } catch (\Throwable $e) {
            return $this->error('settings.execution.detect_failed', 500, $e->getMessage());
        }
    }

    /**
     * 웹 크론 호출 주소를 반환합니다 ([보기]·[복사] 전용 — D20).
     *
     * 번호가 아직 없으면 이 시점에 발급한다 — 운영자가 [보기] 를 눌렀다는 것은 그
     * 주소를 쓰겠다는 뜻이고, 발급 버튼을 따로 누르게 하면 빈 값만 보인다.
     *
     * @return JsonResponse 호출 주소 JSON 응답
     */
    public function cronToken(): JsonResponse
    {
        try {
            return $this->success('schedule.cron.token_fetch_success', $this->service->revealCallUrl());
        } catch (\Throwable $e) {
            return $this->error('schedule.cron.token_fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 웹 크론 호출 번호를 재발급합니다 (기존 주소는 즉시 무효).
     *
     * @return JsonResponse 새 호출 주소 JSON 응답
     */
    public function rotateCronToken(): JsonResponse
    {
        try {
            return $this->success('schedule.cron.token_rotated', $this->service->rotateCallUrl());
        } catch (\Throwable $e) {
            return $this->error('schedule.cron.token_rotate_failed', 500, $e->getMessage());
        }
    }

    /**
     * 예약 작업을 지금 한 번 실행합니다.
     *
     * 최소 호출 간격은 적용하지 않는다 — 그 제한은 외부의 과다 호출을 막기 위한
     * 것이고, 이 경로는 권한을 확인한 운영자의 명시적 행동이다.
     *
     * @return JsonResponse 실행 요약 JSON 응답
     */
    public function runSchedule(): JsonResponse
    {
        try {
            return $this->success('schedule.cron.completed', $this->service->runNow());
        } catch (\Throwable $e) {
            return $this->error('schedule.cron.run_failed', 500, $e->getMessage());
        }
    }
}
