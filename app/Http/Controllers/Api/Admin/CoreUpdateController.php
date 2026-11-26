<?php

namespace App\Http\Controllers\Api\Admin;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\Exceptions\CoreUpdateOperationException;
use App\Exceptions\PackageIntegrityException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\CoreUpdate\StartCoreUpdateRequest;
use App\Http\Requests\CoreUpdate\SubmitCoreUpdateSelectionRequest;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Services\CoreUpdateService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 — 코어 업데이트 (확인·변경사항 + 웹 단계 실행 W6).
 *
 * 단계 실행은 **한 요청이 한 단계**다. 화면이 `advance` 를 반복 호출하고, 서버는 매번
 * 다음 단계 하나만 수행한 뒤 상태를 돌려준다. 그 경계가 곧 R25(파일 교체 뒤 같은
 * 프로세스가 사라진 클래스를 참조해 fatal)의 방어선이다.
 *
 * `check`·`changelog` 는 설정 조회/수정 권한을 그대로 쓰고, **적용 경로 셋**만 신규
 * 권한 `core.system.update` 를 요구한다 (D4) — 설정을 고칠 수 있다는 것과
 * 코어를 갈아 끼울 수 있다는 것은 다른 등급이다.
 */
class CoreUpdateController extends AdminBaseController
{
    /**
     * CoreUpdateController 생성자.
     *
     * @param  CoreUpdateService  $coreUpdateService  코어 업데이트 서비스
     * @param  CoreUpdateOrchestrator  $orchestrator  웹 단계 실행 오케스트레이터
     */
    public function __construct(
        private CoreUpdateService $coreUpdateService,
        private CoreUpdateOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    /**
     * 코어 업데이트 가능 여부를 확인합니다.
     *
     * @return JsonResponse 확인 결과 (직전 custom 자리 사전 검사 중단 기록 포함), 확인 실패 시 422
     */
    public function checkForUpdates(): JsonResponse
    {
        $result = $this->coreUpdateService->checkForUpdates();

        if (! empty($result['check_failed'])) {
            return $this->error(
                'settings.core_update.check_failed',
                422,
                [
                    'reason' => $result['error'] ?? '',
                    'current_version' => $result['current_version'],
                    'github_url' => $result['github_url'],
                ]
            );
        }

        // 직전 업데이트 시도가 개발자 custom 자리 사전 검사로 멈췄으면 같은 새 버전에 한해 그 결과를 싣는다 —
        // 확인 결과 창이 「업데이트를 시작하지 않았습니다」 표를 보여 준다.
        $result['custom_preflight'] = $this->coreUpdateService->pendingCustomPreflight($result['latest_version'] ?? null);

        return $this->success(
            'settings.core_update.check_success',
            $result
        );
    }

    /**
     * 코어 변경사항을 조회합니다.
     *
     * @param  ChangelogRequest  $request  검증된 요청
     * @return JsonResponse 변경사항 응답
     */
    public function changelog(ChangelogRequest $request): JsonResponse
    {
        $changelog = $this->coreUpdateService->getChangelog(
            $request->validated('from_version'),
            $request->validated('to_version')
        );

        return $this->success(
            'settings.fetch_success',
            ['changelog' => $changelog]
        );
    }

    /**
     * 웹 업데이트의 현재 상태를 반환합니다 (W6 4상태의 근거).
     *
     * 진행 중인 실행이 없으면 `run` 이 null 이고, 화면은 ①/② 상태를 그린다.
     *
     * @return JsonResponse 상태 JSON 응답
     */
    public function status(): JsonResponse
    {
        try {
            return $this->success('settings.core_update.web.status_success', $this->withScreenHints(
                $this->orchestrator->status(),
            ));
        } catch (\Throwable $e) {
            return $this->error('settings.core_update.web.status_failed', 500, $e->getMessage());
        }
    }

    /**
     * 업데이트를 착수합니다 (아직 단계는 수행하지 않는다).
     *
     * @param  StartCoreUpdateRequest  $request  검증된 요청
     * @return JsonResponse 착수 후 상태
     */
    public function start(StartCoreUpdateRequest $request): JsonResponse
    {
        try {
            $status = $this->orchestrator->start(
                CoreUpdateOptions::fromValidated($request->validated()),
                $request->user()?->id,
            );

            return $this->success('settings.core_update.web.started', $this->withScreenHints($status));
        } catch (PackageIntegrityException $e) {
            // 미서명 배포본 확인 필요(409 — 같은 요청을 allow_unsigned=1 로 다시 보내면 계속)·서명 불일치·미신뢰 키(422), 7.2.0
            return $this->packageIntegrityError($e);
        } catch (CoreUpdateOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            return $this->error('settings.core_update.web.start_failed', 500, $e->getMessage());
        }
    }

    /**
     * 다음 단계를 한 번 수행합니다 (화면이 반복 호출).
     *
     * 점검 모드가 켜져 있으면 응답에 우회 쿠키를 실어 준다 — 그래야 운영자가 진행 중에도
     * 관리자 화면을 볼 수 있고, 실패로 멈췄을 때 복구 화면에 닿을 수 있다.
     *
     * @return JsonResponse 수행 후 상태
     */
    public function advance(): JsonResponse
    {
        try {
            $status = $this->orchestrator->advance();

            return $this->attachMaintenanceBypass(
                $this->success('settings.core_update.web.advanced', $this->withScreenHints($status)),
                $status,
            );
        } catch (CoreUpdateOperationException $e) {
            return $this->error($e->errorKey, 409, null, $e->params);
        } catch (\Throwable $e) {
            return $this->error('settings.core_update.web.advance_failed', 500, $e->getMessage());
        }
    }

    /**
     * 진행 중에 물은 선택을 받습니다 (7.2.1 — 번들 배치·번들 업데이트 항목과 확장별 레이아웃 전략).
     *
     * 받은 뒤 화면이 다시 단계를 부른다. 점검 모드 중에 불리므로 진행과 같이 우회 쿠키를 싣는다.
     *
     * @param  SubmitCoreUpdateSelectionRequest  $request  검증된 선택
     * @return JsonResponse 받은 뒤 상태
     */
    public function selection(SubmitCoreUpdateSelectionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $status = $this->orchestrator->submitSelection((string) $validated['kind'], (array) $validated['items']);

            return $this->attachMaintenanceBypass(
                $this->success('settings.core_update.web.selection_saved', $this->withScreenHints($status)),
                $status,
            );
        } catch (CoreUpdateOperationException $e) {
            return $this->error($e->errorKey, 409, null, $e->params);
        } catch (\Throwable $e) {
            return $this->error('settings.core_update.web.selection_failed', 500, $e->getMessage());
        }
    }

    /**
     * 끝난 실행의 기록을 지웁니다 (운영자가 결과를 확인한 뒤).
     *
     * @return JsonResponse 지운 뒤 상태
     */
    public function dismiss(): JsonResponse
    {
        try {
            return $this->success('settings.core_update.web.dismissed', $this->withScreenHints(
                $this->orchestrator->dismiss(),
            ));
        } catch (CoreUpdateOperationException $e) {
            return $this->error($e->errorKey, 409, null, $e->params);
        } catch (\Throwable $e) {
            return $this->error('settings.core_update.web.dismiss_failed', 500, $e->getMessage());
        }
    }

    /**
     * 화면이 착수 화면(②)에서 필요로 하는 부가 정보를 덧붙입니다.
     *
     * 수정된 레이아웃 집계는 진행 중일 때 재계산할 이유가 없다 — 그 값은 착수 전
     * 판단을 돕는 것이고, 매 단계 응답마다 확장 전수를 훑으면 진행이 느려진다.
     *
     * @param  array<string, mixed>  $status  오케스트레이터 상태
     * @return array<string, mixed> 화면용 상태
     */
    private function withScreenHints(array $status): array
    {
        if (($status['run'] ?? null) !== null) {
            return $status;
        }

        try {
            $status['modified_layouts'] = $this->orchestrator->modifiedLayoutSummary();
        } catch (\Throwable) {
            // 집계 실패는 착수를 막을 사유가 아니다 — 안내 한 줄이 빠질 뿐이다.
            $status['modified_layouts'] = ['has_modified' => false, 'count' => 0, 'extensions' => []];
        }

        // 사전 조건(쓰기 권한·여유 공간)은 **누르기 전에** 화면에 보여야 한다 (D26 · W6
        // 「사전 조건 미충족」 상태). 실행 1단계에서만 재면 쓰기 권한이 없는 호스팅의
        // 운영자는 사이트를 점검 모드로 보낸 뒤에야 그 사실을 안다.
        try {
            $status['preflight'] = $this->orchestrator->preflightSummary();
        } catch (\Throwable) {
            // 진단 자체가 실패한 것은 「막혔다」와 다르다 — 판정 불능으로 두고 화면은
            // 그 사실을 표시하지 않는다(착수를 막지도 않는다). 진짜 차단은 1단계가 잡는다.
            $status['preflight'] = null;
        }

        return $status;
    }

    /**
     * 점검 모드 우회 쿠키를 응답에 싣습니다 (관리자 화면 접근 보장).
     *
     * 쿠키를 만들지 말지는 오케스트레이터가 판정한다 — 점검 모드를 켠 주체가 그쪽이고,
     * 컨트롤러가 `storage/framework/down` 을 직접 읽으면 그 사실이 두 곳에 적힌다.
     *
     * @param  JsonResponse  $response  원 응답
     * @param  array<string, mixed>  $status  오케스트레이터 상태
     * @return JsonResponse 쿠키가 실린 응답
     */
    private function attachMaintenanceBypass(JsonResponse $response, array $status): JsonResponse
    {
        if (($status['run'] ?? null) === null) {
            return $response;
        }

        $cookie = $this->orchestrator->maintenanceBypassCookie();

        return $cookie === null ? $response : $response->withCookie($cookie);
    }
}
