<?php

namespace Tests\Unit\Support;

use App\Support\InstallerContext;
use Tests\TestCase;

/**
 * InstallerContext 회귀 테스트.
 *
 * `INSTALLER_COMPLETED=true` .env 를 빈 DB 새 서버에 복사한 뒤 `php artisan migrate`
 * 부팅 시, installer_completed fast-path 가 테이블 존재를 잘못 전제해 부팅을 깨뜨리던
 * 결함을 막기 위한 마이그레이션 계열 명령 판정 헬퍼를 검증한다.
 *
 * PHPUnit 실행 자체가 콘솔 컨텍스트(runningInConsole()===true)이므로,
 * $_SERVER['argv'][1] 을 조작해 마이그레이션 명령 여부만 바꿔 판정을 검증한다.
 * argv 는 finally 에서 반드시 원복해 다른 테스트 오염을 막는다.
 */
class InstallerContextTest extends TestCase
{
    /** @var array<int, string>|null 원본 argv 백업 */
    private ?array $originalArgv = null;

    protected function tearDown(): void
    {
        if ($this->originalArgv !== null) {
            $_SERVER['argv'] = $this->originalArgv;
            $this->originalArgv = null;
        }

        parent::tearDown();
    }

    /**
     * 주어진 argv[1] 로 $_SERVER['argv'] 를 임시 설정하고 콜백을 실행합니다.
     *
     * @param  string  $command  artisan 명령명 (argv[1] 위치)
     * @param  callable  $callback  argv 설정 상태에서 실행할 코드
     */
    private function withArgv(string $command, callable $callback): void
    {
        if ($this->originalArgv === null) {
            $this->originalArgv = $_SERVER['argv'] ?? [];
        }

        $_SERVER['argv'] = ['artisan', $command];

        try {
            $callback();
        } finally {
            $_SERVER['argv'] = $this->originalArgv;
            $this->originalArgv = null;
        }
    }

    public function test_returns_true_for_migrate(): void
    {
        $this->withArgv('migrate', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_true_for_migrate_fresh(): void
    {
        $this->withArgv('migrate:fresh', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_true_for_migrate_refresh(): void
    {
        // migrate:refresh 는 참조 구현(LanguagePackServiceProvider)에 누락돼 있던 명령.
        // 스키마를 drop/recreate 하므로 동일한 빈 DB 구간을 만든다 → 반드시 포함.
        $this->withArgv('migrate:refresh', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_true_for_migrate_rollback(): void
    {
        $this->withArgv('migrate:rollback', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_true_for_migrate_reset(): void
    {
        $this->withArgv('migrate:reset', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_true_for_db_wipe(): void
    {
        $this->withArgv('db:wipe', function () {
            $this->assertTrue(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_false_for_non_migration_command(): void
    {
        $this->withArgv('serve', function () {
            $this->assertFalse(InstallerContext::isSchemaMutatingCommand());
        });
    }

    public function test_returns_false_for_route_list(): void
    {
        $this->withArgv('route:list', function () {
            $this->assertFalse(InstallerContext::isSchemaMutatingCommand());
        });
    }
}
