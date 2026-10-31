<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_3\Migrations\RegenerateExtensionCachesWithFingerprint;
use App\Upgrades\Data\V7_1_3\Migrations\RemoveOrphanExtensionCacheFiles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

/**
 * 코어 7.1.3 업그레이드 스텝 — 고아 확장 캐시 파일 삭제·지문 실은 캐시 재생성.
 *
 * @scenario-manifest tests/scenarios/upgrade-7-1-3-extension-cache.yaml
 */
class Upgrade_7_1_3_ExtensionCacheTest extends TestCase
{
    /** @var array<int, array{level: string, message: string}> 업그레이드 로그 수집 */
    private array $logs = [];

    /** @var array<string, string|null> 시험 전 고아 파일 내용 (원복용, null = 없었음) */
    private array $original = [];

    private const ORPHANS = [
        'bootstrap/cache/extension-schedule-commands.php',
        'bootstrap/cache/extension-artifacts.json',
    ];

    /**
     * 데이터 마이그레이션을 적재하고 업그레이드 로그를 가로챕니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.3/migrations/01_RemoveOrphanExtensionCacheFiles.php');
        require_once base_path('upgrades/data/7.1.3/migrations/02_RegenerateExtensionCachesWithFingerprint.php');

        foreach (self::ORPHANS as $relative) {
            $path = base_path($relative);
            $this->original[$relative] = is_file($path) ? File::get($path) : null;
        }

        $logs = &$this->logs;
        $collector = new class($logs) extends AbstractLogger
        {
            /**
             * 기록 대상 배열을 참조로 받습니다.
             *
             * @param  array<int, array{level: string, message: string}>  $logs  기록 대상 배열(참조)
             */
            public function __construct(private array &$logs) {}

            /**
             * 로그 한 줄을 기록합니다.
             *
             * @param  mixed  $level  수준
             * @param  string|Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
        Log::partialMock()->shouldReceive('channel')->with('upgrade')->andReturn($collector);
    }

    /**
     * 고아 파일을 시험 전 상태로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->original as $relative => $content) {
            $path = base_path($relative);
            if ($content === null) {
                File::delete($path);
            } else {
                File::put($path, $content);
            }
        }
        parent::tearDown();
    }

    /**
     * 고아 파일이 있으면 지우고, 다시 돌리면 「없음」 로그만 남긴다(멱등).
     *
     * @scenario step=remove_orphans
     *
     * @effects orphan_cache_files_removed_idempotently
     *
     * @return void 반환값 없음
     */
    public function test_orphan_cache_files_are_removed_idempotently(): void
    {
        foreach (self::ORPHANS as $relative) {
            File::put(base_path($relative), 'orphan');
        }
        $context = (new UpgradeContext('7.1.2', '7.1.3'))->withCurrentStep('7.1.3');

        (new RemoveOrphanExtensionCacheFiles)->run($context);
        foreach (self::ORPHANS as $relative) {
            $this->assertFileDoesNotExist(base_path($relative));
        }

        $this->logs = [];
        (new RemoveOrphanExtensionCacheFiles)->run($context);
        $this->assertCount(2, array_filter($this->logs, fn ($l) => str_contains($l['message'], '건너뜀')));
    }

    /**
     * 캐시 재생성은 extension:update-autoload 를 부르고, 경고와 함께 끝나도 업데이트를 막지 않는다.
     *
     * @scenario step=regenerate_caches
     *
     * @effects cache_regeneration_never_blocks_update
     *
     * @return void 반환값 없음
     */
    public function test_cache_regeneration_logs_and_never_throws(): void
    {
        Artisan::shouldReceive('call')->once()->with('extension:update-autoload')->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('[g7-lifecycle-warnings] count=1');
        $context = (new UpgradeContext('7.1.2', '7.1.3'))->withCurrentStep('7.1.3');

        (new RegenerateExtensionCachesWithFingerprint)->run($context);

        $this->assertSame('warning', $this->logs[0]['level'] ?? null);
    }
}
