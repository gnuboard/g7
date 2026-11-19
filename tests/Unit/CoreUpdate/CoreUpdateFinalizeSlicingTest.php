<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Extension\Helpers\FilePermissionHelper;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트 「마무리」의 준비 구간(버전 기록 · 캐시 · 소유권·권한 복원 · 링크)이 요청 몫 안에서 멈추고
 * 이어 가는지, 그리고 나눠 처리하는 조각이 몫을 넘지 않게 응답 마무리 몫을 남기는지 잰다.
 *
 * 소유권·그룹 쓰기 권한 복원이 vendor·백업·전개본 트리 전체를 한 요청에서 훑으면 큰 사이트에서는 수 분이 걸려,
 * 실행 시간 30~60초 호스팅에서는 그 요청이 시간 제한으로 끊긴다. 나눠 처리하는 조각도 마감을 몫과 같게 두면
 * 멈춘 뒤의 처리만큼 몫을 넘긴다.
 *
 * 커버 축: core_update_web finalize_slicing
 */
class CoreUpdateFinalizeSlicingTest extends TestCase
{
    /** base_path 아래 격리 루트. */
    private string $root;

    /** 원래 storage 경로. */
    private string $originalStoragePath;

    /** 원래 public 경로. */
    private string $originalPublicPath;

    /**
     * 격리 storage·public 과 픽스처 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_final_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'public');

        // 마무리 준비는 공개 저장소 링크를 점검한다 — 개발 저장소의 링크를 건드리지 않게 둘 다 격리한다.
        $this->originalStoragePath = storage_path();
        $this->originalPublicPath = public_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
        app()->usePublicPath($this->root.DIRECTORY_SEPARATOR.'public');
    }

    /**
     * 경로를 되돌리고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(CoreUpdateService::class)->releaseRunLock();
        app()->useStoragePath($this->originalStoragePath);
        app()->usePublicPath($this->originalPublicPath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * 나눠 처리하는 조각의 마감은 몫에서 응답 마무리 몫을 뺀 자리다 (멈춘 뒤의 처리까지 몫 안에 들게).
     *
     * @scenario long_step=finalize_cleanup, slicing=sliced
     *
     * @effects a_slice_leaves_room_to_finish_the_response
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_work_deadline_leaves_room_to_finish_the_response(): void
    {
        $clock = static fn (): float => 1000.0;

        $long = new CoreUpdateTimeBudget($clock, 300);
        $long->begin();
        $this->assertSame(1000.0 + CoreUpdateTimeBudget::MAX_SLICE_SECONDS - CoreUpdateTimeBudget::FINISH_MARGIN_SECONDS, $long->deadline());
        $this->assertLessThan(CoreUpdateTimeBudget::MAX_SLICE_SECONDS, $long->workSeconds());

        $short = new CoreUpdateTimeBudget($clock, 30);
        $short->begin();
        $this->assertSame(15.0 - CoreUpdateTimeBudget::FINISH_MARGIN_SECONDS, $short->workSeconds());

        // 아주 짧은 제한에서도 1초는 진행한다.
        $tiny = new CoreUpdateTimeBudget($clock, 2);
        $tiny->begin();
        $this->assertGreaterThanOrEqual(1.0, $tiny->workSeconds());
    }

    /**
     * 트리 훑기를 한 항목씩 나눠도 한 번에 훑은 순서·항목과 같고, 링크·정션 안으로 들어가지 않는다.
     *
     * @scenario long_step=finalize_cleanup, slicing=sliced
     *
     * @effects the_ownership_restore_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_tree_walk_visits_the_same_entries_as_a_single_pass(): void
    {
        $tree = $this->fixtureTree('walk');

        $single = [];
        $result = FilePermissionHelper::walkTreeChunk($tree, null, null, function (string $path, string $relative) use (&$single): void {
            $single[] = $relative;
        });
        $this->assertTrue($result['done']);

        $sliced = [];
        $cursor = null;
        $calls = 0;

        do {
            $result = FilePermissionHelper::walkTreeChunk($tree, $cursor, static fn (): bool => true, function (string $path, string $relative) use (&$sliced): void {
                $sliced[] = $relative;
            });
            $cursor = $result['cursor'];
            $calls++;
        } while (! $result['done'] && $calls < 200);

        $this->assertTrue($result['done']);
        $this->assertGreaterThan(5, $calls);
        $this->assertSame($single, $sliced);
        $this->assertContains('d1/link', $single, '링크 자체는 방문한다');
        $this->assertNotContains('d1/link/outside.txt', $single, '링크를 따라 들어가면 운영 업로드 폴더의 소유권까지 바꾼다');
    }

    /**
     * 소유권·그룹 쓰기 권한 복원을 나눠도 끝나고, 대상마다 멈추고 이어 간다.
     *
     * @scenario long_step=finalize_cleanup, slicing=sliced
     *
     * @effects the_ownership_restore_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_ownership_restore_finishes(): void
    {
        if (! function_exists('chown')) {
            $this->markTestSkipped('chown 이 없는 서버에서는 소유권 복원이 돌지 않는다');
        }

        $treeA = $this->fixtureTree('own-a');
        $treeB = $this->fixtureTree('own-b');
        $snapshot = [];

        foreach ([$treeA, $treeB] as $tree) {
            $snapshot[$this->relative($tree)] = ['owner' => @fileowner($tree), 'group' => @filegroup($tree), 'perms' => @fileperms($tree) & 0777];
        }

        config(['app.update.restore_ownership_group_writable' => array_keys($snapshot)]);

        $service = app(CoreUpdateService::class);
        $progress = [];
        $calls = 0;

        do {
            $progress = $service->restoreOwnershipChunk($snapshot, [], $progress, static fn (): bool => true);
            $calls++;
        } while (! $progress['done'] && $calls < 500);

        $this->assertTrue($progress['done']);
        $this->assertGreaterThan(5, $calls, '대상 트리를 한 요청에 다 훑으면 큰 vendor·백업에서 요청 시간 제한에 걸린다');
        $this->assertSame([], $progress['warnings']);
    }

    /**
     * 항목별 정확 복원(상세 스냅샷)도 항목 커서로 나뉜다 — 한 구간으로 하면 실행 시간이 짧은 서버에서 그 한 요청이 몫을 넘긴다.
     *
     * @scenario long_step=finalize_cleanup, slicing=sliced
     *
     * @effects the_ownership_restore_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_detailed_snapshot_restore_is_split_by_entry(): void
    {
        if (! function_exists('chown')) {
            $this->markTestSkipped('chown 이 없는 서버에서는 소유권 복원이 돌지 않는다');
        }

        $tree = $this->fixtureTree('detailed');
        $snapshot = [$this->relative($tree) => ['owner' => @fileowner($tree), 'group' => @filegroup($tree), 'perms' => @fileperms($tree) & 0777]];
        config(['app.update.restore_ownership_group_writable' => []]);

        $service = app(CoreUpdateService::class);
        $detailed = $service->snapshotOwnershipDetailed([$tree]);
        $this->assertGreaterThan(5, count($detailed), '픽스처가 충분한 항목을 갖지 않는다');

        $progress = [];
        $calls = 0;
        $detailedCalls = 0;

        do {
            $progress = $service->restoreOwnershipChunk($snapshot, $detailed, $progress, static fn (): bool => true);
            $calls++;

            if (($progress['phase'] ?? null) === 'detailed' && ! $progress['done']) {
                $detailedCalls++;
            }
        } while (! $progress['done'] && $calls < 500);

        $this->assertTrue($progress['done']);
        $this->assertGreaterThanOrEqual(count($detailed) - 1, $detailedCalls, '상세 스냅샷을 한 구간에 다 하면 항목 수만큼 한 요청이 길어진다');
        $this->assertSame([], $progress['warnings']);
    }

    /**
     * 마무리 첫 요청은 준비(버전·캐시)까지만 하고 멈추며, 소유권 복원은 다음 요청부터 나눠 한다.
     *
     * @scenario long_step=finalize_cleanup, slicing=web_requests
     *
     * @effects the_ownership_restore_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_finalize_step_does_not_restore_ownership_in_its_first_request(): void
    {
        $treeA = $this->fixtureTree('fin-a');
        $snapshot = [$this->relative($treeA) => ['owner' => @fileowner($treeA), 'group' => @filegroup($treeA), 'perms' => @fileperms($treeA) & 0777]];
        config(['app.update.restore_ownership_group_writable' => array_keys($snapshot)]);

        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('updateVersionInEnv')->andReturnNull();
        $service->shouldReceive('clearAllCaches')->andReturnNull();
        $service->shouldReceive('signalQueueRestart')->andReturnNull();
        $wholeRestoreCalls = 0;
        $service->shouldReceive('restoreOwnership')->andReturnUsing(function () use (&$wholeRestoreCalls): void {
            $wholeRestoreCalls++;
        });

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.5', '7.2.6', 1);
        $state->patch(['next_step' => CoreUpdateStep::Finalize->value]);
        $state->mergeContext(['ownership' => $snapshot, 'detailed_ownership' => []]);

        $orchestrator = fn (): CoreUpdateOrchestrator => new CoreUpdateOrchestrator(
            $service,
            $this->state(),
            new CoreUpdatePreflight($service),
            app(CommandRunnerResolver::class),
            $this->tightBudget(),
        );

        $orchestrator()->advance();

        $this->assertSame(0, $wholeRestoreCalls, '첫 요청이 소유권 복원까지 하면 그 요청 하나가 트리 전체를 훑는다');
        $this->assertSame(CoreUpdateStep::Finalize, $this->state()->nextStep());

        // 나머지 구간은 요청마다 조금씩 — 링크 점검·점검 모드 해제 전(캐시 굽기는 개발 저장소를 건드린다)까지만 돈다.
        $requests = 1;

        while (($this->state()->context('finalize_progress')['phase'] ?? null) !== 'link' && $requests < 300) {
            $orchestrator()->advance();
            $requests++;
        }

        $this->assertSame('link', $this->state()->context('finalize_progress')['phase'] ?? null);
        $this->assertGreaterThan(4, $requests, '소유권 복원이 한 요청에 끝났다면 나뉘지 않은 것이다');
        $this->assertSame(0, $wholeRestoreCalls);
    }

    /**
     * 격리 경로를 쓰는 상태를 만든다.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }

    /**
     * 물을 때마다 크게 흐르는 시계의 예산.
     *
     * @return CoreUpdateTimeBudget 예산
     */
    private function tightBudget(): CoreUpdateTimeBudget
    {
        $tick = 0.0;

        return new CoreUpdateTimeBudget(static function () use (&$tick): float {
            return $tick += 100.0;
        }, 30);
    }

    /**
     * 하위 폴더·링크가 든 트리를 만든다.
     *
     * @param  string  $name  폴더 이름
     * @return string 트리 루트
     */
    private function fixtureTree(string $name): string
    {
        $tree = $this->root.DIRECTORY_SEPARATOR.$name;

        foreach (['a.txt', 'd1/b.txt', 'd1/c.txt', 'd1/sub/d.txt', 'd2/e.txt', 'd2/f.txt', 'z.txt'] as $relative) {
            $path = $tree.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'x');
        }

        $outside = $this->root.DIRECTORY_SEPARATOR.$name.'-outside';
        File::ensureDirectoryExists($outside);
        File::put($outside.DIRECTORY_SEPARATOR.'outside.txt', 'o');

        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J '.escapeshellarg($tree.DIRECTORY_SEPARATOR.'d1'.DIRECTORY_SEPARATOR.'link').' '.escapeshellarg($outside).' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode(' ', $output));
        } else {
            $this->assertTrue(symlink($outside, $tree.DIRECTORY_SEPARATOR.'d1'.DIRECTORY_SEPARATOR.'link'));
        }

        return $tree;
    }

    /**
     * base_path 상대 경로로 바꾼다.
     *
     * @param  string  $absolute  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $absolute): string
    {
        return ltrim(str_replace('\\', '/', substr($absolute, strlen(base_path()))), '/');
    }

    /**
     * 링크·정션을 따라가지 않고 트리를 지운다.
     *
     * @param  string  $path  지울 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if (! file_exists($path) && ! is_link($path) && @readlink($path) === false) {
            return;
        }

        $isJunction = PHP_OS_FAMILY === 'Windows' && ! is_link($path) && ! is_file($path) && ! is_dir($path) && @readlink($path) !== false;

        if (is_link($path) || $isJunction) {
            if (! @rmdir($path)) {
                @unlink($path);
            }

            return;
        }

        if (is_dir($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
                }
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
