<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Core\ExecuteUpgradeStepsCommand;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 업그레이드 스텝 spawn 자식의 upgrade 로그 파일 소유권 복원 회귀 테스트.
 *
 * 회귀 가드 (버그 ③ 로그 영역): sudo 코어 업데이트는 upgrade step 을 별도 PHP 프로세스로
 * spawn(ExecuteUpgradeStepsCommand) 하며, root 로 실행되어 `upgrade-YYYY-MM-DD.log`
 * (Log::channel('upgrade')) 를 root 소유로 만든다. 이후 www-data(php-fpm) 의 module:update
 * upgrade step 이 같은 날짜 로그에 append 하지 못해 "could not be opened in append mode:
 * Permission denied" 로 실패한다.
 *
 * upgrade 로그를 만든 주체인 spawn 자식이 **항상 신버전 코드로 실행**되므로, 자기 종료
 * 직전에 직접 로그 소유권을 부모(storage/logs) 기준으로 정합한다. 부모(CoreUpdateCommand)
 * 는 업데이트 시작 시점의 이전 버전 클래스를 메모리에 들고 있을 수 있어(클래스 캐싱) 부모
 * 측 정상화는 신뢰할 수 없다 — 그래서 자식이 처리한다.
 */
class CoreUpdateLogFileOwnershipTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    private function invokeRestore(): void
    {
        $command = new ExecuteUpgradeStepsCommand;
        $method = new ReflectionMethod($command, 'restoreUpgradeLogOwnership');
        $method->setAccessible(true);
        $method->invoke($command);
    }

    /**
     * upgrade-*.log 가 부모(storage/logs) 소유권으로 정합된다 (POSIX best-effort).
     * sudo 아닌 환경에서 상속은 no-op(이미 부모와 동일 owner) 이므로 "로그 owner == 부모
     * owner" 가 항상 성립해야 한다 — 상속이 owner 를 잘못 바꾸면 깨짐.
     */
    public function test_restore_upgrade_log_ownership_matches_parent(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('fileowner')) {
            $this->markTestSkipped('소유권 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }

        $logsDir = storage_path('logs');
        File::ensureDirectoryExists($logsDir);

        $upgradeLog = $logsDir.'/upgrade-2026-06-30.log';
        file_put_contents($upgradeLog, "test\n");
        $this->createdFiles[] = $upgradeLog;

        $this->invokeRestore();

        $this->assertSame(
            fileowner($logsDir),
            fileowner($upgradeLog),
            'upgrade 로그는 부모(storage/logs) 소유권과 일치해야 함',
        );
    }

    /**
     * 확장 업그레이드 로그(extension-upgrade-*.log)도 정합 대상에 포함된다 (POSIX best-effort).
     *
     * 코어/확장 업그레이드 로그를 별도 채널(upgrade / extension-upgrade)로 분리했으므로,
     * 소유권 정합 glob 이 두 접두사를 모두 커버해야 한다. 'upgrade-*.log' 단일 패턴은
     * 'extension-' 접두사 파일을 매칭하지 못하므로 두 패턴을 각각 순회한다. 정합이 확장
     * 로그를 누락하면 www-data 의 module:update 가 root 소유 파일에 append 못 해 회귀.
     */
    public function test_restore_covers_extension_upgrade_log(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('fileowner')) {
            $this->markTestSkipped('소유권 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }

        $logsDir = storage_path('logs');
        File::ensureDirectoryExists($logsDir);

        $extensionLog = $logsDir.'/extension-upgrade-2026-07-01.log';
        file_put_contents($extensionLog, "ext\n");
        $this->createdFiles[] = $extensionLog;

        $this->invokeRestore();

        $this->assertSame(
            fileowner($logsDir),
            fileowner($extensionLog),
            'extension-upgrade 로그도 부모(storage/logs) 소유권과 일치해야 함',
        );
    }

    /**
     * 무관한 로그 파일(laravel.log, core_update_*.log 등)은 건드리지 않는다 — glob 한정성.
     * (자식 메서드는 upgrade-*.log / extension-upgrade-*.log 만 처리. core_update_*.log 는
     *  부모 saveUpdateLog 가 생성 직후 개별 정합하므로 자식 대상이 아님.)
     */
    public function test_restore_does_not_throw_and_leaves_unrelated_files(): void
    {
        $logsDir = storage_path('logs');
        File::ensureDirectoryExists($logsDir);

        $unrelated = $logsDir.'/laravel-test-marker.log';
        file_put_contents($unrelated, "keep\n");
        $this->createdFiles[] = $unrelated;

        $this->invokeRestore();

        $this->assertFileExists($unrelated);
        $this->assertSame("keep\n", file_get_contents($unrelated));
    }
}
