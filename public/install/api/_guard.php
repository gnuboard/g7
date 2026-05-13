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

if (!function_exists('installer_resolve_base_path')) {
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

if (!function_exists('installer_is_completed')) {
    function installer_is_completed(): bool
    {
        $basePath = installer_resolve_base_path();

        $lockFile = $basePath . '/storage/app/g7_installed';
        if (is_file($lockFile)) {
            return true;
        }

        $envFile = $basePath . '/.env';
        if (is_file($envFile)) {
            $env = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
            if (is_array($env)) {
                $flag = strtolower(trim((string) ($env['INSTALLER_COMPLETED'] ?? '')));
                // parse_ini_file 은 따옴표가 보존된 raw 모드이므로 양 끝 따옴표 제거
                $flag = trim($flag, "\"'");
                if (in_array($flag, ['true', '1', 'yes'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('installer_guard_or_410')) {
    function installer_guard_or_410(): void
    {
        if (!installer_is_completed()) {
            return;
        }

        if (!headers_sent()) {
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
