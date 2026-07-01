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

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        // 안전 가드: 본 테스트는 BASE_PATH/.env 와 BASE_PATH/storage/app/g7_installed 를
        // 직접 write/unlink 한다. BASE_PATH 는 PHP 상수라 한 프로세스에서 단 한 번만 정의되며,
        // 다른 Installer 테스트가 먼저 프로젝트 루트로 박으면 본 테스트의 cleanup() 이
        // 실제 운영 .env 를 삭제하게 된다. BASE_PATH 가 시스템 temp 하위가 아니면 skip 한다.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '
                    .'다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 실제 .env 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerGuardTest';

                return;
            }
            self::$tempBase = (string) BASE_PATH;
        } else {
            self::$tempBase = sys_get_temp_dir().'/g7-installer-guard-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$tempBase);
        }

        if (! is_dir(self::$tempBase.'/storage/app')) {
            @mkdir(self::$tempBase.'/storage/app', 0755, true);
        }

        require_once dirname(__DIR__, 3).'/public/install/api/_guard.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // skip 상태(= BASE_PATH 가 실 루트)면 파괴적 정리를 절대 수행하지 않는다.
        if (self::$skipReason !== '') {
            return;
        }

        $lock = BASE_PATH.'/storage/app/g7_installed';
        if (is_file($lock)) {
            @unlink($lock);
        }
        $env = BASE_PATH.'/.env';
        if (is_file($env)) {
            @unlink($env);
        }
    }

    public function test_no_signals_means_not_completed(): void
    {
        $this->assertFalse(installer_is_completed());
    }

    public function test_lock_file_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_accepts_quoted_true(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED="true"'."\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_accepts_one(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=1'."\n");

        $this->assertTrue(installer_is_completed());
    }

    public function test_env_flag_false_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=false\n");

        $this->assertFalse(installer_is_completed());
    }

    public function test_env_flag_missing_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\n");

        $this->assertFalse(installer_is_completed());
    }

    public function test_both_signals_present_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=true'."\n");

        $this->assertTrue(installer_is_completed());
    }

    // ------------------------------------------------------------------------
    // finalize 전용 가드 — installer_finalize_is_completed()
    //
    // 일반 가드와 달리 g7_installed 락 파일은 차단 사유에서 제외되어야 한다
    // (complete_flag task 가 락 파일을 먼저 생성한 직후 finalize 가 호출되는
    //  설계상의 호출 순서 때문 — 자가 차단 회귀 가드).
    // ------------------------------------------------------------------------

    public function test_finalize_guard_passes_with_lock_only(): void
    {
        // 정상 1회차 finalize 시나리오: 락 파일은 존재하나 .env 머지 아직 안 됨
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertFalse(
            installer_finalize_is_completed(),
            'g7_installed 락 파일 단독 존재 시 finalize 차단되면 자가 차단 회귀'
        );
    }

    public function test_finalize_guard_passes_with_no_env_file(): void
    {
        // .env 자체가 없는 신규 설치 시나리오
        $this->assertFalse(installer_finalize_is_completed());
    }

    public function test_finalize_guard_blocks_when_env_completed_true(): void
    {
        // 이미 finalize 된 상태 — 멱등 차단
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(installer_finalize_is_completed());
    }

    public function test_finalize_guard_blocks_with_lock_and_env_completed(): void
    {
        // 락 + .env true 동시 존재 → 차단 (멱등)
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=true'."\n");

        $this->assertTrue(installer_finalize_is_completed());
    }

    public function test_finalize_guard_passes_when_env_completed_false(): void
    {
        file_put_contents(BASE_PATH.'/.env', "INSTALLER_COMPLETED=false\n");

        $this->assertFalse(installer_finalize_is_completed());
    }

    public function test_finalize_guard_accepts_quoted_and_alt_truthy_values(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED="true"'."\n");
        $this->assertTrue(installer_finalize_is_completed());

        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=1'."\n");
        $this->assertTrue(installer_finalize_is_completed());

        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=yes'."\n");
        $this->assertTrue(installer_finalize_is_completed());
    }

    // ------------------------------------------------------------------------
    // state-management.php 진입 가드 정적 회귀 (KVE-2026-1056)
    //
    // state-management.php?action=get 은 정상 설치 흐름의 1초 폴링이며,
    // complete_flag task 가 g7_installed 락 파일을 finalize 보다 먼저 생성한다.
    // 따라서 일반 가드(installer_guard_or_410, 락 파일도 차단 사유)를 적용하면
    // "락만 있고 .env 플래그는 아직 없는" 폴링 구간이 410 으로 끊겨 완료 화면이
    // 표시되지 않는 자가 차단 회귀가 발생한다.
    //
    // 올바른 조치: finalize 전용 가드(installer_guard_finalize_or_410,
    // .env INSTALLER_COMPLETED=true 단독 판정)를 객체 생성 전에 호출한다.
    // exit/header 는 단위에서 검증 불가하므로 소스 정적 검사로 회귀를 못박는다.
    // ------------------------------------------------------------------------

    private function stateManagementSource(): string
    {
        $path = dirname(__DIR__, 3).'/public/install/api/state-management.php';

        $this->assertFileExists($path, 'state-management.php 가 존재해야 한다');

        return (string) file_get_contents($path);
    }

    public function test_state_management_loads_guard_file(): void
    {
        $src = $this->stateManagementSource();

        $this->assertMatchesRegularExpression(
            '/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]\/_guard\.php[\'"]/',
            $src,
            'state-management.php 가 _guard.php 를 로드해야 한다'
        );
    }

    public function test_state_management_calls_finalize_guard_not_general_guard(): void
    {
        $src = $this->stateManagementSource();

        $this->assertStringContainsString(
            'installer_guard_finalize_or_410()',
            $src,
            'state-management.php 는 finalize 전용 가드를 호출해야 한다 (.env 플래그 단독 판정)'
        );

        // 일반 가드를 쓰면 락-only 폴링 구간이 410 으로 끊겨 정상 설치가 깨진다.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!finalize_)installer_guard_or_410\s*\(\s*\)/',
            $src,
            'state-management.php 는 일반 installer_guard_or_410() 을 호출하면 안 된다 '
                .'(complete_flag 락 파일로 인한 폴링 자가 차단 회귀)'
        );
    }

    public function test_state_management_guard_runs_before_api_instantiation(): void
    {
        $src = $this->stateManagementSource();

        $guardPos = strpos($src, 'installer_guard_finalize_or_410()');
        $instantiatePos = strpos($src, 'new StateManagementApi');

        $this->assertNotFalse($guardPos, 'finalize 가드 호출이 존재해야 한다');
        $this->assertNotFalse($instantiatePos, 'StateManagementApi 인스턴스화가 존재해야 한다');
        $this->assertLessThan(
            $instantiatePos,
            $guardPos,
            '가드는 StateManagementApi 객체 생성 전에 호출되어야 한다 (모든 action 일괄 차단)'
        );
    }

    public function test_state_management_polling_passes_with_lock_only(): void
    {
        // 핵심 비파괴 불변식: complete_flag 직후~finalize 전 구간(락만 존재)에서
        // finalize 가드는 통과해야 한다 — 폴링이 completed 상태를 받아야 완료 화면 전환.
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertFalse(
            installer_finalize_is_completed(),
            'g7_installed 락 단독 존재 시 state-management 폴링이 차단되면 '
                .'정상 설치가 "진행 중"에 고착된다 (KVE-2026-1056 조치의 비파괴 조건)'
        );
    }

    public function test_state_management_blocks_when_env_completed(): void
    {
        // 완전 완료 상태(.env 플래그 set)에서는 get/reset/abort 가 차단되어야 한다 —
        // KVE-2026-1056 의 보호장치 우회·상태 변조 공격 표면 제거.
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(
            installer_finalize_is_completed(),
            '완전 설치 완료 상태에서 state-management 가 차단되어야 한다 (KVE-2026-1056)'
        );
    }
}
