<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PrivacyManualRunConflictException;
use App\Exceptions\PrivacyManualRunDisabledException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\PrivacyManualRunRequest;
use App\Http\Requests\Admin\PrivacyManualRunStatusRequest;
use App\Http\Resources\PrivacyManualRunResource;
use App\Privacy\ManualRun\PrivacyManualRunRegistry;
use App\Privacy\ManualRun\PrivacyManualRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 개인정보 조치 관리자 직권 실행 컨트롤러
 *
 * 설정 화면 카드의 「지금 실행」이 부른다. 흐름은 ① 상태 조회(카드의 진행 상태·마지막 결과,
 * 진행 중이면 화면이 주기적으로 다시 부른다) ② 확인 창 건수 ③ 큐 접수.
 *
 * 대상은 코어가 모른 채 수집기에서 키로 찾는다 — 보유기간 핸들러(확장 포함)·휴면 단계·확장이
 * 등록한 조치가 같은 경로를 쓴다. 그래서 실행 권한은 라우트가 정적으로 알 수 없고, 대상이
 * 선언한 「설정 수정 + 도메인 수정」을 여기서 요청 시점에 검사한다(적용 범위 전체만).
 */
class PrivacyManualRunController extends AdminBaseController
{
    /**
     * @param  PrivacyManualRunRegistry  $registry  직권 실행 대상 수집기
     * @param  PrivacyManualRunService  $service  직권 실행 서비스
     */
    public function __construct(
        private PrivacyManualRunRegistry $registry,
        private PrivacyManualRunService $service,
    ) {
        parent::__construct();
    }

    /**
     * 대상별 직권 실행 상태를 조회합니다.
     *
     * 실행 권한이 없는 대상은 `can_run=false`·`run=null` 로 가린다. `has_active` 는 화면이
     * 주기 조회를 계속할지 판정하는 값이고, `finished_since` 는 `since` 이후에 끝난 작업이 있는지다
     * (끝났으면 화면이 대상 건수를 다시 받는다).
     *
     * @param  PrivacyManualRunStatusRequest  $request  대상 키 목록
     * @return JsonResponse 대상 키 => 상태
     */
    public function index(PrivacyManualRunStatusRequest $request): JsonResponse
    {
        $checkedAt = now();
        $since = $request->since();
        $statuses = $this->service->statuses($request->targets(), $request->user());
        $hasActive = false;
        $finishedSince = false;
        $targets = [];

        foreach ($statuses as $key => $status) {
            $run = $status['run'];
            $hasActive = $hasActive || ($run !== null && $run->status->isActive());
            $finishedSince = $finishedSince || ($since !== null && $run?->finished_at !== null && $run->finished_at->gte($since));
            $targets[$key] = [
                ...$status,
                'run' => $run !== null ? (new PrivacyManualRunResource($run))->toArray($request) : null,
            ];
        }

        return $this->success('common.success', [
            'targets' => $targets,
            'has_active' => $hasActive,
            // 화면 폴링용 — `checked_at` 을 다음 조회의 `since` 로 돌려보내면, 그 사이에 끝난 작업이
            // 있을 때 `finished_since` 가 true 가 되어 화면이 대상 건수를 다시 받는다.
            'finished_since' => $finishedSince,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);
    }

    /**
     * 확인 창에 보일 건수·방식을 조회합니다 (데이터를 바꾸지 않음).
     *
     * @param  PrivacyManualRunRequest  $request  대기기간 생략 여부
     * @param  string  $target  대상 키
     * @return JsonResponse 건수 응답 (미등록 404 · 권한 부족 403)
     */
    public function preview(PrivacyManualRunRequest $request, string $target): JsonResponse
    {
        $runner = $this->registry->runner($target);

        if ($runner === null) {
            return $this->notFound('privacy.manual_run.unknown_target');
        }

        if (! $this->registry->canRun($runner, $request->user())) {
            return $this->forbidden('auth.permission_denied');
        }

        try {
            $preview = $this->service->preview($runner, $request->skipWaiting());
        } catch (Throwable $e) {
            Log::error('개인정보 조치 직권 실행 건수 조회 실패', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);

            return $this->error('common.failed', 500);
        }

        return $this->success('common.success', [
            'key' => $target,
            'label' => $runner->label(),
            ...$preview,
        ]);
    }

    /**
     * 직권 실행을 큐에 접수합니다.
     *
     * @param  PrivacyManualRunRequest  $request  대기기간 생략 여부
     * @param  string  $target  대상 키
     * @return JsonResponse 접수된 기록 (202 · 미등록 404 · 권한 부족 403 · 진행 중 작업 있음·꺼져 있어 실행할 수 없음 409)
     */
    public function store(PrivacyManualRunRequest $request, string $target): JsonResponse
    {
        $runner = $this->registry->runner($target);

        if ($runner === null) {
            return $this->notFound('privacy.manual_run.unknown_target');
        }

        if (! $this->registry->canRun($runner, $request->user())) {
            return $this->forbidden('auth.permission_denied');
        }

        try {
            $run = $this->service->queue($runner, $request->skipWaiting(), $request->user());
        } catch (PrivacyManualRunConflictException $e) {
            return $this->error(PrivacyManualRunConflictException::MESSAGE_KEY, 409, [
                'run' => (new PrivacyManualRunResource($e->activeRun->loadMissing('requester:id,name')))->toArray($request),
            ]);
        } catch (PrivacyManualRunDisabledException $e) {
            // 진행 중 충돌과 달리 `errors.run` 이 없다 — 화면은 이 차이로 「기능을 켜고 저장하라」 안내를 고른다.
            return $this->error(PrivacyManualRunDisabledException::MESSAGE_KEY, 409);
        } catch (Throwable $e) {
            Log::error('개인정보 조치 직권 실행 접수 실패', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);

            return $this->error('common.failed', 500);
        }

        return $this->success(
            'privacy.manual_run.queued',
            (new PrivacyManualRunResource($run->loadMissing('requester:id,name')))->toArray($request),
            202,
        );
    }
}
