<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 진입 가드 회귀 테스트
 *
 * 설치 완료 후 public/install/api/ 하위 모든 엔드포인트가 비즈니스 로직 진입 전
 * HTTP 410 으로 차단되어야 하는 정책의 단위 검증.
 *
 * 판정 신호:
 *  1. {BASE_PATH}/storage/app/g7_installed 락 파일 존재
 *  2. .env 의 INSTALLER_COMPLETED=true (또는 1, yes)
 *
 * exit/header 동작은 단위 테스트 내에서 직접 검증할 수 없으므로
 * installer_is_completed() 의 결정론적 판정 결과만 검증한다.
 * 410 응답 본문은 PoC 매뉴얼 검증 영역.
 */
class InstallerGuardTest extends TestCase
{
    private static string $tempBase = '';

    public static function setUpBeforeClass(): void
    {
        self::$tempBase = sys_get_temp_dir() . '/g7-installer-guard-test-' . bin2hex(random_bytes(4));
        mkdir(self::$tempBase . '/storage/app', 0755, true);

        // BASE_PATH 가 다른 테스트에서 이미 정의되었을 수 있다.
        // 그 경우 폴백 (installer_resolve_base_path) 가 본 파일 위치 기준으로 결정되므로
        // 본 테스트는 정의된 BASE_PATH 를 그대로 사용하고, 그 안에 격리 디렉토리를 만든다.
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', self::$tempBase);
        } else {
            // 다른 테스트의 BASE_PATH 와 공존 — 그 디렉토리 안에 lock/.env 를 두고 정리.
            self::$tempBase = BASE_PATH;
            if (! is_dir(BASE_PATH . '/storage/app')) {
                @mkdir(BASE_PATH . '/storage/app', 0755, true);
            }
        }

        require_once dirname(__DIR__, 3) . '/public/install/api/_guard.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        @unlink(BASE_PATH . '/storage/app/g7_installed');
        @unlink(BASE_PATH . '/.env');
    }

    public function test_no_signals_means_not_completed(): void
    {
        $this->assertFalse(installer_is_completed());
    }

    public function test_lock_file_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH . '/storage/app/g7_installed', '');

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH . '/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_accepts_quoted_true(): void
    {
        file_put_contents(BASE_PATH . '/.env', 'INSTALLER_COMPLETED="true"' . "\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_accepts_one(): void
    {
        file_put_contents(BASE_PATH . '/.env', 'INSTALLER_COMPLETED=1' . "\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_false_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH . '/.env', "APP_ENV=local\nINSTALLER_COMPLETED=false\n");

        $this->assertFalse(installer_is_completed());
    }

    public function test_env_flag_missing_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH . '/.env', "APP_ENV=local\n");

        $this->assertFalse(installer_is_completed());
    }

    public function test_both_signals_present_marks_completed(): void
    {
        file_put_contents(BASE_PATH . '/storage/app/g7_installed', '');
        file_put_contents(BASE_PATH . '/.env', 'INSTALLER_COMPLETED=true' . "\n");

        $this->assertTrue(installer_is_completed());
    }
}
