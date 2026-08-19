<?php

namespace Tests\Unit\Services;

use App\Services\StorageLeftoverPruneService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 스토리지 잔존물 정리 서비스 회귀 테스트 (`storage:prune-leftovers`)
 *
 * 확장/코어 업데이트·설치가 중단되며 남긴 임시 산출물(#120 동형 — 산출물 누적 +
 * 회수 경로 부재)을 mtime 기준으로 회수하되, 파괴 방지 가드(정상 대기 확장 불가침 ·
 * 최신 백업 상시 보존 · dry-run 무삭제)를 함께 고정한다.
 *
 * 픽스처는 실제 저장소 경로가 아닌 격리 루트에 만든다 — 서비스 생성자의 루트 주입이
 * 그 격리 지점이다.
 */
class StorageLeftoverPruneServiceTest extends TestCase
{
    /** @var string 픽스처 격리 루트 */
    private string $fixtureRoot;

    /** @var string base_path 대응 루트 */
    private string $baseRoot;

    /** @var string storage_path 대응 루트 */
    private string $storageRoot;

    private StorageLeftoverPruneService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing/leftover-prune-'.uniqid());
        $this->baseRoot = $this->fixtureRoot.DIRECTORY_SEPARATOR.'base';
        $this->storageRoot = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage';

        File::ensureDirectoryExists($this->baseRoot);
        File::ensureDirectoryExists($this->storageRoot);

        $this->service = new StorageLeftoverPruneService($this->baseRoot, $this->storageRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    /**
     * 보존일이 지난 스테이징·임시·vendor 번들 잔존물만 삭제되고, 신선한 것은 남는다.
     *
     * @scenario leftover_target=update_staging, leftover_mode=real_delete, leftover_guard=aged_out
     *
     * @effects aged_leftovers_are_pruned_after_retention, fresh_leftovers_are_kept
     */
    public function test_aged_leftovers_are_pruned_and_fresh_ones_are_kept(): void
    {
        // 업데이트 스테이징 (타임스탬프 접미사) + 교체 임시 마커
        $agedStaging = $this->makeDir('base/modules/_pending/acme-shop_20260701_010101', 5);
        $agedSwap = $this->makeDir('base/modules/_pending/acme-shop_updating_0123456789abc', 5);
        $freshStaging = $this->makeDir('base/plugins/_pending/acme-pay_20260817_010101', 0);

        // 코어 임시 (디렉토리 + 파일 혼재)
        $agedTempDir = $this->makeDir('storage/app/temp/module_update_deadbeef00000', 5);
        $agedTempFile = $this->makeFile('storage/app/temp/core_remote_changelog.md', 5);
        $freshTempDir = $this->makeDir('storage/app/temp/plugin_update_cafebabe00000', 0);

        // vendor 번들 스테이징
        $agedBuild = $this->makeDir('storage/app/vendor-bundle-staging/build-0011223344556.77889900', 5);
        $freshBuild = $this->makeDir('storage/app/vendor-bundle-staging/build-aabbccddeeff0.11223344', 0);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertDirectoryDoesNotExist($agedStaging);
        $this->assertDirectoryDoesNotExist($agedSwap);
        $this->assertDirectoryExists($freshStaging, '보존일 이내 스테이징이 삭제되었다');

        $this->assertDirectoryDoesNotExist($agedTempDir);
        $this->assertFileDoesNotExist($agedTempFile);
        $this->assertDirectoryExists($freshTempDir, '보존일 이내 임시 디렉토리가 삭제되었다');

        $this->assertDirectoryDoesNotExist($agedBuild);
        $this->assertDirectoryExists($freshBuild, '보존일 이내 번들 스테이징이 삭제되었다');

        $this->assertCount(2, $result['staging']);
        $this->assertCount(2, $result['temp']);
        $this->assertCount(1, $result['vendor_bundle_staging']);
    }

    /**
     * 백업은 보존일 경과분만 삭제하되, identifier 별 최신 1개는 나이와 무관하게 보존한다.
     *
     * @scenario leftover_target=extension_backups, leftover_mode=real_delete, leftover_guard=latest_backup_kept
     *
     * @effects latest_backup_is_always_kept
     */
    public function test_latest_backup_is_kept_regardless_of_age(): void
    {
        // 확장 백업 — 같은 identifier 의 오래된 2개: 최신(40일)은 보존, 그 이전(50일)은 삭제
        $extOldest = $this->makeDir('storage/app/extension_backups/modules/acme-shop_20260601_010101', 50);
        $extNewest = $this->makeDir('storage/app/extension_backups/modules/acme-shop_20260708_010101', 40);
        // 다른 identifier 의 보존일 이내 백업은 최신이 아니어도 남는다
        $extRecentA = $this->makeDir('storage/app/extension_backups/modules/acme-blog_20260810_010101', 7);
        $extRecentB = $this->makeDir('storage/app/extension_backups/modules/acme-blog_20260815_010101', 2);

        // 코어 백업 — 최신 1개 보존, 그 이전 삭제
        $coreOldest = $this->makeDir('storage/app/core_backups/core_20260601_020202', 50);
        $coreNewest = $this->makeDir('storage/app/core_backups/core_20260708_020202', 40);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertDirectoryDoesNotExist($extOldest);
        $this->assertDirectoryExists($extNewest, '나이와 무관하게 최신 백업 1개는 보존해야 한다 (롤백 능력)');
        $this->assertDirectoryExists($extRecentA, '보존일 이내 백업은 최신이 아니어도 남아야 한다');
        $this->assertDirectoryExists($extRecentB);

        $this->assertDirectoryDoesNotExist($coreOldest);
        $this->assertDirectoryExists($coreNewest, '코어도 최신 백업 1개는 상시 보존해야 한다');

        $this->assertSame([$extOldest], $result['extension_backups']);
        $this->assertSame([$coreOldest], $result['core_backups']);
    }

    /**
     * `_pending` 직하위의 정상 대기 확장 디렉토리(타임스탬프 접미사 없음)는 불가침이다.
     *
     * @scenario leftover_target=update_staging, leftover_mode=real_delete, leftover_guard=normal_pending_untouched
     *
     * @effects normal_pending_extension_dirs_are_untouched
     */
    public function test_normal_pending_extension_directories_are_untouched(): void
    {
        // 다운로드 대기 정상 확장 — 오래되어도 삭제 대상이 아니다
        $pendingModule = $this->makeDir('base/modules/_pending/acme-shop', 90);
        $pendingTemplate = $this->makeDir('base/templates/_pending/acme-admin_basic', 90);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertDirectoryExists($pendingModule, '정상 대기 확장 디렉토리가 삭제되었다');
        $this->assertDirectoryExists($pendingTemplate);
        $this->assertSame([], $result['staging']);
    }

    /**
     * dry-run 은 아무것도 삭제하지 않고 삭제 대상 목록만 돌려준다.
     *
     * @scenario leftover_target=core_temp, leftover_mode=dry_run, leftover_guard=aged_out
     *
     * @effects dry_run_deletes_nothing
     */
    public function test_dry_run_deletes_nothing_and_reports_targets(): void
    {
        $agedStaging = $this->makeDir('base/modules/_pending/acme-shop_20260701_010101', 5);
        $agedTemp = $this->makeDir('storage/app/temp/ext_zip_0123456789abc', 5);
        $legacyLog = $this->makeFile('storage/logs/browser.log', 1);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: true);

        $this->assertDirectoryExists($agedStaging, 'dry-run 이 스테이징을 실제로 삭제했다');
        $this->assertDirectoryExists($agedTemp, 'dry-run 이 임시 디렉토리를 실제로 삭제했다');
        $this->assertFileExists($legacyLog, 'dry-run 이 레거시 로그를 실제로 삭제했다');

        $this->assertSame([$agedStaging], $result['staging']);
        $this->assertSame([$agedTemp], $result['temp']);
        $this->assertSame([$legacyLog], $result['legacy_browser_log']);
    }

    /**
     * 정리 직후 재실행은 아무것도 삭제하지 않는다 (멱등).
     *
     * @scenario leftover_target=vendor_bundle_staging, leftover_mode=real_delete, leftover_guard=idempotent_second_run
     *
     * @effects leftover_prune_is_idempotent
     */
    public function test_second_run_prunes_nothing(): void
    {
        $this->makeDir('base/modules/_pending/acme-shop_20260701_010101', 5);
        $this->makeDir('storage/app/temp/module_update_deadbeef00000', 5);
        $this->makeDir('storage/app/vendor-bundle-staging/build-0011223344556.77889900', 5);
        $this->makeDir('storage/app/extension_backups/modules/acme-shop_20260601_010101', 50);
        $this->makeDir('storage/app/extension_backups/modules/acme-shop_20260708_010101', 40);
        $this->makeDir('storage/app/core_backups/core_20260601_020202', 50);
        $this->makeDir('storage/app/core_backups/core_20260708_020202', 40);
        $this->makeFile('storage/logs/browser.log', 1);

        $first = $this->service->prune(days: 3, backupDays: 30, dryRun: false);
        $second = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertGreaterThan(0, array_sum(array_map('count', $first)), '1회차가 아무것도 지우지 않으면 멱등 검증이 무의미하다');
        $this->assertSame(0, array_sum(array_map('count', $second)), '2회차가 무언가를 또 지웠다 — 멱등이 아니다');
    }

    /**
     * 레거시 단일 browser.log 는 나이와 무관하게 삭제하되, 날짜별 로그는 건드리지 않는다.
     *
     * @scenario leftover_target=legacy_browser_log, leftover_mode=real_delete, leftover_guard=aged_out
     *
     * @effects legacy_browser_log_is_removed
     */
    public function test_legacy_browser_log_is_removed_but_dated_logs_are_kept(): void
    {
        $legacy = $this->makeFile('storage/logs/browser.log', 0);
        $dated = $this->makeFile('storage/logs/browser-2026-08-17.log', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertFileDoesNotExist($legacy, '레거시 단일 browser.log 는 writer 가 사라졌으므로 무조건 삭제한다');
        $this->assertFileExists($dated, '날짜별 로그는 daily 드라이버가 자체 정리한다 — 대상이 아니다');
        $this->assertSame([$legacy], $result['legacy_browser_log']);
    }

    /**
     * 픽스처 디렉토리를 만들고 mtime 을 지정 일수만큼 과거로 되돌립니다.
     *
     * 내부에 파일을 하나 두어 실제 잔존물 형태(비어 있지 않은 디렉토리)를 재현하고,
     * 내용 생성이 디렉토리 mtime 을 갱신하므로 touch 는 마지막에 수행한다.
     *
     * @param  string  $relative  픽스처 루트 기준 상대 경로
     * @param  int  $ageDays  현재로부터 며칠 전의 mtime 으로 둘지
     * @return string 절대 경로
     */
    private function makeDir(string $relative, int $ageDays): string
    {
        $path = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists($path);
        file_put_contents($path.DIRECTORY_SEPARATOR.'payload.txt', 'leftover');

        $mtime = time() - $ageDays * 86400;
        touch($path.DIRECTORY_SEPARATOR.'payload.txt', $mtime);
        touch($path, $mtime);

        return $path;
    }

    /**
     * 픽스처 파일을 만들고 mtime 을 지정 일수만큼 과거로 되돌립니다.
     *
     * @param  string  $relative  픽스처 루트 기준 상대 경로
     * @param  int  $ageDays  현재로부터 며칠 전의 mtime 으로 둘지
     * @return string 절대 경로
     */
    private function makeFile(string $relative, int $ageDays): string
    {
        $path = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'leftover');
        touch($path, time() - $ageDays * 86400);

        return $path;
    }
}
