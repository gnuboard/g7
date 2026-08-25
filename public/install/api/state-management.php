<?php

/**
 * G7 인스톨러 - 설치 상태 관리 통합 API
 *
 * 설치 상태 조회, 초기화, 중단 기능을 하나의 API로 통합
 *
 * 엔드포인트:
 * - GET  ?action=get    : 현재 설치 상태 조회 (action 생략 시 기본값)
 * - POST ?action=reset  : 설치 상태 초기화 (Step 3로 이동)
 * - POST ?action=abort  : 설치 중단 (현재 작업만 롤백)
 * - POST ?action=skip_task : 실패한 선택 확장 태스크를 건너뛰고 재개 가능 상태로 (W3)
 */

/**
 * 설치 상태 관리 API 클래스
 */
class StateManagementApi
{
    /**
     * 요청 처리 메인 메서드
     */
    public function handleRequest(): void
    {
        // JSON 헤더 설정
        $this->setJsonHeaders();

        // HTTP 메서드 및 action 파라미터 확인
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? ($method === 'GET' ? 'get' : null);

        // 상태를 바꾸는 액션은 출처를 확인한다 (13차 보안#4). 인스톨러에는 csrf_token 검증이
        // 없고(헬퍼는 정의만 있었다) 세션 쿠키는 SameSite=Lax 라, 다른 사이트의 자동 제출 폼
        // 하나로 [중단](진행 중 태스크 롤백)·[초기화](완료 기록 전소거)를 부를 수 있었다.
        // 커스텀 헤더 요구는 방어가 아니다 — 단순 폼 제출에는 헤더가 필요 없다.
        if (in_array($action, ['reset', 'abort', 'skip_task'], true)) {
            // 끝난 설치의 중단·초기화·건너뛰기는 성립하지 않는다 (13차 보안#8).
            // `action=get` 은 대상이 아니다 — 완료 화면 전환이 그 폴링 한 번에 달려 있다.
            installer_guard_post_complete_or_410();

            $originRejection = installerCrossOriginRejection($_SERVER);
            if ($originRejection !== null) {
                http_response_code($originRejection['status']);
                echo installer_json_encode([
                    'success' => false,
                    'message' => lang($originRejection['message_key']),
                    'message_key' => $originRejection['message_key'],
                ], JSON_UNESCAPED_UNICODE);

                return;
            }
        }

        try {
            // action에 따라 적절한 메서드 호출
            match ($action) {
                'get' => $this->getState(),
                'reset' => $this->resetState(),
                'abort' => $this->abortInstallation(),
                'skip_task' => $this->skipFailedTask(),
                default => $this->error400('Invalid action parameter'),
            };
        } catch (Throwable $e) {
            // Throwable 로 받는다 — 이 엔드포인트는 폴링이 1초 간격으로 때리고 헤더가 이미
            // application/json 이라, Error/TypeError 가 새어 나가면 JSON 아닌 본문이 200 으로
            // 나가 프론트의 res.json() 이 폭주한다.
            $this->error500($e);
        }
    }

    /**
     * JSON 응답 헤더 설정
     */
    private function setJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * GET ?action=get
     * 현재 설치 상태 조회
     *
     * JavaScript 폴링(1초 간격)으로 호출되어 실시간 진행 상황을 업데이트합니다.
     */
    private function getState(): void
    {
        // GET 메서드만 허용
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo installer_json_encode([
                'error' => 'Method Not Allowed',
                'message' => lang('error_get_method_required'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // state.json 파일 실제 존재 여부 (getInstallationState는 부재 시 DEFAULT_INSTALLATION_STATE를 반환하므로
        // isset($state['installation_status']) 로는 "기본 상태"와 "실제 파일 상태"를 구분할 수 없다)
        $stateFileExists = file_exists(STATE_PATH);
        $installedFlagPath = BASE_PATH.'/storage/app/g7_installed';

        // 현재 설치 상태 조회
        $state = getInstallationState();

        // installation_status 값을 status로 매핑
        $status = 'pending';

        if (! $stateFileExists && file_exists($installedFlagPath)) {
            // state.json 삭제 + g7_installed 플래그 존재 → 설치 완료 후 정리된 상태
            // 폴링이 완료 시점을 놓치면 이후 주기에서 이 분기로 completed 전환
            $status = 'completed';
        } elseif ($stateFileExists && isset($state['installation_status'])) {
            switch ($state['installation_status']) {
                case 'not_started':
                case 'ready':  // ready도 pending으로 매핑 (설치 대기 상태)
                    $status = 'pending';
                    break;
                case 'running':
                    $status = 'running';
                    break;
                case 'completed':
                    $status = 'completed';
                    break;
                case 'failed':
                    $status = 'failed';
                    break;
                case 'aborted':
                    $status = 'aborted';
                    break;
                default:
                    $status = $state['installation_status'];
            }
        }

        // 폴링 모드 증분 조회 지원 (?log_offset=N)
        $logOffset = isset($_GET['log_offset']) ? max(0, (int) $_GET['log_offset']) : 0;

        // 로그 파일에서 로그 읽기 (offset 이후만)
        $logs = getInstallationLogs($logOffset);
        $logTotal = getInstallationLogCount();

        // API 응답 형식으로 변환
        $response = array_merge([
            'status' => $status,
            'current_step' => $state['current_step'] ?? 0,
            'logs' => $logs,
            'log_total' => $logTotal,
            'error' => $state['error'] ?? null,
            'last_updated' => $state['last_updated'] ?? null,
        ],
            // 실패 정보 (새로고침 후에도 표시용) — 단계별 실행 응답과 같은 집합을
            // 공용 SSoT 가 만든다 (installer-state.php::installerClientFailureFields)
            installerClientFailureFields($state),
            [

                // SSE/폴링/단계별 진행 모드
                'installation_mode' => $state['installation_mode'] ?? 'sse',
                // 설치 시작 시 확정된 실행 모드 (subprocess|inprocess). 화면은 서버 렌더 값으로
                // 분기하므로 이 필드를 읽지 않는다 — 진단·실측용이다.
                'execution_mode' => $state['execution_mode_resolved'] ?? null,
            ]);

        // JSON 응답 반환
        //
        // 최종 안전망 (gnuboard/g7#62): $response 의 로그·경로에 invalid UTF-8 바이트가
        // 섞이면 표준 json_encode 는 false 를 반환하고, echo false = 빈 본문(HTTP 200) 이 되어
        // 프론트 폴링(res.json())이 "Unexpected end of JSON input" 으로 폭주한다.
        //
        // installer_json_encode 는 값을 정규화하고 substitute 를 적용하며,
        // 실패하더라도 파싱 가능한 오류 JSON 을 돌려주므로 빈 본문이 나갈 수 없다.
        //
        // 폴백에도 `status`/`logs`/`log_total` 을 실어 보낸다. 프론트 PollingMonitor 는
        // `state.status` 로 진행/완료/실패를 판정하므로, 그 키가 없는 응답은 파싱은 되지만
        // 아무 전이도 일으키지 못해 화면이 조용히 멈춘 것처럼 보인다(파싱 실패 카운터도
        // 걸리지 않는다). 도달 확률과 무관하게 소비자 계약을 폴백에서도 유지한다.
        echo installer_json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ['status' => $status, 'logs' => [], 'log_total' => $logTotal]
        );
    }

    /**
     * POST ?action=reset
     * 설치 상태 초기화 및 적절한 Step으로 이동
     *
     * 설치 상태를 초기화하여 설정 페이지로 돌아갈 수 있도록 합니다.
     * DB 롤백은 수행하지 않습니다 (사용자가 수동으로 DB 정리 필요).
     * (마이그레이션 실패 시 자동 롤백은 install-worker.php에서 처리)
     */
    /**
     * 단계별 실행 중인 설치의 상태 변경 요청에 토큰을 요구한다 (10차 #M10).
     *
     * 단계별 실행은 세션이 아니라 발급 토큰으로 진행한다(R8) — 그래서 이 파일의 상태 변경
     * 액션은 전부 같은 토큰을 요구해야 한다. 건너뛰기 한 곳에만 걸어 두면, **그보다 파괴적인**
     * 중단(진행 중 태스크 롤백 → `migrate:rollback`)과 초기화(`completed_tasks` 전소거)가
     * 무인증으로 남는다. 게이트를 세 곳에 복제하지 않고 한 지점이 소유한다 — 복제하면
     * 한 곳만 빠져도 그 액션이 우회로가 된다.
     *
     * 통과하지 못하면 403 으로 응답하고 종료한다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     */
    private function requireStepwiseTokenOrReject(array $state): void
    {
        if (! installerRequiresStepwiseToken($state)) {
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        $presented = (string) ($_SERVER['HTTP_X_INSTALLER_TOKEN'] ?? ((is_array($body) ? $body['token'] : null) ?? ''));
        $expected = (string) (readInstallerRuntime()['installer']['stepwise_token'] ?? '');

        // 사유를 나눈다 (11차 #A10) — 판정은 execution-mode.php 가 소유한다.
        $rejection = installerStepwiseTokenRejection($presented, $expected);
        if ($rejection === null) {
            return;
        }

        http_response_code($rejection['status']);
        echo installer_json_encode([
            'success' => false,
            'message' => lang($rejection['message_key']),
            'message_key' => $rejection['message_key'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function resetState(): void
    {
        // POST 메서드만 허용
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo installer_json_encode([
                'success' => false,
                'message' => 'Only POST method is allowed',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 현재 상태 조회
        $state = getInstallationState();

        // 파손된 상태에서는 이 뒤의 저장이 거부되어(13차 #D13) 초기화가 500 으로 끝난다 —
        // 그러면 운영자에게 남는 조작이 없다. 초기화는 진행분을 **의도적으로 버리는** 유일한
        // 조작이므로 여기서만 파손 원본을 치운다(사본은 남는다). 다른 경로는 종전대로 거부된다.
        if (installerStateIsCorrupt() && installerDiscardCorruptState()) {
            addLog(lang('state_reset_discarded_corrupt'));
            $state = getInstallationState();
        }

        $this->requireStepwiseTokenOrReject($state);

        // 항상 Step 4 (확장 선택)로 이동
        $targetStep = 4;

        addLog(lang('state_reset_requested'));
        addLog(lang('state_reset_db_notice'));

        // 초기화의 읽기·수정·쓰기를 배타 구간 안에서 한다 (17차 #C12). 초기화는 진행분을
        // 의도적으로 버리지만 `config` 는 **보존**하므로, 구간 밖에서 읽은 스냅샷을 쓰면 그
        // 사이 워커가 갱신한 설정(예: composer 가용성 판정)이 조용히 사라진다. 구간 안에서
        // 다시 읽어 최신 config 위에 초기화를 얹는다. 이 구간에는 SSE flush 가 없어
        // installerWithStateLock 의 경계 규칙(짧은 상태 조작만)에 부합한다.
        $saved = (bool) installerWithStateLock(static function () use ($state, $targetStep): bool {
            $fresh = getInstallationState();
            $state = (is_array($fresh) && $fresh !== []) ? $fresh : $state;

            // 상태 초기화 (DB 롤백 없이)
            $state['current_step'] = $targetStep;
            $_SESSION['installer_current_step'] = $targetStep;
            $state['step_status'][$targetStep] = 'pending';
            $state['installation_status'] = 'not_started';
            $state['completed_tasks'] = [];
            $state['current_task'] = null;
            $state['error'] = null;
            $state['failed_task'] = null;
            $state['failed_task_target'] = null;
            $state['error_message_key'] = null;
            $state['error_message_params'] = null;
            $state['error_detail'] = null;
            $state['rollback_failure'] = null;
            $state['manual_commands'] = null;
            // 진행 표시에 쓰이는 잔여 목록도 함께 비운다 — 남겨 두면 초기화 직후 조회 응답이
            // 그 값을 그대로 실어 화면이 이미 없어진 "건너뜀" 을 보여 준다 (install-process 가
            // 다음 요청에서 덮어쓰기 전까지). 재시작 경로가 비우는 키와 같은 집합이다.
            $state['failed_task_skippable'] = false;
            $state['skipped_tasks'] = [];
            $state['best_effort_failed_tasks'] = [];
            // 의존성 경고도 이 집합에 속한다 (11차 #A8) — 남기면 다음 설치의 첫 건너뛰기에서
            // **이전 설치의** 경고가 진행 기록에 다시 나타난다.
            $state['dependency_skip_warnings'] = [];
            // 마이그레이션 기준선도 이 집합에 속한다 (19차 R2). 이 값은 「이번 실행 직전의 최대
            // 배치」라 실행이 바뀌면 뜻을 잃는데, `installerRecordMigrationBatchBaseline()` 은
            // 값이 있으면 다시 기록하지 않는다. 이월하면 **이전 회전의 기준선**으로 다음 회전의
            // `--step` 이 계산되어, 그 사이 운영자가 DB 를 바꾸거나 유지했을 때 이번 실행이
            // 만들지도 않은 배치가 롤백 범위에 든다.
            $state['db_migrate_batch_baseline'] = null;

            // 이 실행은 더 이상 유효하지 않다 — 죽은 워커의 잔존 점유(와 그 요청이 선언한
            // 시간 예산)를 남겨 두면 이후 재시작이 최대 10분간 busy 로 튕겨 복구 수단이 사라진다.
            $state = clearWorkerLock($state);

            $state['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');

            // 보존되는 config 에서 비밀 제거 (이슈 #465) — 초기화 후 무기한 잔존 가능하므로
            // 평문이 남지 않아야 한다. 비밀번호는 runtime.php 에 있고, 재설치 시 Step 3
            // 재입력 또는 runtime 폴백으로 복원된다.
            $state = redactInstallationStateSecrets($state);

            // 상태 저장
            return installerSaveStateOrReport($state, 'state_reset');
        });

        if (! $saved) {
            throw new Exception(lang('state_save_failed'));
        }

        addLog(lang('state_reset_completed', ['step' => $targetStep]));

        // 성공 응답
        http_response_code(200);

        echo installer_json_encode([
            'success' => true,
            'message' => lang('state_reset_completed', ['step' => $targetStep]),
            'target_step' => $targetStep,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?action=abort
     * 설치 중단 (현재 작업만 롤백)
     *
     * 사용자가 명시적으로 설치 중단 버튼을 클릭했을 때 호출됩니다.
     * 현재 진행 중인 작업만 롤백하고, 완료된 작업은 유지합니다.
     */
    private function abortInstallation(): void
    {
        // POST 메서드만 허용
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo installer_json_encode([
                'success' => false,
                'message' => lang('api_method_not_allowed'),
            ]);
            exit;
        }

        // 설치 상태 가져오기 — 게이트가 먼저다. 로그를 앞에 두면 거부된 요청도 진행 기록을 남긴다.
        $state = getInstallationState();

        $this->requireStepwiseTokenOrReject($state);

        addLog(lang('abort_api_requested'));

        // 디버그: 현재 state 상태 로깅
        addLog(lang('abort_api_current_status', ['status' => $state['installation_status'] ?? 'null']));
        // 태스크는 표시 이름으로 — 진행 기록의 독자는 운영자다 (§12.0 W3). 확장 태스크의
        // current_task 는 "module_install:sirsoft-board" 같은 합성 키라 그대로 쓰면 노출된다.
        addLog(lang('abort_api_current_task', [
            'task' => isset($state['current_task'])
                ? installerTaskKeyDisplayName((string) $state['current_task'])
                : 'null',
        ]));
        addLog(lang('abort_api_completed_count', ['count' => count($state['completed_tasks'] ?? [])]));

        // 설치가 이미 완료된 경우
        if (isset($state['installation_status']) && $state['installation_status'] === 'completed') {
            addLog(lang('abort_api_already_completed'));
            echo installer_json_encode([
                'success' => false,
                'message' => lang('abort_api_already_completed'),
            ]);
            exit;
        }

        // 이미 중단된 경우 - 멱등성 보장 (재진입 시 400 에러 방지)
        if (isset($state['installation_status']) && $state['installation_status'] === 'aborted') {
            addLog(lang('abort_api_already_aborted'));
            echo installer_json_encode([
                'success' => true,
                'message' => lang('abort_api_already_aborted'),
            ]);
            exit;
        }

        // 설치가 진행 중이 아닌 경우
        if (! isset($state['installation_status']) || $state['installation_status'] !== 'running') {
            addLog(lang('abort_api_not_running_log', ['status' => $state['installation_status'] ?? 'null']));
            echo installer_json_encode([
                'success' => false,
                'message' => lang('abort_api_not_running'),
            ]);
            exit;
        }

        addLog(lang('abort_user_requested'));

        // 세션 잠금 해제 — 아래 구간은 **확정 대기(최대 15초) + DB 롤백**으로 수 초~수십 초
        // 블로킹한다. PHP 기본 세션 핸들러는 스크립트가 끝날 때까지 세션 파일을 배타 잠그므로,
        // 닫지 않으면 같은 세션의 폴링 조회(`?action=get` — **이 파일의 다른 분기**)가 전부
        // `session_start()` 에서 줄을 선다. 화면은 오류 없이 마지막 진행률에 얼어붙고, 90초를
        // 넘기면 폴링의 정지 감지가 「응답이 없습니다」라는 **틀린 사유**로 설치를 끝낸다.
        //
        // 형제 지점 `install-process.php` 가 인라인 워커 앞에서 같은 이유로 같은 조치를 한다.
        // 13차 결정 3 이 이 분기에 확정 대기를 넣으면서 그 조치를 승계하지 않은 자리다 (17차 #B1).
        // 이 분기는 `$_SESSION` 에 쓰지 않는다(쓰기는 reset 분기 1곳뿐)이라 닫아도 안전하다.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // ── 되돌리기 전에 「작업이 물러날 때까지」 기다린다 (PO 결정 13차 — 11차 이월분) ──
        //
        // 워커는 태스크 **경계에서만** 중단을 확인한다. 그래서 종전처럼 되돌리기를 즉시
        // 부르면, 아직 실행 중인 바로 그 태스크와 되돌리기가 겹칠 수 있었다.
        //
        // 순서: ① 중단 표식을 먼저 기록해 워커가 물러날 기회를 준다 → ② 실제로 물러났는지
        // 확인한다 → ③ 되돌리기. 표식 기록은 워커 lock 흔적을 남긴다(그 흔적이 「물러났는가」의
        // 판정 근거다) — 흔적 정리는 아래 installerApplyAbortedState() 가 맡는다.
        installerMarkAbortRequested();

        // 대기 상한은 이 요청에 **실제로 적용된** 시간 예산에서 도출한다 (환경#9 와 같은 규율) —
        // 대기가 요청 수명을 다 써 버리면 되돌리기가 시작되기도 전에 응답이 끊긴다.
        $abortTimeBudget = installerSetTimeLimit(INSTALLER_ABORT_REQUEST_TIME_LIMIT);
        $abortWaitCap = installerAbortWaitCapSeconds($abortTimeBudget);
        $abortWait = installerWaitForWorkerToRetreat($abortWaitCap);

        // 상한을 넘겨도 **기다리지 않고 진행한다** — 중단은 어떤 경우에도 완료되어야 한다.
        // 11차가 이 축을 좁히려다 철회한 이유가 「탈출구가 막히는 것」이었으므로, 겹칠 수
        // 있다는 사실만 진행 기록에 남기고 되돌리기는 반드시 수행한다.
        addLog($abortWait['retreated']
            ? lang('abort_api_worker_retreated', ['seconds' => number_format($abortWait['waited_ms'] / 1000, 1)])
            : lang('abort_api_worker_wait_timeout', ['seconds' => (string) $abortWaitCap]));

        // 되돌리기 대상은 **기다린 뒤 다시 읽은** 상태가 정한다. 대기 중에 워커가 그 태스크를
        // 끝냈을 수 있고, 진입 시 스냅샷을 그대로 쓰면 이미 완료된 작업을 되돌린다
        // (rollbackCurrentTask 의 completed_tasks 검사가 옛 목록을 보게 된다).
        $state = getInstallationState();

        // 현재 진행 중인 작업만 롤백 (완료된 작업은 유지)
        // 결과는 rollbackCurrentTask()에서 로깅됨
        $rollbackResult = rollbackCurrentTask($state);

        // 설치 상태를 'aborted'로 변경 (재로드 · 워커 lock 흔적 정리 · 비밀 제거 —
        // installer-state.php SSoT). completed_tasks는 유지 (이미 완료된 작업은 롤백하지 않음).
        //
        // 위 롤백은 수 초 블로킹이고 그동안 워커가 완료 마커·건너뜀·best-effort 기록을 쓴다 —
        // 진입 시 스냅샷($state)에 중단 표식만 얹어 저장하면 그 기록이 통째로 사라진다 (12차 #A1).
        addLog(lang('abort_api_status_change'));

        // 롤백 실패 정보 저장 (새로고침 후에도 표시용)
        $rollbackFailure = (isset($rollbackResult['success']) && ! $rollbackResult['success'])
            ? [
                'task' => $rollbackResult['task'] ?? null,
                'message' => $rollbackResult['message'] ?? null,
                'message_key' => 'failed_rollback_manual_cleanup',
                'detail_key' => 'failed_rollback_manual_cleanup_detail',
            ]
            : null;
        // 세션도 Step 5를 유지 (중단 화면 표시)

        $saveResult = installerFinalizeAbortedState(
            installerApplyAbortedState(getInstallationState()),
            $rollbackFailure,
        );
        addLog(lang('abort_api_save_result', ['result' => $saveResult ? 'success' : 'failed']));

        // 저장 후 실제 state.json 확인
        $verifyState = getInstallationState();
        addLog(lang('abort_api_verify_status', ['status' => $verifyState['installation_status'] ?? 'null']));

        addLog(lang('abort_installation_stopped'));

        // 성공 응답 (리다이렉트 없음 - 현재 Step 5 유지)
        echo installer_json_encode([
            'success' => true,
            'message' => lang('abort_installation_stopped'),
        ]);
    }

    /**
     * POST ?action=skip_task
     * 실패한 태스크 건너뛰기 (W3 [건너뛰고 계속])
     *
     * 선택 확장(모듈·플러그인·사용자 템플릿·언어팩) 태스크만 허용된다. 건너뛴 태스크는
     * completed_tasks 에 실려 재개 시 지나가고, skipped_tasks 에 따로 기록되어 화면에
     * "건너뜀" 으로 표시된다. 재개 자체는 클라이언트가 install-process.php 로 다시 시작한다.
     */
    private function skipFailedTask(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo installer_json_encode([
                'success' => false,
                'message' => lang('api_method_not_allowed'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $state = getInstallationState();

        $this->requireStepwiseTokenOrReject($state);

        $result = markInstallationTaskSkipped($state);

        http_response_code($result['success'] ? 200 : 400);
        echo installer_json_encode([
            'success' => $result['success'],
            'message' => lang($result['message_key']),
            'skipped' => $result['skipped'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 400 Bad Request 응답
     */
    private function error400(string $message): void
    {
        http_response_code(400);
        echo installer_json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 500 Internal Server Error 응답
     */
    private function error500(Throwable $e): void
    {
        // 에러 로깅
        if (function_exists('logInstallationError')) {
            logInstallationError(lang('error_state_management'), $e);
        } elseif (function_exists('addLog')) {
            addLog(lang('error_log_prefix', ['error' => $e->getMessage()]));
        }

        // 에러 응답 — 예외 원문은 싣지 않는다 (13차 보안#9). 이 엔드포인트는 인증이 없고
        // 폴링이 1초 간격으로 때린다: 원문에는 절대경로·DB 호스트명 같은 배치 정보가 섞이며,
        // `message` 는 화면이 제목으로 그리는 자리라 개발자 문구가 그대로 안내가 된다.
        // 원문은 위 로깅 채널에만 남는다.
        http_response_code(500);
        echo installer_json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'message' => lang('error_state_management'),
            'message_key' => 'error_state_management',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========================================
// 실행 부분
// ========================================

// 단계별 실행 토큰 판정(installerStepwiseTokenAccepted)의 출처 — skip_task 가 run-task.php 와
// 같은 토큰을 요구하므로 판정도 같은 지점을 쓴다. 인라인 사본을 두면 그 사본의 hash_equals 가
// `==` 로 바뀌어도 어떤 테스트도 red 가 되지 않는다(게이트는 run-task.php 만 본다).
// 최상위 실행문이 없고 BASE_PATH 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 본문에 섞이지 않게 한다 — **include 체인·가드보다 앞** (12차 #A6 · 21차 N4).
// 이 엔드포인트는 폴링이 1초 간격으로 때리고 응답은 JSON 계약이라, warning 한 줄이 본문 앞에
// 붙으면 프론트의 res.json() 이 매 초 throw 한다.
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

// 필수 파일 로드 (config.php가 BASE_PATH를 정의함)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/installer-state.php';
require_once __DIR__.'/../includes/installer-runtime.php';

// 롤백 함수 로드 (reset, abort 액션에서 필요)
require_once __DIR__.'/rollback-functions.php';

// 설치 완료 후 진입 차단 (KVE-2026-1056)
// finalize 전용 가드 — `.env` 의 INSTALLER_COMPLETED=true 단독으로만 차단한다.
// 일반 가드(installer_guard_or_410)는 `g7_installed` 락 파일도 차단 사유로 삼는데,
// 그 락 파일은 마지막 task `complete_flag` 가 먼저 생성한다. 그 직후에도 본 엔드포인트의
// action=get 폴링(1초 간격)이 계속되어 `completed` 상태를 받아야 완료 화면으로 전환되므로,
// 락 파일 기준으로 차단하면 정상 설치가 "진행 중"에 고착되는 자가 차단 회귀가 발생한다.
// 완전 완료 신호인 `.env` 플래그를 기준으로 삼아 get/reset/abort 를
// 일괄 차단하면서 폴링 구간은 비파괴로 통과시킨다.
require_once __DIR__.'/_guard.php';
installer_guard_finalize_or_410();

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// API 인스턴스 생성 및 요청 처리
$api = new StateManagementApi;
$api->handleRequest();
