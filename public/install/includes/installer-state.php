<?php

/**
 * G7 인스톨러 상태 관리 시스템
 *
 * 설치 진행 상태를 storage/installer-state.json에 저장하고 조회합니다.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); // public/install/includes에서 프로젝트 루트로
}

define('STATE_PATH', BASE_PATH . '/storage/installer-state.json');
define('INSTALLER_DIR', BASE_PATH . '/storage/installer');

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
    if (!file_exists(STATE_PATH)) {
        return $defaultState;
    }

    // 파일 읽기 권한 체크
    if (!is_readable(STATE_PATH)) {
        error_log("State file is not readable: " . STATE_PATH);
        return $defaultState;
    }

    // state.json 파일 읽기
    $content = @file_get_contents(STATE_PATH);

    // 파일 읽기 실패 시 기본 상태 반환
    if ($content === false) {
        error_log("Failed to read state file: " . STATE_PATH);
        return $defaultState;
    }

    $state = json_decode($content, true);

    // JSON 파싱 실패 시 기본 상태 반환 (재귀 호출 제거 - 무한 루프 방지)
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Failed to parse state file JSON: " . json_last_error_msg());
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
        error_log("Storage directory does not exist: {$storageDir}");
        return false;
    }

    // 디렉토리 쓰기 권한 확인
    if (!is_writable($storageDir)) {
        error_log("Storage directory is not writable: {$storageDir}");
        return false;
    }

    // last_updated 타임스탬프 업데이트
    $state['last_updated'] = date('Y-m-d\TH:i:s\Z');

    // JSON 형식으로 저장
    $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 파일 쓰기
    $result = @file_put_contents(STATE_PATH, $content);

    if ($result === false) {
        error_log("Failed to write state file: " . STATE_PATH);
        return false;
    }

    // 파일 권한 설정
    @chmod(STATE_PATH, 0664);

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
function addLog(string $message): bool
{
    $logDir = BASE_PATH . '/storage/logs';
    $logFile = $logDir . '/installation.log';

    // 로그 디렉토리 확인 및 생성 시도
    if (!is_dir($logDir)) {
        $created = @mkdir($logDir, 0775, true);
        if (!$created) {
            error_log("Failed to create log directory: {$logDir} (storage 권한 확인 필요)");
            return false;
        }
    }

    // 로그 디렉토리 쓰기 권한 확인
    if (!is_writable($logDir)) {
        error_log("Log directory is not writable: {$logDir}");
        return false;
    }

    // Windows에서 CP949 인코딩된 메시지를 UTF-8로 변환
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $encoding = mb_detect_encoding($message, ['UTF-8', 'EUC-KR', 'CP949'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $message = mb_convert_encoding($message, 'UTF-8', $encoding);
        }
    }

    // 타임스탬프와 함께 로그 작성
    $timestamp = date('Y-m-d H:i:s');
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
        error_log("Failed to open log file: {$logFile}");
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
        error_log("Failed to write log file: {$logFile}");
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
 * @return array 로그 배열 [{timestamp, message}, ...]
 */
function getInstallationLogs(): array
{
    $logFile = BASE_PATH . '/storage/logs/installation.log';

    // 파일 캐시 초기화 (최신 데이터 읽기 보장)
    clearstatcache(true, $logFile);

    // 로그 파일이 없으면 빈 배열 반환
    if (!file_exists($logFile)) {
        return [];
    }

    // 로그 파일 읽기
    $content = @file_get_contents($logFile);
    if ($content === false) {
        return [];
    }

    // 각 라인을 파싱
    $lines = explode("\n", trim($content));
    $logs = [];

    foreach ($lines as $line) {
        if (empty($line)) {
            continue;
        }

        // [timestamp] message 형식 파싱
        if (preg_match('/^\[(.+?)\] (.+)$/', $line, $matches)) {
            $logs[] = [
                'timestamp' => $matches[1],
                'message' => $matches[2],
            ];
        } else {
            // 형식이 맞지 않으면 전체를 메시지로
            $logs[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => $line,
            ];
        }
    }

    return $logs;
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
