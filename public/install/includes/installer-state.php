<?php

/**
 * G7 인스톨러 상태 관리 시스템
 *
 * 설치 진행 상태를 storage/installer-state.json에 저장하고 조회합니다.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); // public/install/includes에서 프로젝트 루트로
}

if (! defined('STATE_PATH')) {
    define('STATE_PATH', BASE_PATH . '/storage/installer-state.json');
}
if (! defined('INSTALLER_DIR')) {
    define('INSTALLER_DIR', BASE_PATH . '/storage/installer');
}

/**
 * 설치 상태 조회
 *
 * @return array 설치 상태 배열
 */
function getInstallationState(): array
{
    // 기본 상태 가져오기 (config.php에서 정의)
    $defaultState = DEFAULT_INSTALLATION_STATE;

    // 런타임 타임스탬프 추가
    $defaultState['last_updated'] = date('Y-m-d\TH:i:s\Z');

    // 파일 상태 캐시 초기화 (다른 프로세스의 변경 감지를 위해)
    clearstatcache(true, STATE_PATH);

    // state.json 파일이 없으면 기본 상태 반환
    // (설치 완료 후 워커가 DELETE_INSTALLER_AFTER_COMPLETE로 정상 삭제하는 경우가 있으므로
    //  부재 자체는 에러가 아님. 호출자가 g7_installed 플래그 등으로 완료 여부 판정)
    if (!file_exists(STATE_PATH)) {
        return $defaultState;
    }

    // 파일 읽기 권한 체크
    if (!is_readable(STATE_PATH)) {
        $msg = "[installer-state] State file is not readable: " . STATE_PATH;
        error_log($msg);
        addLog($msg);
        return $defaultState;
    }

    // state.json 파일 읽기
    $content = @file_get_contents(STATE_PATH);

    // 파일 읽기 실패 시 기본 상태 반환
    if ($content === false) {
        $msg = "[installer-state] Failed to read state file: " . STATE_PATH;
        error_log($msg);
        addLog($msg);
        return $defaultState;
    }

    $state = json_decode($content, true);

    // JSON 파싱 실패 시 기본 상태 반환 (재귀 호출 제거 - 무한 루프 방지)
    if (json_last_error() !== JSON_ERROR_NONE) {
        $contentLen = strlen($content);
        $preview = substr($content, 0, 200);
        $msg = "[installer-state] Failed to parse state file JSON (length={$contentLen}): " . json_last_error_msg() . " / preview: " . $preview;
        error_log($msg);
        addLog($msg);
        return $defaultState;
    }

    return $state;
}

/**
 * 설치 상태 저장
 *
 * @param array $state 저장할 상태 배열
 * @return bool 저장 성공 여부
 */
function saveInstallationState(array $state): bool
{
    // storage 디렉토리 존재 여부 확인 (생성하지 않음)
    $storageDir = BASE_PATH . '/storage';
    if (!is_dir($storageDir)) {
        $msg = "[installer-state] Storage directory does not exist: {$storageDir}";
        error_log($msg);
        addLog($msg);
        return false;
    }

    // 디렉토리 쓰기 권한 확인
    if (!is_writable($storageDir)) {
        $msg = "[installer-state] Storage directory is not writable: {$storageDir}";
        error_log($msg);
        addLog($msg);
        return false;
    }

    // last_updated 타임스탬프 업데이트
    $state['last_updated'] = date('Y-m-d\TH:i:s\Z');

    // JSON 형식으로 저장
    $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($content === false) {
        $msg = "[installer-state] Failed to encode state as JSON: " . json_last_error_msg();
        error_log($msg);
        addLog($msg);
        return false;
    }

    // 원자적 쓰기: tmp 파일에 쓰고 rename으로 교체
    // 동시 쓰기 경쟁 상황에서 부분 쓰기(half-written file)로 인한 JSON 손상 방지.
    // Linux에서 rename()은 atomic syscall이므로 다른 리더가 항상 완전한 파일만 읽는다.
    $tmpPath = STATE_PATH . '.tmp.' . getmypid() . '.' . uniqid();

    $result = @file_put_contents($tmpPath, $content, LOCK_EX);
    if ($result === false || $result !== strlen($content)) {
        $msg = "[installer-state] Failed to write state tmp file: " . $tmpPath;
        error_log($msg);
        addLog($msg);
        @unlink($tmpPath);
        return false;
    }

    @chmod($tmpPath, 0664);

    if (!@rename($tmpPath, STATE_PATH)) {
        $msg = "[installer-state] Failed to rename state tmp file: " . $tmpPath . ' → ' . STATE_PATH;
        error_log($msg);
        addLog($msg);
        @unlink($tmpPath);
        return false;
    }

    return true;
}

/**
 * 설치 완료 여부 확인
 *
 * @return bool 설치 완료 여부
 */
function isInstallationCompleted(): bool
{
    $state = getInstallationState();

    // installation_status가 completed인지 확인
    if (isset($state['installation_status']) && $state['installation_status'] === 'completed') {
        return true;
    }

    // 또는 current_step이 5(완료 단계)이고 step_status[5]가 completed인지 확인
    if (isset($state['current_step']) && $state['current_step'] >= 5) {
        if (isset($state['step_status']['5']) && $state['step_status']['5'] === 'completed') {
            return true;
        }
    }

    // g7_installed 파일 존재 여부 확인 (추가 안전장치)
    $installedFlagPath = BASE_PATH . '/storage/app/g7_installed';
    if (file_exists($installedFlagPath)) {
        return true;
    }

    // .env 파일의 INSTALLER_COMPLETED 플래그 확인
    $envPath = BASE_PATH . '/.env';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        if (strpos($envContent, 'INSTALLER_COMPLETED=true') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * 특정 작업을 완료로 표시
 *
 * @param string $task 작업 식별자
 * @return bool 업데이트 성공 여부
 */
function markTaskCompleted(string $task): bool
{
    $state = getInstallationState();

    // completed_tasks 배열에 추가 (중복 방지)
    if (!in_array($task, $state['completed_tasks'])) {
        $state['completed_tasks'][] = $task;
    }

    // 현재 작업이 완료된 작업과 같으면 초기화
    if ($state['current_task'] === $task) {
        $state['current_task'] = null;
        $state['current_task_name'] = null;
    }

    return saveInstallationState($state);
}

/**
 * 특정 작업을 완료 목록에서 제거
 *
 * @param string $task 작업 식별자
 * @return bool 업데이트 성공 여부
 */
function removeTaskCompleted(string $task): bool
{
    $state = getInstallationState();

    $state['completed_tasks'] = array_values(
        array_filter($state['completed_tasks'], fn($t) => $t !== $task)
    );

    return saveInstallationState($state);
}

/**
 * existing_db_action='drop_tables' 시 DB 관련 task 마커 일괄 재설정 가드.
 *
 * 'drop_tables' 동의는 "기존 DB 다 지우고 처음부터 다시 설치" 의도이므로,
 * 이전 시도에서 partial 진행된 db_cleanup / db_migrate / db_seed 의
 * completed 마커를 모두 제거하여 처음부터 재실행되도록 한다.
 *
 * 적용 시나리오:
 * - 첫 시도(skip) 실패 → 재시도(drop_tables): cleanup 마커 제거 → cleanup 재실행
 * - SSE 시작(drop_tables, cleanup 성공 + migrate 일부) → SSE 끊김 → 폴링
 *   재시도(drop_tables): cleanup + migrate 마커 모두 제거 → cleanup 다시 drop
 *   (이전 SSE 가 일부 만든 테이블 포함) → migrate 처음부터 → 1050 에러 회피
 *
 * 'skip' 신청이거나 액션이 없는 경우 마커 보존 (이미 진행한 결과 무효화 방지).
 *
 * @param  array<string, mixed>  $state  현재 인스톨러 state
 * @param  string  $newAction  요청에서 받은 신규 existing_db_action
 * @return array<string, mixed> 가드 적용된 state (existing_db_action 은 호출자가 별도 설정)
 */
/**
 * 워커 lock 획득 시도 (시나리오 B — 동시 실행 race 차단).
 *
 * SSE 워커가 backgroud 진행 중인 상태에서 폴링 워커가 진입하면 두 워커가
 * 동일 인스톨러 state + DB 를 동시 조작하여 cleanup → migrate race 발생.
 * state.active_worker_id + state.last_heartbeat 를 통해 동시 실행을 차단한다.
 *
 * 발동 조건:
 * - last_heartbeat 가 staleSeconds 이내면 다른 워커가 활동 중 → 거부
 * - staleSeconds 초과 또는 active_worker_id 부재 → takeover 또는 신규 획득
 *
 * @param  int  $staleSeconds  heartbeat 가 이 시간 이상 갱신 안 되면 stale 판정 (default 15초)
 * @return array{acquired: bool, worker_id: string|null, reason: string}
 *         reason: 'available' | 'takeover_stale' | 'busy'
 */
function acquireWorkerLock(int $staleSeconds = 15): array
{
    $state = getInstallationState();
    $now = time();

    $activeId = $state['active_worker_id'] ?? null;
    $lastHeartbeat = $state['last_heartbeat'] ?? 0;

    if ($activeId !== null && ($now - $lastHeartbeat) < $staleSeconds) {
        return ['acquired' => false, 'worker_id' => null, 'reason' => 'busy'];
    }

    $reason = $activeId !== null ? 'takeover_stale' : 'available';
    $newWorkerId = bin2hex(random_bytes(8));

    $state['active_worker_id'] = $newWorkerId;
    $state['last_heartbeat'] = $now;
    saveInstallationState($state);

    return ['acquired' => true, 'worker_id' => $newWorkerId, 'reason' => $reason];
}

/**
 * 워커 heartbeat 갱신 — 자기 worker_id 만 갱신 가능.
 *
 * task 진행 중 주기적으로 호출하여 lock 점유 유지. 다른 worker_id 가 active 면
 * 자기는 stale loser 이므로 false 반환 (호출자는 즉시 종료해야 함).
 *
 * @param  string  $workerId  자기 worker_id
 * @return bool 갱신 성공 (자기가 owner) 또는 실패 (다른 워커가 takeover)
 */
function refreshWorkerHeartbeat(string $workerId): bool
{
    $state = getInstallationState();
    $activeId = $state['active_worker_id'] ?? null;

    if ($activeId !== $workerId) {
        return false;
    }

    $state['last_heartbeat'] = time();
    saveInstallationState($state);

    return true;
}

/**
 * 워커 lock 해제 — 자기 worker_id 가 active 일 때만.
 *
 * 정상 종료 시 호출. 다른 워커가 takeover 했으면 무시 (자기가 점유자가 아니므로).
 *
 * @param  string  $workerId  자기 worker_id
 */
function releaseWorkerLock(string $workerId): void
{
    $state = getInstallationState();

    if (($state['active_worker_id'] ?? null) !== $workerId) {
        return;
    }

    unset($state['active_worker_id'], $state['last_heartbeat']);
    saveInstallationState($state);
}

function applyExistingDbActionStateGuard(array $state, string $newAction): array
{
    // 다른 워커가 활성 진행 중이면 state 변경 금지 — race 회피.
    // 시나리오: SSE 워커가 db_* 완료 후 module/plugin 진행 중인데 클라이언트가
    // 폴링 fallback 시도하면 install-process.php 가 다시 호출되어 본 가드를 통과.
    // 그 시점에 db_* 마커를 제거하면 SSE 워커가 다음 markTaskCompleted 시 read/write
    // 하면서 db_* 가 빠진 상태에 module_* 만 추가됨 → UI 에 DB pending 으로 잔존.
    // worker 가 살아있을 때는 그 워커의 state 진행을 신뢰하고 가드를 skip 한다.
    $activeId = $state['active_worker_id'] ?? null;
    $lastHeartbeat = $state['last_heartbeat'] ?? 0;
    $isOtherWorkerActive = $activeId !== null && (time() - (int) $lastHeartbeat) < 15;

    if ($newAction === 'drop_tables' && ! $isOtherWorkerActive) {
        // drop_tables 는 "처음부터 다시" — DB 관련 task 마커 일괄 제거.
        // SSE 가 cleanup + migrate 일부 진행 후 끊긴 상태에서 폴링 재진입 케이스를
        // 커버하려면 이전 액션이 drop_tables 였더라도 다시 reset 해야 한다.
        $resetTasks = ['db_cleanup', 'db_migrate', 'db_seed'];
        $state['completed_tasks'] = array_values(array_filter(
            $state['completed_tasks'] ?? [],
            fn($t) => !in_array($t, $resetTasks, true)
        ));
    } else {
        // 키 정규화 — completed_tasks 가 부재인 경우 빈 배열 보장
        $state['completed_tasks'] = $state['completed_tasks'] ?? [];
    }

    return $state;
}

/**
 * 현재 진행 중인 작업 업데이트
 *
 * @param string $task 작업 식별자
 * @return bool 업데이트 성공 여부
 */
function updateCurrentTask(string $task): bool
{
    $state = getInstallationState();

    $state['current_task'] = $task;
    // current_task_name은 저장하지 않음 (프론트엔드에서 task ID로 번역)

    return saveInstallationState($state);
}

/**
 * 설치 로그 추가 (별도 파일에 기록)
 *
 * 페이지 새로고침/재시작 시 로그가 즉시 표시되도록
 * fflush() + clearstatcache()를 적용합니다.
 *
 * @param string $message 로그 메시지
 * @return bool 저장 성공 여부
 */
/**
 * 다수의 로그 라인을 1회 fopen/flock/fwrite/fclose 로 일괄 기록합니다.
 *
 * artisan 명령 출력처럼 수백~수천 라인을 동시에 emit 해야 하는 경우 매 라인마다
 * addLog 를 호출하면 매번 file lock 을 잡았다 풀어 Windows 환경에서 수 초의
 * 지연이 발생하고, 이 지연 동안 polling 클라이언트의 fetch 가 connection_abort
 * 되어 워커가 강제 종료되는 회귀가 발생함. batch write 로 lock 1회만 잡는다.
 *
 * @param  array<int, string>  $messages  로그 메시지 배열 (빈 라인은 호출자가 사전 필터링)
 * @return bool 저장 성공 여부
 */
function addLogBatch(array $messages): bool
{
    if (empty($messages)) {
        return true;
    }

    $logDir = BASE_PATH . '/storage/logs';
    $logFile = $logDir . '/installation.log';

    if (!is_dir($logDir)) {
        $created = @mkdir($logDir, 0775, true);
        if (!$created) {
            $msg = "[addLogBatch] Failed to create log directory: {$logDir}";
            error_log($msg);
            addLog($msg); // 재귀 가드가 차단하므로 안전 — error_log fallback 만 수행
            return false;
        }
    }

    if (!is_writable($logDir)) {
        $msg = "[addLogBatch] Log directory is not writable: {$logDir}";
        error_log($msg);
        addLog($msg); // 재귀 가드가 차단
        return false;
    }

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $entries = '';
    foreach ($messages as $message) {
        if ($isWindows) {
            $encoding = mb_detect_encoding($message, ['UTF-8', 'EUC-KR', 'CP949'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $message = mb_convert_encoding($message, 'UTF-8', $encoding);
            }
        }
        $microtime = microtime(true);
        $ms = sprintf('%03d', (int) (($microtime - floor($microtime)) * 1000));
        $timestamp = date('Y-m-d H:i:s', (int) $microtime).'.'.$ms;
        $entries .= "[{$timestamp}] {$message}\n";
    }

    clearstatcache(true, $logFile);
    $isNewFile = !file_exists($logFile) || filesize($logFile) === 0;
    if ($isNewFile) {
        $entries = "\xEF\xBB\xBF" . $entries;
    }

    $handle = @fopen($logFile, 'a');
    if ($handle === false) {
        $msg = "[addLogBatch] Failed to open log file: {$logFile}";
        error_log($msg);
        addLog($msg); // 재귀 가드가 차단
        return false;
    }
    flock($handle, LOCK_EX);
    $result = fwrite($handle, $entries);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result !== false;
}

function addLog(string $message): bool
{
    // 무한 재귀 가드 — addLog 내부 폴백 경로에서 자기 자신을 다시 호출하면 안 된다.
    // installer-state.php / session.php / finalize-env.php 등에서 error_log 와 addLog 를
    // 병행 호출하는 경우, addLog 내부의 인프라 실패 폴백 (storage 쓰기 실패 등) 시
    // 호출자가 다시 addLog 를 부르면 같은 인프라가 다시 실패하여 무한 재귀가 발생할 수 있다.
    static $inAddLog = false;
    if ($inAddLog) {
        // 재진입: installation.log 시도 skip, PHP error_log 만 호출하고 즉시 종료
        error_log("[addLog reentrant] " . $message);
        return false;
    }
    $inAddLog = true;

    try {
        return _addLogInternal($message);
    } finally {
        $inAddLog = false;
    }
}

/**
 * addLog 의 실제 구현 — 무한 재귀 가드를 거친 후 호출된다.
 *
 * 본 함수 안에서는 절대 addLog() 를 호출하지 말 것 (무한 재귀 위험).
 * 내부 폴백은 error_log() 만 사용.
 *
 * @param  string  $message  기록할 메시지
 * @return bool 성공 여부
 */
function _addLogInternal(string $message): bool
{
    $logDir = BASE_PATH . '/storage/logs';
    $logFile = $logDir . '/installation.log';

    // 로그 디렉토리 확인 및 생성 시도
    if (!is_dir($logDir)) {
        $created = @mkdir($logDir, 0775, true);
        if (!$created) {
            $msg = "[addLog] Failed to create log directory: {$logDir} (storage 권한 확인 필요)";
            error_log($msg);
            addLog($msg); // 재귀 가드가 차단 (본 함수는 이미 가드 안)
            return false;
        }
    }

    // 로그 디렉토리 쓰기 권한 확인
    if (!is_writable($logDir)) {
        $msg = "[addLog] Log directory is not writable: {$logDir}";
        error_log($msg);
        addLog($msg); // 재귀 가드가 차단
        return false;
    }

    // Windows에서 CP949 인코딩된 메시지를 UTF-8로 변환
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $encoding = mb_detect_encoding($message, ['UTF-8', 'EUC-KR', 'CP949'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $message = mb_convert_encoding($message, 'UTF-8', $encoding);
        }
    }

    // 타임스탬프와 함께 로그 작성 — millisecond 정밀도 (hang 진단 시 정확한 timing 필요)
    $microtime = microtime(true);
    $ms = sprintf('%03d', (int) (($microtime - floor($microtime)) * 1000));
    $timestamp = date('Y-m-d H:i:s', (int) $microtime).'.'.$ms;
    $logEntry = "[{$timestamp}] {$message}\n";

    // 파일 캐시 초기화 (최신 상태 확인)
    clearstatcache(true, $logFile);

    // 파일이 없거나 빈 파일이면 UTF-8 BOM 추가 (Windows 텍스트 편집기 호환성)
    $isNewFile = !file_exists($logFile) || filesize($logFile) === 0;
    if ($isNewFile) {
        $utf8Bom = "\xEF\xBB\xBF";
        $logEntry = $utf8Bom . $logEntry;
    }

    // 파일 핸들 열기 (append 모드)
    $handle = @fopen($logFile, 'a');
    if ($handle === false) {
        $msg = "[addLog] Failed to open log file: {$logFile}";
        error_log($msg);
        addLog($msg); // 재귀 가드가 차단
        return false;
    }

    // 배타적 잠금
    flock($handle, LOCK_EX);

    // 쓰기
    $result = fwrite($handle, $logEntry);

    // 버퍼 플러시 (즉시 디스크에 기록)
    fflush($handle);

    // 잠금 해제 및 닫기
    flock($handle, LOCK_UN);
    fclose($handle);

    // Windows 파일 캐시 초기화 (최신 데이터 읽기 보장)
    clearstatcache(true, $logFile);

    if ($result === false) {
        $msg = "[addLog] Failed to write log file: {$logFile}";
        error_log($msg);
        addLog($msg); // 재귀 가드가 차단
        return false;
    }

    return true;
}

/**
 * 설치 로그 조회 (파일에서 읽기)
 *
 * 페이지 새로고침 시 최신 로그를 즉시 표시하기 위해
 * clearstatcache()를 적용합니다.
 *
 * @param int $offset 건너뛸 로그 줄 수 (폴링 모드 증분 조회용, 기본 0)
 * @return array 로그 배열 [{timestamp, message}, ...]
 */
function getInstallationLogs(int $offset = 0): array
{
    $logFile = BASE_PATH . '/storage/logs/installation.log';

    clearstatcache(true, $logFile);

    if (!file_exists($logFile)) {
        return [];
    }

    $content = @file_get_contents($logFile);
    if ($content === false) {
        return [];
    }

    $lines = explode("\n", trim($content));
    $logs = [];

    foreach ($lines as $line) {
        if (empty($line)) {
            continue;
        }

        if (preg_match('/^\[(.+?)\] (.+)$/', $line, $matches)) {
            $logs[] = [
                'timestamp' => $matches[1],
                'message' => $matches[2],
            ];
        } else {
            $logs[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => $line,
            ];
        }
    }

    // 증분 조회 (offset > 0이면 이전 결과 이후만 반환)
    if ($offset > 0 && $offset < count($logs)) {
        return array_slice($logs, $offset);
    } elseif ($offset >= count($logs)) {
        return [];
    }

    return $logs;
}

/**
 * 설치 로그 전체 줄 수 조회 (폴링 모드 offset 계산용).
 *
 * @return int 전체 로그 줄 수
 */
function getInstallationLogCount(): int
{
    $logFile = BASE_PATH . '/storage/logs/installation.log';

    clearstatcache(true, $logFile);

    if (!file_exists($logFile)) {
        return 0;
    }

    $content = @file_get_contents($logFile);
    if ($content === false) {
        return 0;
    }

    $lines = explode("\n", trim($content));
    $count = 0;
    foreach ($lines as $line) {
        if (!empty($line)) {
            $count++;
        }
    }
    return $count;
}

/**
 * 마지막 완료된 단계 조회
 *
 * @return int 마지막 완료된 단계 번호 (0-5)
 */
function getLastCompletedStep(): int
{
    $state = getInstallationState();

    // step_status를 역순으로 확인
    for ($step = 5; $step >= 0; $step--) {
        $stepKey = (string)$step;
        if (isset($state['step_status'][$stepKey]) && $state['step_status'][$stepKey] === 'completed') {
            return $step;
        }
    }

    // 완료된 단계가 없으면 -1 반환
    return -1;
}
