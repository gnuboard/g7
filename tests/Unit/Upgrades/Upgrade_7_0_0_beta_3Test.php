<?php

namespace Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Upgrade_7_0_0_beta_3;
use RuntimeException;
use Tests\TestCase;

/**
 * Upgrade_7_0_0_beta_3 단위 테스트
 *
 * 본 스텝은 경로 C (beta.2 부모 CoreUpdateCommand in-process) 로 실행되도록 설계됨.
 * spawn 자식 경로에서는 의도적 throw → beta.2 의 in-process fallback 재실행을 유도.
 * in-process 경로에서는 부모 umask(0002) 주입 + 로컬 재귀 chmod 로 기존 g-w 디렉토리를
 * g+w 로 승격.
 *
 * POSIX 전용 테스트는 Windows 에서 `assertPosixOrSkip()` 으로 자동 스킵.
 */
class Upgrade_7_0_0_beta_3Test extends TestCase
{
    /**
     * upgrades/ 디렉토리 파일은 composer autoload 에 없으므로 테스트 진입 전 수동 로드.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Upgrade_7_0_0_beta_3::class, false)) {
            require_once base_path('upgrades/Upgrade_7_0_0_beta_3.php');
        }
    }

    private function assertPosixOrSkip(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('posix_getuid')) {
            $this->markTestSkipped('POSIX 전용 — chmod/fileperms/umask 의미 제한');
        }
    }

    /**
     * TARGETS 상수는 인스톨러 REQUIRED_DIRECTORIES (public/install/includes/config.php)
     * 및 config/app.php 의 restore_ownership_group_writable 기본값과 1:1 정렬되어야 한다.
     */
    public function test_targets_constant_matches_config_default(): void
    {
        $reflection = new \ReflectionClassConstant(Upgrade_7_0_0_beta_3::class, 'TARGETS');
        $targets = $reflection->getValue();

        $expected = [
            'storage',
            'bootstrap/cache',
            'vendor',
            'modules',
            'modules/_pending',
            'plugins',
            'plugins/_pending',
            'templates',
            'templates/_pending',
            'storage/app/core_pending',
        ];

        $this->assertSame($expected, $targets, 'TARGETS 목록은 인스톨러 SSoT 와 동기화되어야 한다');
    }

    /**
     * config/app.php 의 restore_ownership_group_writable 기본값과도 동일해야 한다.
     */
    public function test_targets_matches_config_app_restore_ownership_group_writable(): void
    {
        $configDefault = config('app.update.restore_ownership_group_writable');

        $reflection = new \ReflectionClassConstant(Upgrade_7_0_0_beta_3::class, 'TARGETS');
        $targets = $reflection->getValue();

        $this->assertSame(
            $targets,
            array_values($configDefault),
            'TARGETS 상수와 config 기본값이 1:1 동일해야 한다'
        );
    }

    /**
     * spawn 자식 탐지 — argv 에 `core:execute-upgrade-steps` 가 있으면 의도적으로 throw.
     * beta.2 의 spawnUpgradeStepsProcess 가 exit != 0 감지 → in-process fallback 유도.
     */
    public function test_throws_when_running_inside_spawned_child(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['php', 'artisan', 'core:execute-upgrade-steps', '--from=7.0.0-beta.2', '--to=7.0.0-beta.3'];

        try {
            $step = new Upgrade_7_0_0_beta_3;
            $context = new UpgradeContext(
                fromVersion: '7.0.0-beta.2',
                toVersion: '7.0.0-beta.3',
                currentStep: '7.0.0-beta.3',
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/in-process/');

            $step->run($context);
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    /**
     * in-process 경로 (argv 에 core:update 등) 에서는 예외 없이 완료.
     * 현재 코드베이스의 storage 가 g+w 이든 g-w 이든 예외는 발생하지 않아야 한다
     * (운영자 의도 존중 분기 또는 정상 실행 어느 쪽이든 정상 종료).
     */
    public function test_run_completes_without_exception_in_process(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['php', 'artisan', 'core:update'];

        try {
            $step = new Upgrade_7_0_0_beta_3;
            $context = new UpgradeContext(
                fromVersion: '7.0.0-beta.2',
                toVersion: '7.0.0-beta.3',
                currentStep: '7.0.0-beta.3',
            );

            $step->run($context);

            $this->assertTrue(true, 'run() 이 예외 없이 완료되었다');
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    /**
     * 경로 C 규율 준수 검증: 파일에서 `FilePermissionHelper::syncGroupWritability` 호출이
     * 제거되었는지 확인. 경로 C 는 신설 메서드 호출 금지.
     */
    public function test_does_not_call_new_file_permission_helper_method(): void
    {
        $source = file_get_contents(base_path('upgrades/Upgrade_7_0_0_beta_3.php'));

        // 호출 패턴(`::메서드명(`) 만 잡는다. docblock 설명 텍스트는 허용.
        $this->assertStringNotContainsString(
            'FilePermissionHelper::syncGroupWritability(',
            $source,
            '경로 C 규율: beta.3 신설 메서드 호출 금지. 로컬 private 메서드로 인라인되어야 한다'
        );

        // use 문도 제거되어야 한다 (의존성 최소화)
        $this->assertStringNotContainsString(
            'use App\Extension\Helpers\FilePermissionHelper;',
            $source,
            'FilePermissionHelper import 제거 — 경로 C 는 인라인 로직만 사용'
        );

        $this->assertStringContainsString(
            '@upgrade-path C',
            $source,
            'docblock 에 @upgrade-path C 선언이 있어야 한다'
        );
    }

    /**
     * in-process 실행 시 storage g+w 환경에서는 umask 가 0002 로 전환되어야 한다.
     */
    public function test_sets_umask_to_0002_when_storage_has_group_write(): void
    {
        $this->assertPosixOrSkip();

        // storage 가 g+w 인 경우에만 의미. 테스트 환경 storage 퍼미션 확인.
        $storagePath = base_path('storage');
        if (! is_dir($storagePath)) {
            $this->markTestSkipped('storage/ 디렉토리 미존재');
        }
        $perms = fileperms($storagePath);
        if (($perms & 0020) === 0) {
            $this->markTestSkipped('테스트 환경 storage 가 g-w — 운영자 의도 존중 스킵 분기라 검증 불가');
        }

        $originalUmask = umask(0022);
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['php', 'artisan', 'core:update'];

        try {
            $step = new Upgrade_7_0_0_beta_3;
            $context = new UpgradeContext(
                fromVersion: '7.0.0-beta.2',
                toVersion: '7.0.0-beta.3',
                currentStep: '7.0.0-beta.3',
            );

            $step->run($context);

            $this->assertSame(0002, umask(), 'in-process 실행 후 umask 는 0002 로 전환되어야 한다');
        } finally {
            umask($originalUmask);
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }
}
