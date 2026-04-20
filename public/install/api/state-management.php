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

        try {
            // action에 따라 적절한 메서드 호출
            match ($action) {
                'get' => $this->getState(),
                'reset' => $this->resetState(),
                'abort' => $this->abortInstallation(),
                default => $this->error400('Invalid action parameter'),
            };
        } catch (Exception $e) {
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
            echo json_encode([
                'error' => 'Method Not Allowed',
                'message' => lang('error_get_method_required'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // state.json 파일 실제 존재 여부 (getInstallationState는 부재 시 DEFAULT_INSTALLATION_STATE를 반환하므로
        // isset($state['installation_status']) 로는 "기본 상태"와 "실제 파일 상태"를 구분할 수 없다)
        $stateFileExists = file_exists(STATE_PATH);
        $installedFlagPath = BASE_PATH . '/storage/app/g7_installed';

        // 현재 설치 상태 조회
        $state = getInstallationState();

        // installation_status 값을 status로 매핑
        $status = 'pending';

        if (!$stateFileExists && file_exists($installedFlagPath)) {
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
        $response = [
            'status' => $status,
            'current_step' => $state['current_step'] ?? 0,
            'current_task' => $state['current_task'] ?? null,
            'completed_tasks' => $state['completed_tasks'] ?? [],
            'logs' => $logs,
            'log_total' => $logTotal,
            'error' => $state['error'] ?? null,
            'last_updated' => $state['last_updated'] ?? null,

            // 실패 정보 (새로고침 후에도 표시용)
            'failed_task' => $state['failed_task'] ?? null,
            'error_message_key' => $state['error_message_key'] ?? null,
            'error_detail' => $state['error_detail'] ?? null,
            'rollback_failure' => $state['rollback_failure'] ?? null,
            'manual_commands' => $state['manual_commands'] ?? null,

            // SSE/폴링 듀얼 모드 지원
            'installation_mode' => $state['installation_mode'] ?? 'sse',
        ];

        // JSON 응답 반환
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * POST ?action=reset
     * 설치 상태 초기화 및 적절한 Step으로 이동
     *
     * 설치 상태를 초기화하여 설정 페이지로 돌아갈 수 있도록 합니다.
     * DB 롤백은 수행하지 않습니다 (사용자가 수동으로 DB 정리 필요).
     * (마이그레이션 실패 시 자동 롤백은 install-worker.php에서 처리)
     */
    private function resetState(): void
    {
        // POST 메서드만 허용
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Only POST method is allowed',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 현재 상태 조회
        $state = getInstallationState();

        // 항상 Step 4 (확장 선택)로 이동
        $targetStep = 4;

        addLog(lang('state_reset_requested'));
        addLog(lang('state_reset_db_notice'));

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
        $state['error_detail'] = null;
        $state['rollback_failure'] = null;
        $state['manual_commands'] = null;
        $state['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');

        // 상태 저장
        $saved = saveInstallationState($state);

        if (!$saved) {
            throw new Exception(lang('state_save_failed'));
        }

        addLog(lang('state_reset_completed', ['step' => $targetStep]));

        // 성공 응답
        http_response_code(200);

        echo json_encode([
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
            echo json_encode([
                'success' => false,
                'message' => lang('api_method_not_allowed'),
            ]);
            exit;
        }

        addLog(lang('abort_api_requested'));

        // 설치 상태 가져오기
        $state = getInstallationState();

        // 디버그: 현재 state 상태 로깅
        addLog(lang('abort_api_current_status', ['status' => $state['installation_status'] ?? 'null']));
        addLog(lang('abort_api_current_task', ['task' => $state['current_task'] ?? 'null']));
        addLog(lang('abort_api_completed_count', ['count' => count($state['completed_tasks'] ?? [])]));

        // 설치가 이미 완료된 경우
        if (isset($state['installation_status']) && $state['installation_status'] === 'completed') {
            addLog(lang('abort_api_already_completed'));
            echo json_encode([
                'success' => false,
                'message' => lang('abort_api_already_completed'),
            ]);
            exit;
        }

        // 이미 중단된 경우 - 멱등성 보장 (재진입 시 400 에러 방지)
        if (isset($state['installation_status']) && $state['installation_status'] === 'aborted') {
            addLog(lang('abort_api_already_aborted'));
            echo json_encode([
                'success' => true,
                'message' => lang('abort_api_already_aborted'),
            ]);
            exit;
        }

        // 설치가 진행 중이 아닌 경우
        if (! isset($state['installation_status']) || $state['installation_status'] !== 'running') {
            addLog(lang('abort_api_not_running', ['status' => $state['installation_status'] ?? 'null']));
            echo json_encode([
                'success' => false,
                'message' => lang('abort_api_not_running', ['status' => $state['installation_status'] ?? 'null']),
            ]);
            exit;
        }

        addLog(lang('abort_user_requested'));

        // 현재 진행 중인 작업만 롤백 (완료된 작업은 유지)
        // 결과는 rollbackCurrentTask()에서 로깅됨
        $rollbackResult = rollbackCurrentTask($state);

        // 설치 상태를 'aborted'로 변경
        addLog(lang('abort_api_status_change'));
        $state['installation_status'] = 'aborted';
        // current_step은 5를 유지 (중단된 화면을 보여주기 위해 - Step 5 = Installation)
        $state['current_task'] = null;
        // completed_tasks는 유지 (이미 완료된 작업은 롤백하지 않음)
        $state['aborted_at'] = date('Y-m-d H:i:s');
        $state['abort_reason'] = 'User requested';

        // 롤백 실패 정보 저장 (새로고침 후에도 표시용)
        if (isset($rollbackResult['success']) && !$rollbackResult['success']) {
            $state['rollback_failure'] = [
                'task' => $rollbackResult['task'] ?? null,
                'message' => $rollbackResult['message'] ?? null,
                'message_key' => 'failed_rollback_manual_cleanup',
                'detail_key' => 'failed_rollback_manual_cleanup_detail',
            ];
        }
        // 세션도 Step 5를 유지 (중단 화면 표시)

        $saveResult = saveInstallationState($state);
        addLog(lang('abort_api_save_result', ['result' => $saveResult ? 'success' : 'failed']));

        // 저장 후 실제 state.json 확인
        $verifyState = getInstallationState();
        addLog(lang('abort_api_verify_status', ['status' => $verifyState['installation_status'] ?? 'null']));

        addLog(lang('abort_installation_stopped'));

        // 성공 응답 (리다이렉트 없음 - 현재 Step 5 유지)
        echo json_encode([
            'success' => true,
            'message' => lang('abort_installation_stopped'),
        ]);
    }

    /**
     * 400 Bad Request 응답
     */
    private function error400(string $message): void
    {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 500 Internal Server Error 응답
     */
    private function error500(Exception $e): void
    {
        // 에러 로깅
        if (function_exists('logInstallationError')) {
            logInstallationError(lang('error_state_management'), $e);
        } elseif (function_exists('addLog')) {
            addLog(lang('error_log_prefix', ['error' => $e->getMessage()]));
        }

        // 에러 응답
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========================================
// 실행 부분
// ========================================

// 필수 파일 로드 (config.php가 BASE_PATH를 정의함)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/installer-state.php';

// 롤백 함수 로드 (reset, abort 액션에서 필요)
require_once __DIR__.'/rollback-functions.php';

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// API 인스턴스 생성 및 요청 처리
$api = new StateManagementApi;
$api->handleRequest();
