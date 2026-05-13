<?php

namespace Tests\Feature\Upgrade;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test-1: 경로 B — `core:execute-upgrade-steps` 가 별도 PHP 프로세스로 실행될 때
 * 최신 파일 기준으로 클래스를 로드함을 실증한다.
 *
 * 시나리오:
 *  1. 임시 Service 클래스(`app/Services/TestUpgradeSpawnMarkerService.php`) 작성 —
 *     프로세스 메모리 캐시와 무관한 마커 파일을 생성하는 정적 메서드 보유
 *  2. 임시 upgrade 파일(`upgrades/Upgrade_0_0_1_test_spawn.php`) 작성 — run() 이
 *     신규 Service 의 정적 메서드 호출
 *  3. `proc_open` 으로 `php artisan core:execute-upgrade-steps --from=0.0.0 --to=0.0.1 --force`
 *     실행 → 새 PHP 프로세스가 방금 작성한 파일들을 autoload 로 읽어 실행
 *  4. 마커 파일 존재로 정상 호출 검증
 *  5. tearDown: 임시 파일 3종(Service, Upgrade, 마커) 삭제
 *
 * 본 테스트가 통과하면 경로 B 구조(별도 프로세스 spawn → 최신 클래스 로드)가
 * 실제로 작동함을 보장한다.
 */
class CoreUpgradeStepSpawnTest extends TestCase
{
    private string $tmpServicePath;

    private string $tmpUpgradePath;

    private string $markerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpServicePath = base_path('app/Services/TestUpgradeSpawnMarkerService.php');
        $this->tmpUpgradePath = base_path('upgrades/Upgrade_0_0_1_test_spawn.php');
        $this->markerPath = storage_path('app/test-upgrade-spawn-marker-'.uniqid().'.txt');
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmpServicePath, $this->tmpUpgradePath, $this->markerPath] as $p) {
            if (File::exists($p)) {
                File::delete($p);
            }
        }
        parent::tearDown();
    }

    public function test_spawned_process_loads_fresh_classes(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        $markerPath = addslashes($this->markerPath);

        $serviceCode = <<<PHP
<?php

namespace App\\Services;

class TestUpgradeSpawnMarkerService
{
    public static function writeMarker(): void
    {
        file_put_contents('{$markerPath}', 'spawned:'.getmypid());
    }
}
PHP;

        $upgradeCode = <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_spawn implements UpgradeStepInterface
{
    public function version(): string
    {
        return '0.0.1';
    }

    public function description(): string
    {
        return 'Test spawn marker';
    }

    public function run(UpgradeContext $context): void
    {
        \App\Services\TestUpgradeSpawnMarkerService::writeMarker();
    }
}
PHP;

        File::put($this->tmpServicePath, $serviceCode);
        File::put($this->tmpUpgradePath, $upgradeCode);

        $this->assertFileDoesNotExist($this->markerPath, '사전: 마커 파일이 없어야 한다');

        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');
        $commandLine = implode(' ', array_map('escapeshellarg', [
            $phpBinary,
            $artisan,
            'core:execute-upgrade-steps',
            '--from=0.0.0',
            '--to=0.0.1',
            '--force',
            '--env=testing',
        ])).' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($commandLine, $descriptors, $pipes, base_path());
        $this->assertIsResource($process, 'proc_open 으로 별도 프로세스 실행 성공해야 한다');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            "spawn 프로세스가 exit 0 으로 종료되어야 한다. output:\n{$stdout}"
        );

        $this->assertFileExists(
            $this->markerPath,
            '별도 프로세스가 최신 Service 를 autoload 로 로드하여 마커를 작성해야 한다'
        );

        $marker = File::get($this->markerPath);
        $this->assertStringStartsWith('spawned:', $marker, '마커 내용이 예상 형식이어야 한다');
    }
}
