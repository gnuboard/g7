<?php

declare(strict_types=1);

/**
 * G7 인스톨러 공통 진입 가드
 *
 * 설치가 완료된 시스템에서는 인스톨러의 모든 비즈니스 로직 진입을 차단한다.
 * 운영 환경에서 인스톨러 노출형 취약점의 공격 표면을 영구 제거하기 위한
 * 코드 레벨 안전장치이며, 웹 서버 단의 /install 경로 차단(또는 디렉토리 삭제)
 * 이 1차 방어선이라는 점은 별도 운영 가이드로 안내한다.
 *
 * 판정 신호 (OR):
 *   1. {BASE_PATH}/storage/app/g7_installed 락 파일 존재
 *   2. .env 의 INSTALLER_COMPLETED=true (또는 1, yes)
 *
 * 둘 중 하나라도 참이면 HTTP 410 Gone 으로 즉시 종료.
 */
if (! function_exists('installer_resolve_base_path')) {
    function installer_resolve_base_path(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH;
        }

        // 진입점이 BASE_PATH 를 정의하기 전에 호출된 경우의 폴백.
        // 본 파일은 public/install/api/ 에 위치하므로 3단계 상위가 프로젝트 루트.
        return dirname(__DIR__, 3);
    }
}

if (! function_exists('installer_env_completed_flag')) {
    /**
     * .env 의 INSTALLER_COMPLETED 가 참인지 한 줄 단위로 읽어 판정한다.
     *
     * parse_ini_file 을 쓰지 않는다 — .env 는 INI 가 아니어서, 배포 템플릿의 `#` 주석에 괄호 같은
     * 문자가 있으면 파일 전체 파싱이 실패하고 판정이 "미완료" 로 떨어져 가드가 열린 채 남는다.
     *
     * @param  string  $envFile  .env 절대 경로
     * @return bool 완료 플래그가 참(true·1·yes, 따옴표·대소문자 무관)이면 true
     */
    function installer_env_completed_flag(string $envFile): bool
    {
        if (! is_file($envFile)) {
            return false;
        }

        $content = @file_get_contents($envFile);
        if (! is_string($content)) {
            return false;
        }

        if (! preg_match('/^[ \t]*INSTALLER_COMPLETED[ \t]*=(.*)$/m', $content, $matches)) {
            return false;
        }

        $flag = strtolower(trim(trim($matches[1]), "\"'"));

        return in_array($flag, ['true', '1', 'yes'], true);
    }
}

if (! function_exists('installer_is_completed')) {
    function installer_is_completed(): bool
    {
        $basePath = installer_resolve_base_path();

        $lockFile = $basePath.'/storage/app/g7_installed';
        if (is_file($lockFile)) {
            return true;
        }

        return installer_env_completed_flag($basePath.'/.env');
    }
}

if (! function_exists('installer_guard_or_410')) {
    function installer_guard_or_410(): void
    {
        if (! installer_is_completed()) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Installer: disabled');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'success' => false,
            'message' => 'Installer is disabled because installation has been completed.',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if (! function_exists('installer_finalize_is_completed')) {
    /**
     * finalize-env.php 전용 멱등 차단 신호.
     *
     * 본 함수는 `.env` 의 `INSTALLER_COMPLETED=true` 단독으로만 차단을 판정한다.
     * `storage/app/g7_installed` 락 파일은 finalize 호출 직전 단계인
     * `complete_flag` task 가 먼저 생성하므로 차단 사유에서 제외한다.
     */
    function installer_finalize_is_completed(): bool
    {
        return installer_env_completed_flag(installer_resolve_base_path().'/.env');
    }
}

if (! function_exists('installer_guard_finalize_or_410')) {
    /**
     * finalize-env.php 전용 진입 가드.
     *
     * 차단 조건: `.env` 의 `INSTALLER_COMPLETED=true` 단독. `g7_installed` 락 파일
     * 존재는 차단 사유가 아니다 — complete_flag task 가 락 파일을 먼저 만든 직후
     * 본 엔드포인트가 호출되어 `.env` 머지를 수행해야 하기 때문이다.
     *
     * RCE 공격 표면이 없는 finalize 전용으로 한정 — 일반 인스톨러 엔드포인트는
     * 계속 `installer_guard_or_410()` 을 사용한다.
     */
    function installer_guard_finalize_or_410(): void
    {
        if (! installer_finalize_is_completed()) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Installer: disabled');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'success' => false,
            'message' => 'Finalize already completed.',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}
