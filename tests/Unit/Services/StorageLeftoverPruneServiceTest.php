<?php

namespace Tests\Unit\Services;

use App\Console\Commands\PruneStorageLeftoversCommand;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\BackupDirectoryName;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\RootHtaccessUpdater;
use App\Extension\Helpers\RootWebConfigUpdater;
use App\Extension\Helpers\StorageLinkHelper;
use App\Services\CoreUpdateService;
use App\Services\StorageLeftoverPruneService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 스토리지 잔존물 정리 서비스 회귀 테스트 (`storage:prune-leftovers`)
 *
 * 확장/코어 업데이트·설치가 중단되며 남긴 임시 산출물(공개 #120 동형 — 산출물 누적 +
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

    /** @var string public_path 대응 루트 (웹 트리) */
    private string $publicRoot;

    private StorageLeftoverPruneService $service;

    /**
     * 기준, storage, public 세 루트를 임시 픽스처 안에 만들고 코어 업데이트 전개 경로도 픽스처 안으로 옮긴 뒤 정리 서비스를 그 루트들로 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing/leftover-prune-'.uniqid());
        $this->baseRoot = $this->fixtureRoot.DIRECTORY_SEPARATOR.'base';
        $this->storageRoot = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage';
        $this->publicRoot = $this->fixtureRoot.DIRECTORY_SEPARATOR.'public';

        File::ensureDirectoryExists($this->baseRoot);
        File::ensureDirectoryExists($this->storageRoot);
        File::ensureDirectoryExists($this->publicRoot);

        // `app.update.pending_path` 는 주입 루트 밖의 **절대 경로**라, 그대로 두면 이 단위
        // 테스트가 개발 머신의 실제 코어 업데이트 전개본(`storage/app/core_pending`)을 훑고
        // `dryRun: false` 경로에서 지운다 — 픽스처가 만든 것이 아닌 것을 지우는 셈이다.
        // 픽스처 안의 다른 자리로 옮겨, 「운영자가 옮긴 위치도 본다」는 축은 그대로 재면서
        // 바깥을 건드리지 않게 한다.
        config(['app.update.pending_path' => $this->fixtureRoot.DIRECTORY_SEPARATOR.'moved_core_pending']);

        $this->service = new StorageLeftoverPruneService($this->baseRoot, $this->storageRoot, $this->publicRoot);
    }

    /**
     * 임시 픽스처 디렉토리를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * **만드는 쪽이 실제로 만든 이름**이 지우는 쪽 모집단에 들어간다.
     *
     * 이 검사가 없던 동안 둘은 실제로 갈려 있었다 — 생산자가 같은 초 충돌을 막으려고
     * 이름 끝에 난수를 붙였는데 정리 쪽 정규식은 시각으로 끝나는 형태 그대로여서, 그
     * 뒤로 만들어진 코어 백업이 정리 목록에 **한 번도** 들어가지 않았다. 기존 픽스처는
     * 이름을 손으로 적었기 때문에(`core_20260601_020202`) 그 상태에서도 초록이었다.
     *
     * 그래서 이름을 **생산자에게 받아온다.** 여기서 이름 규약을 다시 적는 순간 이
     * 검사는 생산자와 무관해지고 같은 사각이 그대로 돌아온다.
     *
     * @scenario leftover_target=core_backup, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_core_backup_name_from_the_real_producer_enters_the_prune_population(): void
    {
        // 대상 0개로 부르면 디렉토리만 만들고 끝난다 — 이름만 필요하다.
        $olderPath = CoreBackupHelper::createBackup([]);
        $newerPath = CoreBackupHelper::createBackup([]);
        $olderName = basename($olderPath);
        $newerName = basename($newerPath);
        File::deleteDirectory($olderPath);
        File::deleteDirectory($newerPath);

        // 같은 초에 두 번 불러도 이름이 갈리는 것 자체가 생산자의 계약이다.
        $this->assertNotSame(
            $olderName,
            $newerName,
            '같은 초의 두 실행이 같은 이름을 만든다 — 뒤 실행이 앞 실행의 백업을 덮어쓴다'
        );

        // 판별 조건: 생산자 이름을 **최신** 자리에 두고, 그 이전 자리에는 이 규약
        // 이전의 이름을 둔다. 생산자 이름이 모집단에서 빠지면 남는 항목이 하나뿐이라
        // 그것이 최신으로 보존되어 **아무것도 지워지지 않는다** — 그 상태가 결함이었다.
        $legacy = $this->makeDir('storage/app/core_backups/core_20260601_020202', 50);
        $produced = $this->makeDir('storage/app/core_backups/'.$newerName, 40);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame(
            [$legacy],
            $result['core_backups'],
            '생산자가 만든 이름이 정리 모집단에 들어가지 않는다 — 코어 전량 스냅샷이 무한 누적한다'
        );
        $this->assertDirectoryDoesNotExist($legacy);
        $this->assertDirectoryExists($produced, '최신 1개는 나이와 무관하게 보존해야 한다');
    }

    /**
     * 확장 백업 이름도 같은 규약을 쓴다 — 형제 축의 패리티.
     *
     * 확장 업데이트에는 코어와 달리 실행 잠금이 없어 같은 초 충돌이 오히려 더 쉽다.
     *
     * @scenario leftover_target=extension_backup, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_extension_backup_name_shape_matches_the_shared_convention(): void
    {
        // 식별자 자신에 밑줄이 들어 있는 확장을 고른다 — 이름을 시각과 가르는 지점이
        // 밑줄이라, 여기서 잘못 갈리면 그 확장만 조용히 모집단에서 빠진다.
        $identifier = 'g7test-prune_axis';

        // 이름은 **실제 생산자에게 받아온다.** 종전에는 여기서 `BackupDirectoryName::stamp()`
        // 을 직접 불렀는데, 그러면 `ExtensionBackupHelper` 가 자기 이름 모양을 바꿔도 이
        // 검사는 그대로 초록이다 — 정리 모집단에서 빠지는 결함이 바로 그 형태였고, 형제
        // 축(코어)은 이미 생산자를 부르고 있었다. 한쪽만 생산자를 부르는 상태가 곧 비대칭이다.
        $olderName = basename($this->nameFromExtensionBackupProducer('plugins', $identifier));
        $producedName = basename($this->nameFromExtensionBackupProducer('plugins', $identifier));

        // 같은 초에 두 번 불러도 이름이 갈리는 것 자체가 생산자의 계약이다 — 형제 축(코어)과
        // 같다. 확장 업데이트에는 코어와 달리 실행 잠금이 없어 그 충돌이 오히려 더 쉽다.
        // 아래 모집단 판정은 이 축을 대신하지 못한다: 이름 모양 정규식은 접미사를 **선택**으로
        // 두어(이 규약 이전 백업도 계속 인식해야 하므로) 접미사가 사라져도 통과한다.
        $this->assertNotSame(
            $olderName,
            $producedName,
            '같은 초의 두 실행이 같은 이름을 만든다 — 뒤 실행이 앞 실행이 이미 적용한 신 버전 디스크를 '
            .'「업데이트 이전 스냅샷」 자리에 덮어써 백업의 정의가 깨진다'
        );

        // 생산자의 이름이 **모양 SSoT** 를 따르는지 그 자리에서 단언한다 — 아래 모집단
        // 판정만으로는 「정리 정규식과 생산자가 함께 어긋난」 상태를 구분하지 못한다.
        $this->assertMatchesRegularExpression(
            BackupDirectoryName::pattern(preg_quote($identifier, '/').'_'),
            $producedName,
            '확장 백업 생산자가 공유 이름 규약을 따르지 않는다 — 정리 쪽 정규식과 갈리는 순간 그 백업이 모집단에서 조용히 빠진다'
        );

        $legacy = $this->makeDir(
            'storage/app/extension_backups/plugins/'.$identifier.'_20260601_010101',
            50
        );
        $produced = $this->makeDir(
            'storage/app/extension_backups/plugins/'.$producedName,
            40
        );

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame(
            [$legacy],
            $result['extension_backups'],
            '새 규약으로 만든 확장 백업이 정리 모집단에서 빠진다'
        );
        $this->assertDirectoryExists($produced, '최신 1개는 나이와 무관하게 보존해야 한다');
    }

    /**
     * 교체 직전 루트 진입 파일 사본은 **백업 등급**으로 보존된다.
     *
     * 이 사본은 임시 산출물이 아니라 되돌릴 **유일한 수단**이다 — 업데이트가 성공하면
     * 코어 백업은 그 자리에서 삭제되므로, 운영자가 자기 랜딩 페이지가 사라진 것을 며칠
     * 뒤에 알아차렸을 때 남아 있어야 할 것이 이것뿐이다. 종전에는 임시 산출물 등급
     * (기본 3일)이라 나흘째에는 복구 수단이 0 이 됐다.
     *
     * @scenario leftover_target=core_update_preserved, leftover_mode=real_delete, leftover_guard=backup_grade
     *
     * @effects root_entry_backup_survives_temp_retention_and_latest_is_always_kept
     *
     * @return void 반환값 없음
     */
    public function test_core_update_preserved_copies_follow_backup_retention(): void
    {
        // 임시 보존일(3일)은 지났지만 백업 보존일(30일) 이내 — 남아야 한다.
        $withinBackup = $this->makeDir('storage/app/core-update/20260810_010101', 10);
        // 백업 보존일도 지난 것 — 최신이 아니면 삭제.
        $aged = $this->makeDir('storage/app/core-update/20260601_010101', 50);
        // 백업 보존일이 지났어도 **최신 1개**는 나이와 무관하게 보존.
        $latestButAged = $this->makeDir('storage/app/core-update/20260901_010101', 40);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertDirectoryExists(
            $withinBackup,
            '임시 보존일이 지났다는 이유로 지워졌다 — 이 사본은 백업 등급이어야 한다'
        );
        $this->assertDirectoryExists(
            $latestButAged,
            '최신 사본은 나이와 무관하게 남아야 한다 (되돌릴 마지막 수단)'
        );
        $this->assertDirectoryDoesNotExist($aged);

        $this->assertSame([$aged], $result['core_update_preserved']);
    }

    /**
     * 이름 형식이 다른 잔해는 「최신 1개」 자리를 **차지하지 못한다.**
     *
     * 「최신」 판정은 디렉토리명 문자열 정렬이라, 형식이 다른 이름(`manual`·`_keep` 등)
     * 하나가 숫자보다 뒤에 와 **영구히** 그 자리를 차지한다. 그러면 진짜 최신 타임스탬프
     * 사본이 무조건 보존을 잃고 보존일 뒤 삭제되어, 등급을 백업으로 올린 목적(되돌릴
     * 수단의 수명)이 그 자리에서 무효가 된다. 형제 두 백업군은 처음부터 이름 패턴 게이트를
     * 갖고 있었고 이 대상군만 그 밖이었다.
     *
     * 이 검사가 없으면 게이트를 통째로 지워도 전부 초록이다(실측으로 확인).
     *
     * @scenario leftover_target=core_update_preserved, leftover_mode=real_delete, leftover_guard=name_pattern
     *
     * @effects foreign_named_leftovers_never_occupy_the_latest_backup_slot
     *
     * @return void 반환값 없음
     */
    public function test_foreign_named_leftovers_do_not_occupy_the_latest_slot(): void
    {
        // 문자열 정렬에서 숫자보다 뒤에 오는 이름 — 게이트가 없으면 이것이 「최신」이 된다.
        $foreign = $this->makeDir('storage/app/core-update/manual', 90);
        // 진짜 최신 사본(보존일 경과) — 나이와 무관하게 남아야 한다.
        $latest = $this->makeDir('storage/app/core-update/20260901_010101', 90);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertDirectoryExists(
            $latest,
            '형식이 다른 잔해가 「최신 1개」 자리를 차지해 진짜 최신 사본이 삭제됐다 — 되돌릴 수단이 0 이 된다'
        );
        $this->assertDirectoryExists(
            $foreign,
            '대상군 밖 이름은 이 정리기의 소관이 아니다 — 지우면 운영자가 둔 것을 임의로 없앤다'
        );
        $this->assertSame([], $result['core_update_preserved']);
    }

    /**
     * `_pending` 직하위의 정상 대기 확장 디렉토리(타임스탬프 접미사 없음)는 불가침이다.
     *
     * @scenario leftover_target=update_staging, leftover_mode=real_delete, leftover_guard=normal_pending_untouched
     *
     * @effects normal_pending_extension_dirs_are_untouched
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * **실제 생산자**(`ExtensionBackupHelper::createBackup`)에게 백업 디렉토리 이름을 받아옵니다.
     *
     * 이 검사가 재려는 것은 「생산자가 만드는 이름이 정리 모집단에 들어가는가」다. 그래서
     * 이름을 여기서 조립하면 안 된다 — 생산자가 모양을 바꿔도 이 검사는 그대로 초록이 되고,
     * 그 상태가 바로 이 규약이 폐기하려던 결함이다(형제 축인 코어는 이미 생산자를 부른다).
     *
     * 생산자는 실재하는 소스 디렉토리를 요구하므로 일회용 확장 트리를 만들어 부르고,
     * 이름만 취한 뒤 소스·산출물을 함께 걷는다. 저장소의 실제 확장을 대상으로 삼지 않는
     * 이유는 활성 확장 디렉토리가 환경마다 있을 수도, 없을 수도 있기 때문이다.
     *
     * @param  string  $type  확장 유형 디렉토리 (`plugins` 등)
     * @param  string  $identifier  확장 식별자
     * @return string 생산자가 만든 백업 디렉토리 절대 경로
     */
    private function nameFromExtensionBackupProducer(string $type, string $identifier): string
    {
        $source = base_path($type.DIRECTORY_SEPARATOR.$identifier);
        File::ensureDirectoryExists($source);
        file_put_contents($source.DIRECTORY_SEPARATOR.'plugin.json', '{}');

        try {
            $produced = ExtensionBackupHelper::createBackup($type, $identifier);
        } finally {
            File::deleteDirectory($source);
        }

        File::deleteDirectory($produced);

        return $produced;
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
     * 정리 커맨드의 라벨 표가 서비스의 대상군과 대칭이다.
     *
     * 커맨드는 라벨 표를 `foreach` 로 돌며 출력하므로, **라벨에 없는 키는 합계에서도
     * 목록에서도 통째로 빠진다.** 서비스가 대상군을 하나 늘려도 운영자 화면에는 그 건수가
     * 나타나지 않고, 오류도 경고도 없다 — 「지울 것이 없었다」와 구분되지 않는다.
     * 종전에는 이 대칭을 재는 단언이 없었다.
     *
     * 반대 방향도 함께 본다. 라벨만 남고 대상군이 사라지면 그 줄은 영원히 0 으로 출력되어
     * 운영자가 「그 축은 항상 깨끗하다」고 읽는다.
     *
     * @effects prune_command_labels_cover_every_prune_group
     *
     * @return void 반환값 없음
     */
    public function test_command_labels_cover_every_prune_group(): void
    {
        $service = new StorageLeftoverPruneService($this->fixtureRoot);
        $groups = array_keys($service->prune(dryRun: true));

        $reflection = new \ReflectionClass(PruneStorageLeftoversCommand::class);
        $labels = array_keys($reflection->getConstant('GROUP_LABELS'));

        $this->assertNotEmpty($groups, '대상군 도출이 비었다 — 아래 비교가 공허하다');

        sort($groups);
        sort($labels);

        $this->assertSame(
            $groups,
            $labels,
            '정리 커맨드의 라벨 표와 서비스의 대상군이 어긋난다 — 라벨에 없는 대상군은 '
            .'합계에서도 목록에서도 빠지고, 대상군 없는 라벨은 영원히 0 으로 출력된다'
        );
    }

    /**
     * 손상된 공개 스토리지 백업이 **정리 모집단에 든다** — 생산자 이름 기준.
     *
     * 이 백업은 `public/storage` 트리를 통째로 옮겨 둔 것이라 크기에 상한이 없고, 링크가
     * 반복 손상되는 환경에서는 실행마다 하나씩 쌓인다. 그런데 종전에는 어느 대상군에도
     * 없어 **정리 주체가 0** 이었다 — G3 이 문서 루트 안에서 밖으로 옮기면서 위치만
     * 바꾸고 회수 주체를 만들지 않았다.
     *
     * 이름은 **생산자 규약에서 도출**한다(`public-storage-broken-<YmdHis>-<hex8>`).
     * 리터럴로 다시 적으면 생산자가 규칙을 바꿀 때 모집단이 조용히 비므로, 형제 두
     * 백업군이 이미 쓰는 형태를 따른다.
     *
     * @scenario leftover_target=public_storage_broken, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_public_storage_broken_backup_enters_the_prune_population(): void
    {
        // 이름은 **생산자에게 받아온다** — 독블록이 「생산자 규약에서 도출한다」고 적어 두고
        // 실제로는 손으로 적은 리터럴이었다. 그러면 생산자가 규칙을 바꿔도 이 검사는 그대로
        // 초록이고, 그것이 이 규약이 폐기하려는 결함 자체다.
        $older = $this->makeDir('storage/app/'.$this->nameFromBrokenBackupProducer(), 50);
        // 같은 초에 두 번 불러도 이름이 갈리는 것 자체가 생산자의 계약이다.
        $newer = $this->makeDir('storage/app/'.$this->nameFromBrokenBackupProducer(), 40);

        $this->assertNotSame(
            basename($older),
            basename($newer),
            '같은 초의 두 실행이 같은 이름을 만든다 — 뒤 실행의 rename 이 실패해 공개 파일 사본이 웹 트리에 남는다'
        );

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame(
            [$older],
            $result['public_storage_broken'],
            '생산자가 만든 이름이 정리 모집단에 들어가지 않는다 — 공개 파일 전량 사본이 무한 누적한다'
        );
        $this->assertDirectoryDoesNotExist($older);
        $this->assertDirectoryExists($newer, '최신 1개는 나이와 무관하게 보존해야 한다 (자동 삭제는 데이터 손실이다)');
    }

    /**
     * 형식이 다른 잔해는 모집단에 들지 않는다 — 「최신 1개」 자리를 뺏지 않는다.
     *
     * 형제 백업군과 같은 규약이다. 이름 게이트가 없으면 문자열 정렬에서 숫자보다 뒤에
     * 오는 잔해가 영구히 최신 자리를 차지하고, 진짜 최신 백업이 보존을 잃는다.
     *
     * @scenario leftover_target=public_storage_broken, leftover_mode=real_delete, leftover_guard=name_pattern
     *
     * @effects foreign_named_leftovers_never_occupy_the_latest_backup_slot
     *
     * @return void 반환값 없음
     */
    public function test_public_storage_broken_pruner_ignores_foreign_names(): void
    {
        $foreign = $this->makeDir(
            'storage/app/'.StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX.'manual',
            50
        );
        $produced = $this->makeDir('storage/app/'.$this->nameFromBrokenBackupProducer(), 50);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertNotContains(
            $foreign,
            $result['public_storage_broken'],
            '형식이 다른 항목이 모집단에 들었다 — 운영자가 손으로 둔 디렉토리를 코어가 지운다'
        );
        $this->assertDirectoryExists($foreign);
        $this->assertDirectoryExists($produced, '모집단에 하나뿐이면 최신 보존으로 남는다');
    }

    /**
     * 인스톨러 점검 잔여물이 **생산자 이름 기준**으로 모집단에 든다.
     *
     * 형제 두 백업군은 「실제 생산자가 만드는 이름이 모집단에 드는가」 전용 검사를
     * 갖는데 이 군만 없었다 — 접두사가 리터럴 복제라 생산자가 규칙을 바꾸면 모집단이
     * 조용히 비는데 아무것도 red 가 되지 않았다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete
     *
     * @effects installer_probe_leftovers_are_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_installer_probe_names_from_the_real_producer_enter_the_population(): void
    {
        // 생산자 상수를 **실제로 로드해** 이름을 만든다 — 리터럴을 다시 적지 않는다.
        $source = (string) file_get_contents(base_path('public/install/includes/exposure-check.php'));

        $prefixes = [];

        foreach (['INSTALLER_EXPOSURE_PROBE_PREFIX', 'INSTALLER_SYMLINK_PROBE_PREFIX'] as $constant) {
            $this->assertSame(
                1,
                preg_match("/define\(\s*'".$constant."'\s*,\s*'([^']+)'\s*\)/", $source, $m),
                $constant.' 정의를 생산자에서 찾지 못했다 — 이름 규약이 바뀌었다면 정리 주체도 함께 고친다'
            );

            $prefixes[] = $m[1];
        }

        $stale = [];

        foreach ($prefixes as $prefix) {
            $stale[] = $this->makeFile('storage/installer/'.$prefix.'deadbeef.txt', 10);
        }

        // 레이트리밋 상태 파일 — `storage/` 직하라 위 스캔의 모집단 밖이었다.
        $rate = $this->makeFile('storage/installer-probe-rate.json', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        foreach ($stale as $path) {
            $this->assertContains(
                $path,
                $result['installer_probes'],
                '생산자가 만드는 이름이 정리 모집단에 없다 — 설치를 중단한 사이트에 잔여물이 영구히 남는다'
            );
        }

        $this->assertContains(
            $rate,
            $result['installer_probes'],
            '레이트리밋 상태 파일을 지우는 주체가 없다 — 설치를 포기한 사이트에 영구 잔존한다'
        );
    }

    /**
     * 정리 주체의 접두사가 **생산자와 같은 값**이다 (사본 드리프트 차단).
     *
     * 인스톨러는 Laravel 부팅 전 코드라 코어 상수를 직접 참조할 수 없어 값이 두 곳에
     * 있다. 그 일치를 여기서 잠근다 — 어긋나면 모집단이 조용히 빈다.
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_prune_prefixes_match_the_installer_producer_constants(): void
    {
        $producer = [];

        // 쓰기 프로브 접두사는 `functions.php` 소유다(생산자 `installerDirectoryIsWritable`) —
        // 세 접두사가 두 파일에 흩어져 있어도 정리 주체는 한 표로 본다.
        foreach ([
            'INSTALLER_EXPOSURE_PROBE_PREFIX' => 'exposure-check.php',
            'INSTALLER_SYMLINK_PROBE_PREFIX' => 'exposure-check.php',
            'INSTALLER_WRITE_PROBE_PREFIX' => 'functions.php',
        ] as $constant => $file) {
            $source = (string) file_get_contents(base_path('public/install/includes/'.$file));
            preg_match("/define\(\s*'".$constant."'\s*,\s*'([^']+)'\s*\)/", $source, $m);
            $producer[] = $m[1] ?? '';
        }

        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);
        // 쓰기 프로브는 점 파일이라 형제 통로(`listDirectoryFiles`)를 탈 수 없어 별도 상수·별도
        // 스캔을 갖는다 — 생산자 집합은 그 둘의 합과 같아야 한다.
        $consumer = array_merge(
            (array) $reflection->getConstant('INSTALLER_PROBE_PREFIXES'),
            [(string) $reflection->getConstant('INSTALLER_WRITE_PROBE_PREFIX')]
        );

        sort($producer);
        sort($consumer);

        $this->assertSame(
            $producer,
            $consumer,
            '정리 주체의 접두사가 생산자 상수와 다르다 — 그 상태는 오류가 아니라 모집단이 조용히 비는 것으로만 나타난다'
        );

        // 레이트리밋 파일 이름도 같은 축이다.
        $rateSource = (string) file_get_contents(base_path('public/install/includes/execution-mode.php'));

        $this->assertStringContainsString(
            (string) $reflection->getConstant('INSTALLER_PROBE_RATE_FILE'),
            $rateSource,
            '레이트리밋 상태 파일 이름이 생산자와 다르다'
        );
    }

    /**
     * 루트 진입·보호 파일의 원자 교체 임시 파일에 **두 번째 정리 주체**가 있다.
     *
     * 생산자(`RootHtaccessUpdater`)는 자기 다음 실행에서만 걷는다 — 코어 업데이트를
     * 다시 하지 않는 사이트에서는 영구 잔존한다. 형제 대상군 전부가 「생산자 자기 스윕
     * + 정리 커맨드」 두 주체 구조인데 이 자리만 하나였다.
     *
     * @scenario leftover_target=root_entry_temp, leftover_mode=real_delete
     *
     * @effects root_entry_temp_files_have_a_second_pruner
     *
     * @return void 반환값 없음
     */
    public function test_root_entry_temp_files_are_pruned_by_the_command(): void
    {
        // 루트 진입 파일은 **프로젝트 루트**(base) 아래다 — storage 아래가 아니다.
        $stale = $this->makeFile('base/.htaccess.tmp.0123456789abcdef', 10);
        $fresh = $this->makeFile('base/web.config.tmp.fedcba9876543210', 0);

        // 생성 규칙(16진 접미사)과 다른 이름 — 운영자가 손으로 둔 파일이다.
        $operator = $this->makeFile('base/.htaccess.tmp.new', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($stale, $result['root_entry_temp']);
        $this->assertFileDoesNotExist($stale);

        $this->assertNotContains($fresh, $result['root_entry_temp'], '진행 중일 수 있는 최근 파일은 남긴다');
        $this->assertFileExists($fresh);

        $this->assertNotContains(
            $operator,
            $result['root_entry_temp'],
            '생성 규칙과 다른 이름을 지웠다 — 운영자가 같은 이름 공간에 둔 파일이 조용히 사라진다'
        );
        $this->assertFileExists($operator);
    }

    /**
     * 루트 진입 파일이 **심볼릭 링크**면 링크 대상 디렉토리의 잔해도 모집단이다 (G3 회차 16 W2 C-5).
     *
     * 생산자(`writeAtomically()`)는 임시 파일을 링크 **대상**의 디렉토리에 만든다(교차 파일시스템
     * `rename` 회피). 정리자가 링크가 놓인 디렉토리만 보면 그 잔해는 어느 정리 주체의 모집단에도
     * 들지 않아 영구 잔존한다 — 도출은 생산자의 `resolveWriteTarget()` 을 그대로 쓴다.
     *
     * @scenario leftover_target=root_entry_temp, leftover_mode=real_delete, root_entry_link=symlinked
     *
     * @effects root_entry_temp_files_have_a_second_pruner
     *
     * @return void 반환값 없음
     */
    public function test_root_entry_temp_files_next_to_a_link_target_are_pruned_too(): void
    {
        $targetDir = $this->fixtureRoot.DIRECTORY_SEPARATOR.'etc-g7';
        File::ensureDirectoryExists($targetDir);
        $realFile = $targetDir.DIRECTORY_SEPARATOR.'htaccess';
        file_put_contents($realFile, 'real');

        $link = $this->baseRoot.DIRECTORY_SEPARATOR.'.htaccess';
        if (! @symlink($realFile, $link)) {
            $this->markTestSkipped('symlink 생성 실패 (Windows SeCreateSymbolicLink 권한 부족 가능) — 링크 대상 잔해 축은 Linux CI 에서 유효');
        }

        $staleNextToTarget = $this->makeFile('etc-g7/htaccess'.RootHtaccessUpdater::TEMP_INFIX.'0123456789abcdef', 10);
        $staleNextToLink = $this->makeFile('base/.htaccess'.RootHtaccessUpdater::TEMP_INFIX.'fedcba9876543210', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($staleNextToTarget, $result['root_entry_temp'], '링크 대상 옆의 잔해가 모집단 밖이다 — 생산자가 만드는 자리를 정리자가 보지 않는다');
        $this->assertFileDoesNotExist($staleNextToTarget);
        $this->assertContains($staleNextToLink, $result['root_entry_temp'], '링크가 놓인 디렉토리의 잔해도 여전히 모집단이다(구판 생산자의 잔해)');
        $this->assertFileExists($realFile, '링크 대상 자신을 지웠다');
        $this->assertTrue(is_link($link));
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

    /**
     * **웹 트리 안**에 남은 손상 백업에도 정리 주체가 있다.
     *
     * 링크 복구는 백업을 웹 트리 밖으로 옮기지 못하면 `public/storage.broken.<…>` 로
     * 폴백한다 — 그 사본은 **지금 웹에서 열린다**. 형제(`storage/app` 쪽)만 회수 주체를
     * 얻는 동안 이쪽은 정리 주체가 0 이었다: 7.2.0 이설 마이그레이션은 1회성이라 그 뒤에
     * 새로 생기는 것을 잡지 못한다 (/ F-4).
     *
     * @scenario leftover_target=public_storage_broken_docroot, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_public_storage_broken_backup_inside_docroot_is_pruned(): void
    {
        $older = $this->makeDir('public/'.StorageLinkHelper::DOCROOT_BROKEN_PREFIX.StorageLinkHelper::brokenStamp(), 50);
        $newer = $this->makeDir('public/'.StorageLinkHelper::DOCROOT_BROKEN_PREFIX.StorageLinkHelper::brokenStamp(), 40);
        $foreign = $this->makeDir('public/'.StorageLinkHelper::DOCROOT_BROKEN_PREFIX.'memo', 50);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains(
            $older,
            $result['public_storage_broken_docroot'],
            '웹 트리 안의 공개 파일 전량 사본에 정리 주체가 없다 — 그 사본은 제2 URL 로 무기한 서빙된다'
        );
        $this->assertDirectoryDoesNotExist($older);
        $this->assertDirectoryExists($newer, '최신 1개는 나이와 무관하게 보존해야 한다 (자동 삭제는 데이터 손실이다)');
        $this->assertDirectoryExists($foreign, '형식이 다른 이름은 이 정리기의 소관이 아니다');
    }

    /**
     * 같은 초 · **같은 mtime** 구간에서도 「최신 1개 보존」이 언제나 같은 하나를 남긴다.
     *
     * 앞선 수정은 정렬 결정자를 난수 접미사에서 mtime 으로 옮기며 「이제 최신이 실제로 최신을
     * 뜻한다」고 적었다. 그런데 `filemtime()` 은 stamp 와 **같은 초 해상도**라, 같은 초에
     * 만들어진 둘은 mtime 까지 같아지는 것이 이 구간의 *정상* 상태다 — 비교자가 0 을 돌리면
     * 안정 정렬이 입력 순서(=디렉토리 열거 순서)를 남겨, 없앴다던 임의성이 한 겹 아래에서
     * 그대로 살아난다. 그 구간을 결정적으로 재는 픽스처가 하나도 없었다.
     *
     * 여기서 재는 것은 「진짜 최신을 골랐는가」가 아니다 — 초 해상도로는 알 수 없다.
     * **열거 순서와 무관하게 언제나 같은 하나가 살아남는가**를 잰다.
     *
     * @scenario leftover_target=core_backups, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_backup_set_keeps_a_stable_survivor_when_stamp_and_mtime_tie(): void
    {
        $mtime = time() - 50 * 86400;

        // 같은 초(stamp 동일) + 같은 mtime — 접미사만 다르다.
        $entries = [];
        foreach (['aaaaaaaa', 'ffffffff', '55555555'] as $suffix) {
            $path = $this->makeDir('storage/app/core_backups/core_20260701_010101_'.$suffix, 50);
            touch($path.DIRECTORY_SEPARATOR.'payload.txt', $mtime);
            touch($path, $mtime);
            $entries[] = ['path' => $path, 'stamp' => '20260701_010101_'.$suffix];
        }

        // **열거 순서를 직접 뒤집어** 넣는다. 실제 열거는 이미 정렬돼 있어 이 축이
        // 호출 경로로는 드러나지 않는다 — 정렬을 보장하지 않는 호출부가 하나라도
        // 생기면 그때 갈리므로, 비교자 자신이 전순서를 갖는지를 여기서 잰다.
        $prune = new \ReflectionMethod($this->service, 'pruneBackupSet');
        $prune->setAccessible(true);

        $cutoff = time() - 30 * 86400;
        $forward = $prune->invoke($this->service, $entries, $cutoff, true);
        $reversed = $prune->invoke($this->service, array_reverse($entries), $cutoff, true);

        sort($forward);
        sort($reversed);

        $this->assertCount(2, $forward, '같은 초·같은 mtime 구간에서도 최신 1개는 보존해야 한다');
        $this->assertSame(
            $forward,
            $reversed,
            '동률 구간의 생존자가 열거 순서에 좌우된다 — 비교자에 전순서가 없어 「최신 1개 보존」이 그때그때 다른 대상을 지운다'
        );
    }

    /**
     * finalize 가 남긴 `.env` 원본 보존 사본에 정리 주체가 있다.
     *
     * `backupExistingEnvFile()` 은 finalize 마다 `.env` **전문**(DB 자격증명 · APP_KEY ·
     * 서드파티 키)을 프로젝트 루트 직하에 한 벌 남기는데, 저장소 전체에서 그 이름을 지우는
     * 지점이 **0** 이었다 — 재설치·재시도마다 비밀 사본이 하나씩 영구 누적됐다. 형제 두 군
     * (런타임 설정 · 상태 파일)은 정리 주체를 갖는다.
     *
     * 다만 이것은 운영자가 값을 되찾는 **백업 자산**이라 백업 등급으로 걷는다 — 보존일이
     * 지난 것만, 그리고 최신 1개는 나이와 무관하게 남긴다.
     *
     * @scenario leftover_target=env_backups, leftover_mode=real_delete, leftover_guard=latest_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_env_file_backups_left_by_finalize_are_pruned(): void
    {
        $older = $this->makeFile('base/.env.bak.20260701_010101', 50);
        $collided = $this->makeFile('base/.env.bak.20260702_010101_1', 45);
        $newer = $this->makeFile('base/.env.bak.20260801_010101', 40);
        $foreign = $this->makeFile('base/.env.backup', 50);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains(
            $older,
            $result['env_backups'],
            '`.env` 전문 사본에 정리 주체가 없다 — 재설치마다 DB 자격증명 사본이 루트에 쌓인다'
        );
        $this->assertContains(
            $collided,
            $result['env_backups'],
            '같은 초 충돌 회피 접미사(`_N`)가 붙은 이름도 모집단에 들어야 한다'
        );
        $this->assertFileDoesNotExist($older);
        $this->assertFileDoesNotExist($collided);
        $this->assertFileExists($newer, '최신 1개는 나이와 무관하게 보존해야 한다 (운영자가 되찾을 값이다)');
        $this->assertFileExists($foreign, '형식이 다른 이름은 이 정리기의 소관이 아니다');
    }

    /**
     * 7.2.0 이설이 만든 **접미사 두 겹** 이름도 정리 모집단에 든다.
     *
     * 이설 마이그레이션은 이미 접미사를 가진 이름을 옮기다 충돌하면 그 뒤에 하나를 더
     * 붙인다(`…-a1b2c3d4-e5f6a7b8`). 정리 쪽 정규식이 대시 그룹을 **하나만** 받으면 그
     * 결과가 영원히 모집단 밖에 남아, 공개 파일 전량 사본이 무한 잔존한다.
     *
     * 이름은 **이설 코드가 실제로 만드는 방식**으로 조립한다 — 두 곳이 같은 상수를 본다.
     *
     * @scenario leftover_target=public_storage_broken, leftover_mode=real_delete, leftover_guard=name_pattern
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_migration_collision_name_enters_the_prune_population(): void
    {
        // 이설이 만드는 형태: `public-storage-broken-<이설 전 접미사>-<충돌 회피 접미사>`
        $collided = StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX
            .StorageLinkHelper::brokenStamp()
            .'-'.bin2hex(random_bytes(4));

        $this->assertMatchesRegularExpression(
            StorageLinkHelper::publicStorageBrokenNamePattern(),
            $collided,
            '이설이 만든 이름이 정리 쪽 이름 규칙에 걸리지 않는다 — 그 사본은 영원히 회수되지 않는다'
        );

        $older = $this->makeDir('storage/app/'.$collided, 50);
        $newer = $this->makeDir('storage/app/'.$this->nameFromBrokenBackupProducer(), 0);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($older, $result['public_storage_broken']);
        $this->assertDirectoryDoesNotExist($older);
        $this->assertDirectoryExists($newer);
    }

    /**
     * 이설 마이그레이션의 이름 판정이 **같은 상수**에서 나온다 (사본 드리프트 차단).
     *
     * 두 곳이 각자 리터럴을 들고 있으면 한쪽만 넓혀도 나머지가 그대로 남는다 — 그 상태는
     * 오류가 아니라 모집단이 조용히 어긋나는 것으로만 나타난다.
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_migration_and_pruner_share_the_broken_backup_name_constants(): void
    {
        $source = (string) file_get_contents(
            base_path('upgrades/data/7.2.0/migrations/04_MovePublicStorageBrokenBackupsOutOfDocroot.php')
        );

        foreach ([
            'StorageLinkHelper::DOCROOT_BROKEN_PREFIX',
            'StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX',
            'StorageLinkHelper::docrootBrokenNamePattern()',
        ] as $symbol) {
            $this->assertStringContainsString(
                $symbol,
                $source,
                '이설 마이그레이션이 이름 규약을 생산자 상수에서 도출하지 않는다 — 리터럴 사본이 갈리면 그 사본은 영원히 회수되지 않는다'
            );
        }

        // 웹 트리 안 이름도 같은 접미사 규칙을 받는다 (접미사 두 겹 허용).
        $this->assertMatchesRegularExpression(
            StorageLinkHelper::docrootBrokenNamePattern(),
            StorageLinkHelper::DOCROOT_BROKEN_PREFIX.'20260101010101-a1b2c3d4-e5f6a7b8'
        );
    }

    /**
     * 코어 업데이트 리포트 로그에 정리 주체가 있다 — **백업 등급 + 최신 1개 보존**.
     *
     * `storage/logs/core_update_{success,failed,failure}_*.log` 는 daily 로테이션 밖이라
     * 프레임워크가 걷지 않고, 이 서비스의 어느 대상군에도 없어 업데이트마다 하나씩 영구히
     * 쌓였다. 같은 서비스가 형제 로그(`logs/browser.log`)는 대상으로 삼는다.
     *
     * 이름은 **생산자 단일 지점**(`CoreUpdateService::updateReportPath()`)이 만드는 규칙을
     * 그대로 쓴다.
     *
     * @scenario leftover_target=core_update_reports, leftover_mode=real_delete, leftover_guard=backup_grade
     *
     * @effects core_update_report_logs_have_a_pruner
     *
     * @return void 반환값 없음
     */
    public function test_core_update_report_logs_follow_backup_retention(): void
    {
        // 생산자가 만드는 이름 규칙을 그대로 확인한다 (경로는 실 저장소라 이름만 취한다).
        $producedName = basename(CoreUpdateService::updateReportPath('failure'));

        $this->assertMatchesRegularExpression(
            CoreUpdateService::UPDATE_REPORT_NAME_PATTERN,
            $producedName,
            '생산자가 만든 이름이 정리 쪽 이름 규칙에 걸리지 않는다 — 리포트가 무한 누적한다'
        );

        $aged = $this->makeFile('storage/logs/core_update_success_20260601_010101.log', 50);
        $latestButAged = $this->makeFile('storage/logs/'.$producedName, 40);
        $dailyLog = $this->makeFile('storage/logs/laravel-2026-06-01.log', 50);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame([$aged], $result['core_update_reports']);
        $this->assertFileDoesNotExist($aged);
        $this->assertFileExists($latestButAged, '최신 1개는 나이와 무관하게 보존해야 한다 (실패 리포트는 진단 자산)');
        $this->assertFileExists($dailyLog, 'daily 드라이버가 자체 정리하는 로그는 대상이 아니다');
    }

    /**
     * 「생산자 finally 하나뿐」이던 산출물 3종에 두 번째 정리 주체가 있다.
     *
     * ① 번들 업데이트 매니페스트(`storage/app/core_pending/`) ② 확장 동기화가 옆으로 치운
     * 잠긴 파일(`*.g7stale_<uniqid>` — 활성 확장 디렉토리 안) ③ composer 폴백 임시
     * 디렉토리(`storage/temp` — 형제 대상군은 `storage/app/temp` 라 다른 경로).
     * 셋 다 강제 종료(FPM 타임아웃·OOM)에서 잔존하고 회수 주체가 없었다.
     *
     * @scenario leftover_target=core_pending, leftover_mode=real_delete, leftover_guard=aged_out
     *
     * @effects finally_only_artifacts_have_a_second_pruner
     *
     * @return void 반환값 없음
     */
    public function test_finally_only_artifacts_have_a_second_pruner(): void
    {
        $manifest = $this->makeFile('storage/app/core_pending/bundled-updates-manifest_abcdefghijklmnop.json', 10);
        $otherPending = $this->makeFile('storage/app/core_pending/core-update-payload.json', 10);

        $stale = $this->makeFile('base/modules/acme-shop/src/Service.php'.ExtensionPendingHelper::STALE_ASIDE_INFIX.uniqid(), 10);
        $freshStale = $this->makeFile('base/plugins/acme-pay/src/Gate.php'.ExtensionPendingHelper::STALE_ASIDE_INFIX.uniqid(), 0);
        $live = $this->makeFile('base/modules/acme-shop/src/Service.php', 10);

        $composerTemp = $this->makeDir('storage/temp/composer-cache', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame([$manifest], $result['core_pending']);
        $this->assertFileDoesNotExist($manifest);
        $this->assertFileExists($otherPending, '접두사가 다른 산출물은 이 대상군의 소관이 아니다');

        $this->assertContains($stale, $result['stale_swap_files']);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($freshStale, '진행 중일 수 있는 최근 잔해는 남긴다');
        $this->assertFileExists($live, '잔해가 아닌 파일을 지웠다');

        $this->assertContains($composerTemp, $result['storage_temp']);
        $this->assertDirectoryDoesNotExist($composerTemp);
    }

    /**
     * 코어 업데이트가 전개한 `_pending` 디렉토리에 정리 주체가 있다.
     *
     * `cleanupPending()` 은 커맨드의 정상 경로와 **처리된** 실패 경로에서만 불린다.
     * 치명 오류·요청 시간 초과·kill(무CLI 웹 워커에서 특히 흔하다)이면 그 코드가 돌지
     * 않아 릴리즈 한 벌 크기의 디렉토리가 영구히 남는데, 형제 대상군(`core_pending`)은
     * 같은 디렉토리의 **매니페스트 파일**만 접두사로 좁혀 본다 (회차 6 이관).
     *
     * @scenario leftover_target=core_pending_staging, leftover_mode=real_delete, leftover_guard=aged_out
     *
     * @effects core_pending_staging_has_a_pruner
     *
     * @return void 반환값 없음
     */
    public function test_core_pending_staging_directories_are_pruned(): void
    {
        $aged = $this->makeDir('storage/app/core_pending/'.CoreUpdateService::PENDING_DIR_PREFIX.'20260701_010101', 5);
        $fresh = $this->makeDir('storage/app/core_pending/'.CoreUpdateService::PENDING_DIR_PREFIX.'20260830_235959', 0);
        $manifest = $this->makeFile('storage/app/core_pending/bundled-updates-manifest_abcdefghijklmnop.json', 5);
        $unrelated = $this->makeDir('storage/app/core_pending/vendor-cache', 5);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertSame([$aged], $result['core_pending_staging']);
        $this->assertDirectoryDoesNotExist($aged);
        $this->assertDirectoryExists($fresh, '진행 중일 수 있는 최근 전개본은 남긴다 — 나이 하한이 그 보호다');
        $this->assertDirectoryExists($unrelated, '생산자 접두사가 아닌 디렉토리는 이 대상군의 소관이 아니다');

        // 형제 대상군(매니페스트)과 겹치지 않는다 — 두 대상군이 같은 항목을 세면 합계가 틀어진다.
        $this->assertSame([$manifest], $result['core_pending']);
        $this->assertNotContains($manifest, $result['core_pending_staging']);
    }

    /**
     * 설치 진행 산출물 3종이 **설치가 끝난 뒤** 회수된다.
     *
     * `storage/installer-state.json` · `storage/installer/runtime.php` ·
     * `storage/{logs,installer}/installer-cmd-output-*.log` — 셋 다 정리 주체가 finalize
     * 하나뿐인데, 이 공정이 대상으로 삼은 배치의 흔한 종착점은 **설치 포기**다. 특히
     * `runtime.php` 는 DB 접속 정보와 APP_KEY 를 담고, 배포 레이아웃 ②에서는 `storage/`
     * 가 문서 루트 안이다 — 누적은 저장 공간이 아니라 **노출** 문제다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=install_completed
     *
     * @effects installer_residue_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_installer_residue_is_pruned_once_installation_completed(): void
    {
        config(['app.installer_completed' => false]);
        $this->makeFile('storage/app/g7_installed', 100);

        $state = $this->makeFile('storage/installer-state.json', 10);
        $runtime = $this->makeFile('storage/installer/runtime.php', 10);
        $cmdLog = $this->makeFile('storage/logs/installer-cmd-output-0123456789abcdef.log', 10);
        $cmdLogAlt = $this->makeFile('storage/installer/installer-cmd-output-fedcba9876543210.log', 10);
        $fresh = $this->makeFile('storage/installer/runtime-notes.txt', 0);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        foreach ([$state, $runtime, $cmdLog, $cmdLogAlt] as $path) {
            $this->assertContains(
                $path,
                $result['installer_probes'],
                '설치 잔여물에 정리 주체가 없다 — 설치를 포기한 사이트에 DB 접속 정보가 영구 잔존한다'
            );
            $this->assertFileDoesNotExist($path);
        }

        $this->assertFileExists($fresh, '설치 잔여물이 아닌 파일을 지웠다');
    }

    /**
     * 설치가 **아직 끝나지 않았으면** 그 산출물을 임시 보존일로 걷지 않는다.
     *
     * 이 셋은 설치를 재개하면 다시 읽히는 파일이다 — 임시 등급(기본 3일)으로 걷으면 며칠
     * 뒤 재개하려는 운영자의 설치가 처음으로 되돌아간다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=resumable_install
     *
     * @effects resumable_install_state_survives_temp_retention
     *
     * @return void 반환값 없음
     */
    public function test_resumable_installation_state_is_not_pruned_by_temp_retention(): void
    {
        config(['app.installer_completed' => false]);

        $state = $this->makeFile('storage/installer-state.json', 10);
        $runtime = $this->makeFile('storage/installer/runtime.php', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertNotContains($state, $result['installer_probes']);
        $this->assertFileExists($state, '재개 가능한 설치의 상태 파일을 지웠다 — 운영자의 설치가 처음으로 되돌아간다');
        $this->assertFileExists($runtime);
    }

    /**
     * 이름 판정이 **생산자 상수에서 도출**된다 — 대상군별 사본 드리프트 차단.
     *
     * 종전에는 이 계약이 `installer_probes` 한 군만 덮었고 나머지는 리터럴 사본이었다
     * (/ F-5). 정리 주체가 생산자 상수를 **실제로 참조**하는지
     * 소스에서 확인한다 — 값이 우연히 같은 상태와 「같은 곳을 본다」를 구분하기 위해서다.
     */
    /**
     * 루트 진입 임시 파일 대상이 **각자의 갱신기 클래스**에 매핑된다.
     *
     * 종전에는 세 대상 전부를 `RootHtaccessUpdater` 상수로 판정했다 — web.config 의 생산자는
     * `RootWebConfigUpdater` 인데, 지금은 두 클래스가 이름 규칙을 공통 추상화에서 상속해 값이 같지만
     * web.config 갱신기가 그 규칙을 재정의하면 프루너가 잘못된 규칙으로 web.config 잔해를 판정한다.
     *
     * @scenario leftover_target=root_entry_temp
     *
     * @effects root_entry_temp_files_have_a_second_pruner
     *
     * @return void 반환값 없음
     */
    public function test_root_entry_temp_targets_map_each_file_to_its_updater(): void
    {
        $map = (new \ReflectionClass(StorageLeftoverPruneService::class))->getConstant('ROOT_ENTRY_TEMP_TARGETS');

        $this->assertIsArray($map);
        $this->assertSame(
            RootWebConfigUpdater::class,
            $map['web.config'] ?? null,
            'web.config 잔해는 RootWebConfigUpdater 가 판정해야 한다 — RootHtaccessUpdater 로 판정하면 두 갱신기가 규칙을 달리하는 순간 모집단이 어긋난다'
        );
        $this->assertSame(RootHtaccessUpdater::class, $map['.htaccess'] ?? null, '.htaccess 잔해는 RootHtaccessUpdater 가 판정해야 한다');
    }

    /**
     * 정리 서비스 소스가 잔여물 이름 규칙을 손으로 적지 않고 생산자 클래스의 상수와 패턴 메서드에서 가져오는지 확인한다.
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_prune_name_rules_are_derived_from_producer_constants(): void
    {
        $pruner = (string) file_get_contents(base_path('app/Services/StorageLeftoverPruneService.php'));

        foreach ([
            'StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX',
            'StorageLinkHelper::publicStorageBrokenNamePattern()',
            'StorageLinkHelper::DOCROOT_BROKEN_PREFIX',
            'StorageLinkHelper::docrootBrokenNamePattern()',
            // 루트 진입 임시 파일 이름 규칙은 **대상별 갱신기**(`$updater`)에서 도출한다 —
            // web.config 는 RootWebConfigUpdater 가 판정한다. 매핑이 두 갱신기 클래스를 지목하고,
            // 스윕은 매핑된 클래스의 상수를 쓴다.
            'RootHtaccessUpdater::class',
            'RootWebConfigUpdater::class',
            '$updater::TEMP_INFIX',
            '$updater::TEMP_SUFFIX_PATTERN',
            'ExtensionPendingHelper::STALE_ASIDE_NAME_PATTERN',
            'ExtensionManager::COMPOSER_FALLBACK_TEMP_DIR',
            'CoreUpdateService::UPDATE_REPORT_PREFIX',
            'CoreUpdateService::UPDATE_REPORT_NAME_PATTERN',
            'LanguagePackService::PENDING_DIR',
            'LanguagePackService::PENDING_EXTRACT_NAME_PATTERN',
        ] as $symbol) {
            $this->assertStringContainsString(
                $symbol,
                $pruner,
                '정리 주체가 이름 규약을 리터럴로 다시 적었다 — 생산자가 규칙을 바꾸면 모집단이 조용히 빈다'
            );
        }

        // 생산자 쪽도 자기 상수로 이름을 만든다 — 한쪽만 상수화하면 여전히 갈린다.
        $this->assertStringContainsString(
            'self::PUBLIC_STORAGE_BROKEN_PREFIX.self::brokenStamp()',
            (string) file_get_contents(base_path('app/Extension/Helpers/StorageLinkHelper.php')),
            '손상 백업 생산자가 이름을 인라인으로 조립한다 — 형제 백업군은 모두 이름 SSoT 를 쓴다'
        );
        // 생산자는 공통 추상화다 — `.htaccess`·`web.config` 갱신기가 같은 쓰기 경로를 상속한다 (G3 회차 16 W2 C-1).
        $this->assertStringContainsString(
            'self::TEMP_INFIX.$suffix',
            (string) file_get_contents(base_path('app/Extension/Helpers/AbstractRootMarkerFileUpdater.php')),
            '루트 진입 임시 파일 생산자가 이름을 인라인으로 조립한다'
        );
        // 언어팩 추출 디렉토리 생산자는 이름을 한 지점에서만 만든다 — 다섯 곳의 인라인
        // `base_path('lang-packs/_pending/'.…)` 가 남아 있으면 그중 하나만 규칙을 바꿔도 모집단이 갈린다.
        $langPack = (string) file_get_contents(base_path('app/Services/LanguagePackService.php'));
        $this->assertStringNotContainsString(
            "base_path('lang-packs/_pending/'",
            $langPack,
            '언어팩 추출 디렉토리 생산자가 이름을 인라인으로 조립한다'
        );
        $this->assertStringContainsString(
            'self::PENDING_DIR.DIRECTORY_SEPARATOR.$name',
            $langPack,
            '언어팩 추출 디렉토리 이름 조립 지점이 상수를 쓰지 않는다'
        );

        $this->assertStringContainsString(
            'self::STALE_ASIDE_INFIX.uniqid()',
            (string) file_get_contents(base_path('app/Extension/Helpers/ExtensionPendingHelper.php')),
            '확장 동기화 잔해 생산자가 이름을 인라인으로 조립한다'
        );

        // vendor 번들 스테이징은 생산자가 이 파티션 밖이라 **소스에서 도출**한다.
        $bundler = (string) file_get_contents(base_path('app/Extension/Vendor/VendorBundler.php'));
        $this->assertSame(
            1,
            preg_match('#storage_path\(.app/(vendor-bundle-staging)/..uniqid\(.(build-).#', $bundler, $m),
            'vendor 번들 스테이징 생산자의 이름 규약을 찾지 못했다 — 규약이 바뀌었다면 정리 주체도 함께 고친다'
        );

        $this->assertStringContainsString("'".$m[1]."'", $pruner, 'vendor 번들 스테이징 디렉토리 이름이 생산자와 다르다');
        $this->assertStringContainsString("'".$m[2]."'", $pruner, 'vendor 번들 스테이징 파일 접두사가 생산자와 다르다');

        // 번들 매니페스트 생산자는 트레이트라 상수를 이름으로 참조할 수 없다(PHP 8.2) —
        // 인스톨러 축과 같은 방식으로 소스에서 도출한다.
        $prompt = (string) file_get_contents(base_path('app/Console/Commands/Core/Concerns/BundledExtensionUpdatePrompt.php'));
        $this->assertSame(
            1,
            preg_match("/BUNDLED_MANIFEST_PREFIX = '([^']+)'/", $prompt, $pm),
            '번들 매니페스트 생산자의 이름 접두사를 찾지 못했다'
        );
        $this->assertStringContainsString("'".$pm[1]."'", $pruner, '번들 매니페스트 접두사가 생산자와 다르다');
    }

    /**
     * 인스톨러 상태 파일의 tmp 스윕이 **형제 두 지점과 같은 판정**을 쓴다.
     *
     * 종전에는 ① 형식 판정이 없어 운영자 파일(`installer-state.json.tmp.backup`)이 300초
     * 뒤 조용히 사라졌고 ② `@filemtime` 실패를 「아주 오래됨」으로 읽어 지웠다(fail-open —
     * `false < (time()-300)` 은 PHP 8 에서 true). 형제 둘은 정반대이고, 그중 하나는 이
     * 스윕을 **선례로 명시 인용**하면서 자기만 게이트를 받았다.
     *
     * @effects installer_state_tmp_sweep_shares_the_sibling_judgement
     *
     * @return void 반환값 없음
     */
    public function test_installer_state_tmp_sweep_shares_the_format_and_mtime_gate(): void
    {
        // 형식 게이트의 **값**은 이름 생성 SSoT(`functions.php` 의 `installerUniqueSuffix`) 곁에
        // 한 번만 적힌다 — 상태 파일·런타임 파일 두 스윕은 그 상수를 가리킨다. 종전에는 파일마다
        // 리터럴이었고, 런타임 쪽이 `{8}` 로 갈려 생성 이름(16진 16자)이 영원히 매치되지 않아
        // 스윕 모집단이 0 이었다.
        $functions = (string) file_get_contents(base_path('public/install/includes/functions.php'));

        $this->assertSame(
            1,
            preg_match("/define\('INSTALLER_TMP_SUFFIX_PATTERN',\s*'([^']+)'\)/", $functions, $m),
            '임시 파일 형식 게이트의 SSoT 가 이름 생성 곁에 없다'
        );
        $this->assertSame(
            1,
            preg_match("/define\('INSTALLER_TMP_SUFFIX_BYTES',\s*(\d+)\)/", $functions, $bytes),
            '임시 파일 접미사 바이트 수 상수가 없다 — 생성과 판정이 같은 값을 볼 수 없다'
        );
        $this->assertStringContainsString(
            '{'.((int) $bytes[1] * 2).',}',
            $m[1],
            '형식 게이트의 하한이 생성 바이트 수(×2 = 16진 자릿수)와 다르다'
        );

        $this->assertSame(
            RootHtaccessUpdater::TEMP_SUFFIX_PATTERN,
            $m[1],
            '세 지점의 임시 파일 형식 판정이 갈렸다 — 같은 규칙이어야 한다'
        );

        // 중간 조각(`.tmp.`)도 같은 축이다 — 패턴만 잠그고 infix 는 세 곳 리터럴이었다.
        // 한쪽이 `.temp.` 로 갈리면 그 스윕의 모집단이 조용히 0 이 된다.
        $this->assertSame(
            1,
            preg_match("/define\('INSTALLER_TMP_INFIX',\s*'([^']+)'\)/", $functions, $infix),
            '임시 파일 중간 조각의 SSoT 가 이름 생성 곁에 없다'
        );
        $this->assertSame(
            RootHtaccessUpdater::TEMP_INFIX,
            $infix[1],
            '인스톨러와 코어의 임시 파일 중간 조각이 갈렸다 — 프루너는 코어 상수로 인스톨러 파일을 찾는다'
        );

        foreach ([
            'installer-state.php' => ['INSTALLER_STATE_TMP_SUFFIX_PATTERN', 'INSTALLER_STATE_TMP_INFIX'],
            'installer-runtime.php' => ['INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN', 'INSTALLER_RUNTIME_TMP_INFIX'],
        ] as $file => [$patternConstant, $infixConstant]) {
            $source = (string) file_get_contents(base_path('public/install/includes/'.$file));

            $this->assertStringContainsString(
                "define('".$patternConstant."', INSTALLER_TMP_SUFFIX_PATTERN)",
                $source,
                $file.' 의 형식 게이트가 SSoT 를 가리키지 않는다 — 리터럴 사본은 이미 한 번 갈렸다'
            );
            $this->assertStringContainsString(
                "define('".$infixConstant."', INSTALLER_TMP_INFIX)",
                $source,
                $file.' 의 중간 조각이 SSoT 를 가리키지 않는다 — 리터럴 사본은 갈려도 아무것도 울리지 않는다'
            );
            $this->assertStringContainsString(
                $infixConstant.'.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES)',
                $source,
                $file.' 의 생산자가 판정과 다른 바이트 수로 이름을 만든다'
            );
            $this->assertStringContainsString(
                "preg_match({$patternConstant}",
                $source,
                $file.' 이 형식 게이트를 정의만 하고 스윕에서 쓰지 않는다'
            );
            $this->assertStringContainsString(
                '$mtime !== false && $mtime < time() - 300',
                $source,
                $file.' 이 mtime 조회 실패를 「아주 오래됨」으로 읽어 지운다 (fail-open)'
            );
        }

        // 실측: PHP 8 에서 `false < (time() - 300)` 은 true 다 — 종전 조건이 fail-open 이었다는 근거.
        $this->assertTrue(time() - 300 > false);
    }

    /**
     * 잔존물을 만들 수 있는 지점의 **차단 가능 함수 가드**가 있다.
     *
     * ① `FileHandleHelper` 의 임시 스크립트 생성은 `tempnam`/`sys_get_temp_dir` 을 무가드로
     * 불렀다 — 막히면 치명이고, `tempnam` 이 false 를 돌리면 `false.'.ps1'` 이 `'.ps1'` 이
     * 되어 **현재 작업 디렉토리**에 파일을 만든다(강제 종료 시 `finally` 가 돌지 않아 잔존).
     * ② 인스톨러 워커 id 는 `bin2hex(random_bytes(8))` 무가드였다 — 비밀이 아니라 겹치지만
     * 않으면 되는 값이라 `installerUniqueSuffix()` 대상인데, CSPRNG 없는 호스팅에서는 그 한
     * 줄로 단계별 설치가 500 이 된다.
     *
     * @effects leftover_producing_call_sites_guard_blockable_functions
     *
     * @return void 반환값 없음
     */
    public function test_leftover_producing_call_sites_guard_blockable_functions(): void
    {
        $handle = (string) file_get_contents(base_path('app/Extension/Helpers/FileHandleHelper.php'));

        $this->assertStringContainsString(
            "ExecutionEnvironment::hasFunction('sys_get_temp_dir')",
            $handle,
            'sys_get_temp_dir 무가드 호출 — 차단 호스팅에서 PHP 8 은 함수 테이블에서 지우므로 Error(치명)다'
        );
        $this->assertStringContainsString(
            "ExecutionEnvironment::hasFunction('tempnam')",
            $handle,
            'tempnam 무가드 호출'
        );
        $this->assertStringContainsString(
            '$tempScript === false',
            $handle,
            'tempnam 실패를 검사하지 않는다 — 빈 경로 조립이 작업 디렉토리에 잔존물을 만든다'
        );

        $installer = (string) file_get_contents(base_path('public/install/includes/installer-state.php'));

        $this->assertStringNotContainsString(
            'bin2hex(random_bytes(8))',
            $installer,
            '겹치지 않으면 되는 값에 CSPRNG 를 무가드로 쓴다 — 그 한 줄로 단계별 설치가 500 이 된다'
        );
        $this->assertStringContainsString(
            '$newWorkerId = installerUniqueSuffix(8);',
            $installer,
            '워커 id 가 차단 내성 단일 지점을 쓰지 않는다'
        );
    }

    /**
     * 누적 통보 문구가 **다국어 키**로 나간다 — 형제 통보 둘과 같은 통로.
     *
     * 종전에는 이 문구만 한국어 하드코딩이라 영어 운영자에게 한국어가 그대로 나갔다.
     * 모집단 판정도 접두사만 봐서 정리 주체(형식 판정)와 숫자가 갈렸다.
     *
     * @effects accumulated_backup_notice_is_translated_and_format_gated
     *
     * @return void 반환값 없음
     */
    public function test_accumulated_backup_notice_is_translated_and_format_gated(): void
    {
        $source = (string) file_get_contents(base_path('app/Extension/Helpers/StorageLinkHelper.php'));

        $this->assertStringContainsString(
            "'settings.core_update.storage_link_backup_accumulated'",
            $source,
            '누적 통보 문구가 하드코딩이다 — 영어 운영자에게 한국어가 나간다'
        );
        $this->assertStringContainsString(
            'self::ACCUMULATED_BACKUP_WARN_THRESHOLD',
            $source,
            '누적 임계가 리터럴이다'
        );
        $this->assertStringContainsString(
            'static::publicStorageBrokenNamePattern()',
            $source,
            '누적 모집단이 접두사만 본다 — 정리 주체(형식 판정)와 숫자가 갈린다'
        );
        $this->assertStringContainsString(
            'static::docrootBrokenNamePattern()',
            $source,
            '웹 트리 안의 사본이 누적 모집단에서 빠졌다 — 위험 등급이 더 높은 쪽이다'
        );

        foreach (['ko', 'en'] as $locale) {
            $message = (string) __(
                'settings.core_update.storage_link_backup_accumulated',
                ['count' => 3, 'path' => '/tmp'],
                $locale
            );

            $this->assertStringNotContainsString(
                ':count',
                $message,
                $locale.' 문구의 치환 자리가 채워지지 않았다 — 키가 없어 원문이 그대로 나간다'
            );
        }
    }

    /**
     * 인스톨러 원자 교체 임시 파일(`runtime.php.tmp.*` · `installer-state.json.tmp.*`)에
     * **두 번째 정리 주체**가 있다.
     *
     * 생산자 스윕은 **다음 쓰기 때만** 돈다 — 설치를 포기한 사이트에서는 다시 쓰지 않으므로
     * 영구 잔존한다. `runtime.php.tmp.*` 는 DB 자격증명·APP_KEY 를 담고 확장자가 `.php` 가
     * 아니라 배포 레이아웃 ②에서 평문으로 나간다.
     *
     * 이 tmp 는 설치를 재개해도 **다시 읽히지 않는다** — 그래서 상태 파일과 달리 설치 미완
     * 사이트에서도 임시 등급 그대로 걷는다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=aged_out
     *
     * @effects installer_probe_leftovers_are_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_installer_atomic_write_tmp_files_are_pruned_even_when_installation_is_unfinished(): void
    {
        config(['app.installer_completed' => false]);

        $staleRuntime = $this->makeFile('storage/installer/runtime.php.tmp.0123456789abcdef', 10);
        $staleState = $this->makeFile('storage/installer-state.json.tmp.fedcba9876543210', 10);
        $freshRuntime = $this->makeFile('storage/installer/runtime.php.tmp.00112233445566778899', 0);
        // 생성 규칙(16진 접미사)과 다른 이름 — 운영자가 손으로 둔 파일이다.
        $operator = $this->makeFile('storage/installer-state.json.tmp.backup', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        foreach ([$staleRuntime, $staleState] as $path) {
            $this->assertContains($path, $result['installer_probes'], '인스톨러 tmp 에 두 번째 정리 주체가 없다 — 설치를 포기한 사이트에 DB 자격증명 사본이 영구 잔존한다');
            $this->assertFileDoesNotExist($path);
        }

        $this->assertFileExists($freshRuntime, '진행 중일 수 있는 신선한 tmp 를 지웠다');
        $this->assertFileExists($operator, '생성 규칙과 다른 이름을 지웠다 — 운영자 파일이 조용히 사라진다');
    }

    /**
     * 파손 상태 사본(`installer-state.json.corrupt.<YmdHis>.<sha8>`)이 백업 등급으로 걷힌다.
     *
     * 생산자는 상한 5 로 **만들기만** 하고 지우는 지점이 0 이었다. 마스킹 전 비밀(구버전 상태
     * 파일)을 담을 수 있으므로 누적은 노출 문제다. 진단 자산이라 최신 1개는 남긴다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=latest_backup_kept
     *
     * @effects backup_directory_name_produced_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_state_copies_follow_backup_retention(): void
    {
        $older = $this->makeFile('storage/installer-state.json.corrupt.20260701010101.deadbeef', 50);
        $middle = $this->makeFile('storage/installer-state.json.corrupt.20260702010101.cafebabe', 45);
        $newest = $this->makeFile('storage/installer-state.json.corrupt.20260801010101.01234567', 40);
        $foreign = $this->makeFile('storage/installer-state.json.corrupt.notes', 50);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertArrayHasKey('installer_state_corrupt', $result, '파손 사본 대상군이 없다');
        $this->assertEqualsCanonicalizing([$older, $middle], $result['installer_state_corrupt']);
        $this->assertFileDoesNotExist($older);
        $this->assertFileDoesNotExist($middle);
        $this->assertFileExists($newest, '최신 사본은 나이와 무관하게 남아야 한다 — 진단 근거다');
        $this->assertFileExists($foreign, '생산자 규칙과 다른 이름을 지웠다');
    }

    /**
     * 상태 파일의 잠금 파일(`installer-state.json.lock`)이 정리 모집단에 든다.
     *
     * `installerWithStateLock` 이 `fopen(…, 'c')` 로 만들고, finalize 가 상태 파일과 함께 지운다.
     * 그런데 설치를 포기한 사이트에서는 finalize 가 돌지 않아 상태 파일과 같은 이유로 영구
     * 잔존했다 — 상태 파일과 **같은 등급**(설치 완료 뒤 임시, 미완이면 재개 보존)으로 걷는다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=install_completed
     *
     * @effects installer_residue_is_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_state_lock_file_follows_the_state_file_retention(): void
    {
        config(['app.installer_completed' => false]);
        $this->makeFile('storage/app/g7_installed', 100);

        $lock = $this->makeFile('storage/installer-state.json.lock', 10);
        $fresh = $this->makeFile('storage/installer-state.json.lockfile-notes', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($lock, $result['installer_probes'], '잠금 파일에 정리 주체가 없다 — 설치를 포기한 사이트에 영구 잔존한다');
        $this->assertFileDoesNotExist($lock);
        $this->assertFileExists($fresh, '이름이 다른 파일을 지웠다');
    }

    /**
     * 설치가 아직 끝나지 않아 재개할 수 있을 때 오래된 인스톨러 상태 잠금 파일을 지우지 않고 남기는지 확인한다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete, leftover_guard=resumable_install
     *
     * @effects resumable_install_state_survives_temp_retention
     *
     * @return void 반환값 없음
     */
    public function test_state_lock_file_is_kept_while_installation_is_resumable(): void
    {
        config(['app.installer_completed' => false]);

        $lock = $this->makeFile('storage/installer-state.json.lock', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertNotContains($lock, $result['installer_probes']);
        $this->assertFileExists($lock, '재개 가능한 설치의 잠금 파일을 지웠다 — 상태 파일과 등급이 갈린다');
    }

    /**
     * 잠금 파일 이름 규칙이 생산자·finalize·프루너 세 곳에서 **같은 상수**다.
     *
     * finalize 본문(상태 파일과 그 잠금 파일 정리)은 웹·터미널 설치가 함께 쓰는
     * `public/install/includes/env-finalize.php` 에 있고, 웹 엔드포인트 `public/install/api/finalize-env.php` 는 그것을 싣는다.
     * 그래서 상수 사용은 공유 본문에서, 위임 사실은 엔드포인트에서 본다.
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_state_lock_name_rule_is_shared_by_producer_finalize_and_pruner(): void
    {
        $producer = (string) file_get_contents(base_path('public/install/includes/installer-state.php'));
        $endpoint = (string) file_get_contents(base_path('public/install/api/finalize-env.php'));
        $finalize = (string) file_get_contents(base_path('public/install/includes/env-finalize.php'));
        $this->assertStringContainsString("require_once __DIR__.'/../includes/env-finalize.php'", $endpoint, 'finalize 엔드포인트가 공유 본문을 싣지 않는다');
        $this->assertStringNotContainsString("'.lock'", $endpoint);
        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);

        $this->assertSame(
            1,
            preg_match("/define\(\s*'INSTALLER_STATE_LOCK_SUFFIX'\s*,\s*'([^']+)'\s*\)/", $producer, $m),
            '잠금 파일 접미사 상수가 생산자에 없다'
        );
        $this->assertSame($m[1], $reflection->getConstant('INSTALLER_STATE_LOCK_SUFFIX'), '프루너의 잠금 파일 접미사가 생산자와 다르다 — 모집단이 조용히 빈다');

        $this->assertStringContainsString('STATE_PATH.INSTALLER_STATE_LOCK_SUFFIX', $producer, '생산자가 잠금 파일 이름을 리터럴로 조립한다');
        $this->assertStringNotContainsString("STATE_PATH.'.lock'", $producer);
        $this->assertStringContainsString('INSTALLER_STATE_LOCK_SUFFIX', $finalize, 'finalize 정리가 잠금 파일 이름을 리터럴로 조립한다');
        $this->assertStringNotContainsString("'.lock'", $finalize);
    }

    /**
     * 파손 사본 이름 규칙이 **생산자 상수**와 같다 (사본 드리프트 차단).
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_state_name_rules_match_the_installer_producer_constants(): void
    {
        $source = (string) file_get_contents(base_path('public/install/includes/installer-state.php'));
        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);

        foreach ([
            'INSTALLER_STATE_CORRUPT_INFIX' => 'INSTALLER_STATE_CORRUPT_INFIX',
            'INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN' => 'INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN',
        ] as $producerConstant => $consumerConstant) {
            $this->assertSame(
                1,
                preg_match("/define\(\s*'".$producerConstant."'\s*,\s*'([^']+)'\s*\)/", $source, $m),
                $producerConstant.' 정의를 생산자에서 찾지 못했다'
            );
            $this->assertSame($m[1], $reflection->getConstant($consumerConstant), $consumerConstant.' 가 생산자와 다르다 — 모집단이 조용히 빈다');
        }

        // 생산자는 그 상수로 이름을 만든다 — 한쪽만 상수화하면 여전히 갈린다.
        $this->assertStringContainsString(
            'STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX.date(INSTALLER_STATE_CORRUPT_STAMP_FORMAT)',
            $source,
            '파손 사본 생산자가 이름을 인라인 리터럴로 조립한다'
        );
        $this->assertStringContainsString('preg_match(INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN', $source, '생산자 자신의 나열이 형식 게이트를 쓰지 않는다');
    }

    /**
     * 쓰기 가능 실측 프로브(`.installer-write-probe-*`)가 정리 모집단에 든다.
     *
     * 생성 직후 지우지만, fopen 과 unlink 사이에서 프로세스가 죽으면 남는다. 정리 스윕도
     * 프루너도 이 접두사를 몰랐다. 점 파일이라 `listDirectoryFiles` 로는
     * 영원히 0건이다 — 그 통로를 쓰지 않는지도 여기서 갈린다.
     *
     * @scenario leftover_target=installer_probe, leftover_mode=real_delete
     *
     * @effects installer_probe_leftovers_are_recognized_by_the_pruner
     *
     * @return void 반환값 없음
     */
    public function test_write_probe_leftovers_enter_the_prune_population(): void
    {
        $stale = [
            $this->makeFile('storage/.installer-write-probe-0123456789abcdef', 10),
            $this->makeFile('storage/framework/cache/.installer-write-probe-0123456789abcdef', 10),
            $this->makeFile('base/bootstrap/cache/.installer-write-probe-0123456789abcdef', 10),
            $this->makeFile('base/lang-packs/_pending/.installer-write-probe-0123456789abcdef', 10),
        ];
        $fresh = $this->makeFile('storage/.installer-write-probe-fedcba9876543210', 0);
        $foreign = $this->makeFile('storage/.installer-write-probe-notes', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        foreach ($stale as $path) {
            $this->assertContains($path, $result['installer_probes'], $path.' 을 지우는 주체가 없다');
            $this->assertFileDoesNotExist($path);
        }

        $this->assertFileExists($fresh);
        $this->assertFileExists($foreign);
    }

    /**
     * 프루너는 `filemtime()` 을 **무가드로** 읽지 않는다.
     *
     * `filemtime()` 실패는 `false` 이고 `false < (int)` 는 PHP 8 에서 참이다 — 그 비교가
     * 삭제 조건이면 조회 실패가 곧 삭제(fail-open)다. `isFresh()` 는 실패를 보존으로 읽는데
     * 레이트리밋 파일 분기만 그 헬퍼를 지나지 않았다. 조회 실패는 프로세스 안에서 흉내낼 수
     * 없으므로 소스로 잠근다: `@` 없는 호출 0 + 모든 호출이 `=== false` 분기를 갖는다.
     *
     * @effects pruner_mtime_reads_are_failure_guarded
     *
     * @return void 반환값 없음
     */
    public function test_pruner_never_reads_filemtime_without_a_failure_guard(): void
    {
        $source = $this->strippedServiceSource();

        $this->assertSame(
            0,
            preg_match_all('/(?<![@\w])filemtime\(/', $source, $bare),
            '@ 없는 filemtime() 호출이 있다 — 조회 실패가 「아주 오래됨」으로 읽혀 삭제된다: '.json_encode($bare[0])
        );

        preg_match_all('/@filemtime\([^;]*;\s*(.{0,160})/s', $source, $calls, PREG_SET_ORDER);
        $this->assertNotEmpty($calls, 'filemtime 호출을 하나도 찾지 못했다 — 검사 모집단이 비었다');

        foreach ($calls as $call) {
            $this->assertMatchesRegularExpression(
                '/=== false|!== false/',
                $call[1],
                'filemtime 결과를 false 와 대조하지 않는 호출이 있다: '.trim($call[0])
            );
        }

        // 실측: 종전 조건 `filemtime($f) < $cutoff` 가 조회 실패에서 참이었다는 근거.
        $this->assertTrue(time() > false);

        // 보존 **방향**까지 잰다. `=== false` 분기가 있어도 실패를 `0`(가장
        // 오래됨)으로 접으면 백업 정렬에서 그 항목이 맨 뒤로 밀려 「최신 1개 보존」의 밖이 된다 —
        // 규정 「조회 실패는 보존」의 반대 방향이다. 실패는 **최신**으로 읽어 보존 쪽에 둔다.
        $this->assertDoesNotMatchRegularExpression(
            '/=== false \? 0 :/',
            $source,
            'filemtime 조회 실패를 0(최고령)으로 접는다 — 정렬에서 삭제 후보 쪽으로 밀린다'
        );
        $this->assertMatchesRegularExpression(
            '/=== false \? PHP_INT_MAX :/',
            $source,
            'filemtime 조회 실패가 최신(보존) 쪽으로 정렬되지 않는다'
        );
    }

    /**
     * 레이트리밋 파일은 헬퍼(`isFresh`)로 나이를 재고, 오래된 것은 지운다 — 가드를 넣으며
     * 분기를 사문으로 만들지 않았는지 동작으로 확인한다.
     *
     * @effects pruner_mtime_reads_are_failure_guarded, aged_leftovers_are_pruned_after_retention
     *
     * @return void 반환값 없음
     */
    public function test_stale_rate_limit_file_is_still_pruned_through_the_guarded_helper(): void
    {
        $stale = $this->makeFile('storage/installer-probe-rate.json', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($stale, $result['installer_probes']);
        $this->assertFileDoesNotExist($stale);
    }

    /**
     * 쓰기 프로브 나열은 `glob` 이 없어도 **`scandir` 로** 이어진다.
     *
     * 종전에는 `glob` 차단 시 빈 배열로 돌아가 11개 디렉토리의 프로브 잔해에 정리 주체가
     * 0 이었다 — 그 호스팅이 곧 이 프루너가 대상으로 삼는 배치다. `disable_functions` 는
     * 프로세스 안에서 만들 수 없으므로 나열 헬퍼에 경로를 명시해 `scandir` 분기를 직접 잰다.
     *
     * @effects write_probe_listing_survives_disabled_glob
     *
     * @return void 반환값 없음
     */
    public function test_write_probe_listing_falls_back_to_scandir_when_glob_is_unavailable(): void
    {
        $probe = $this->makeFile('storage/.installer-write-probe-0123456789abcdef', 10);
        $this->makeFile('storage/.installer-write-probe-notes', 10);
        $this->makeFile('storage/unrelated.txt', 10);

        $method = new \ReflectionMethod(StorageLeftoverPruneService::class, 'listFilesByPrefix');
        $listed = $method->invoke($this->service, dirname($probe), '.installer-write-probe-', false);

        $this->assertContains($probe, $listed, 'glob 없는 분기가 점 파일 프로브를 찾지 못한다');
        $this->assertContains(dirname($probe).DIRECTORY_SEPARATOR.'.installer-write-probe-notes', $listed, '접두사 나열은 형식을 보지 않는다 — 형식 판정은 호출부 몫이다');
        $this->assertNotContains(dirname($probe).DIRECTORY_SEPARATOR.'unrelated.txt', $listed);

        $viaGlob = $method->invoke($this->service, dirname($probe), '.installer-write-probe-', true);
        $this->assertEqualsCanonicalizing($listed, $viaGlob, 'glob 분기와 scandir 분기의 모집단이 다르다');

        $body = $this->serviceFunctionBody('pruneInstallerWriteProbes');
        $this->assertStringContainsString('listFilesByPrefix(', $body, '쓰기 프로브 스윕이 폴백 있는 나열 헬퍼를 쓰지 않는다');
        $this->assertStringNotContainsString("hasFunction('glob')", $body, '쓰기 프로브 스윕이 glob 부재를 「정리 없음」으로 접는다');
    }

    /**
     * 서비스 소스에서 주석을 지운다 — 주석 속 `filemtime(` 언급이 모집단에 끼지 않게.
     *
     * @return string 주석 제거 소스
     */
    private function strippedServiceSource(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(app_path('Services/StorageLeftoverPruneService.php'))) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * 서비스의 한 메서드 본문 (주석 제거).
     *
     * @param  string  $name  메서드명
     * @return string 본문
     */
    private function serviceFunctionBody(string $name): string
    {
        $source = $this->strippedServiceSource();
        $start = strpos($source, 'function '.$name.'(');
        $this->assertNotFalse($start, $name.' 메서드를 찾지 못했다');
        $open = strpos($source, '{', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        $this->fail($name.' 본문의 끝을 찾지 못했다');
    }

    /**
     * 프루너가 훑는 프로브 루트가 인스톨러가 **실제로 점검하는 디렉토리 목록**과 같다.
     *
     * @effects write_probe_roots_match_the_installer_required_directories
     *
     * @return void 반환값 없음
     */
    public function test_write_probe_roots_match_the_installer_required_directories(): void
    {
        $source = (string) file_get_contents(base_path('public/install/includes/config.php'));

        $this->assertSame(1, preg_match("/define\('REQUIRED_DIRECTORIES',\s*\[(.*?)\]\);/s", $source, $m), 'REQUIRED_DIRECTORIES 를 찾지 못했다');
        preg_match_all("/'([^']+)'\s*=>/", $m[1], $keys);

        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);
        $roots = (array) $reflection->getConstant('INSTALLER_WRITE_PROBE_ROOTS');

        $this->assertEqualsCanonicalizing($keys[1], $roots, '프로브 루트가 인스톨러 점검 목록과 다르다 — 빠진 디렉토리의 잔해는 모집단 밖이다');
    }

    /**
     * 언어팩 `_pending` 추출 디렉토리(UUID · `preview-<UUID>`)가 스테이징 정리에 든다.
     *
     * `cleanupPending` 은 `catch`/`finally` 뿐이라 강제 종료 시 남는데, 스테이징 정리는
     * 확장 세 종만 봤다. 운영자가 손으로 둔 팩(식별자 이름)은 불가침이다.
     *
     * @scenario leftover_target=update_staging, leftover_mode=real_delete, leftover_guard=normal_pending_untouched
     *
     * @effects aged_leftovers_are_pruned_after_retention, normal_pending_directories_are_untouched
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_pending_extracts_are_pruned_and_named_packs_are_kept(): void
    {
        $stale = $this->makeDir('base/lang-packs/_pending/0f8fad5b-d9cb-469f-a165-70867728950e', 10);
        $preview = $this->makeDir('base/lang-packs/_pending/preview-7c9e6679-7425-40de-944b-e07fc1f90ae7', 10);
        $fresh = $this->makeDir('base/lang-packs/_pending/16fd2706-8baf-433b-82eb-8c7fada847da', 0);
        $named = $this->makeDir('base/lang-packs/_pending/g7-core-ja', 10);

        $result = $this->service->prune(days: 3, backupDays: 30, dryRun: false);

        $this->assertContains($stale, $result['staging']);
        $this->assertContains($preview, $result['staging']);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryDoesNotExist($preview);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($named, '식별자 이름의 대기 언어팩을 지웠다 — 운영자가 둔 팩이 사라진다');
    }

    /**
     * `@filemtime` 조회 실패는 **보존**이다 (fail-closed).
     *
     * 이 분기를 태우는 픽스처가 0 이었다 — `$mtime === false ||` 를 지워도 초록이었다.
     * `(int) false === 0` 이면 `0 > cutoff` 가 언제나 거짓이라 삭제로 기운다.
     *
     * @effects mtime_lookup_failure_keeps_the_leftover
     *
     * @return void 반환값 없음
     */
    public function test_mtime_lookup_failure_is_treated_as_fresh(): void
    {
        $isFresh = new \ReflectionMethod($this->service, 'isFresh');

        $missing = $this->fixtureRoot.DIRECTORY_SEPARATOR.'does-not-exist';
        $this->assertFileDoesNotExist($missing);

        $this->assertTrue(
            $isFresh->invoke($this->service, $missing, time() + 86400),
            'mtime 을 읽지 못한 항목을 「아주 오래됨」으로 읽는다 — 진행 중인 다른 요청의 파일을 지운다'
        );

        // 백업 집합 경로도 같은 판정을 탄다 — 조회 실패 항목은 목록에 오르지 않는다.
        $prune = new \ReflectionMethod($this->service, 'pruneBackupSet');
        $kept = $this->makeDir('storage/app/core_backups/core_20260801_010101', 50);
        $pruned = $prune->invoke($this->service, [
            ['path' => $kept, 'stamp' => '20260801_010101'],
            ['path' => $missing, 'stamp' => '20260701_010101'],
        ], time() - 30 * 86400, true);

        $this->assertSame([], $pruned);
    }

    /**
     * `.env` 보존 사본의 이름 규칙이 **생산자 상수**에서 나온다.
     *
     * 생산자(`backupExistingEnvFile`)는 `'.bak.'.date('Ymd_His')` 리터럴, 프루너는
     * `ENV_BACKUP_PREFIX`/`ENV_BACKUP_NAME_PATTERN` 리터럴 — 양쪽 다 리터럴이라 어느 쪽이
     * 바뀌어도 아무것도 red 가 되지 않았다. `installer_probes` 선례와 같이
     * 생산자 소스에서 상수를 도출해 대조한다.
     *
     * @effects prune_name_rules_come_from_producer_constants
     *
     * @return void 반환값 없음
     */
    public function test_env_backup_name_rules_are_derived_from_producer_constants(): void
    {
        $source = (string) file_get_contents(base_path('public/install/includes/installer-runtime.php'));
        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);

        $this->assertSame(1, preg_match("/define\(\s*'INSTALLER_ENV_BACKUP_INFIX'\s*,\s*'([^']+)'\s*\)/", $source, $infix), '생산자에 .env 백업 이름 상수가 없다');
        $this->assertSame(1, preg_match("/define\(\s*'INSTALLER_ENV_BACKUP_STAMP_FORMAT'\s*,\s*'([^']+)'\s*\)/", $source, $format), '생산자에 .env 백업 스탬프 형식 상수가 없다');

        $this->assertSame('.env'.$infix[1], $reflection->getConstant('ENV_BACKUP_PREFIX'), '프루너 접두사가 생산자와 다르다 — 모집단이 조용히 빈다');

        // 생산자가 그 상수로 이름을 만든다 — 한쪽만 상수화하면 여전히 갈린다.
        $this->assertStringContainsString('INSTALLER_ENV_BACKUP_INFIX.date(INSTALLER_ENV_BACKUP_STAMP_FORMAT)', $source, '.env 백업 생산자가 이름을 인라인 리터럴로 조립한다');

        // 생산자 형식으로 만든 이름(충돌 회피 접미사 포함)이 프루너 판정을 통과한다.
        $pattern = (string) $reflection->getConstant('ENV_BACKUP_NAME_PATTERN');
        $produced = '.env'.$infix[1].date($format[1]);

        $this->assertSame(1, preg_match($pattern, $produced), '생산자가 만드는 이름이 프루너 판정을 통과하지 못한다');
        $this->assertSame(1, preg_match($pattern, $produced.'_1'), '같은 초 충돌 회피 이름이 프루너 판정을 통과하지 못한다');
        $this->assertSame(0, preg_match($pattern, '.env.backup'), '생산자 규칙 밖의 이름을 받아들인다');
    }

    /**
     * **실제 생산자 규약**으로 손상 백업 디렉토리 이름을 만듭니다.
     *
     * 리터럴을 다시 적으면 생산자가 규칙을 바꿔도 이 검사는 그대로 초록이 되고, 그 상태가
     * 바로 이 규약이 폐기하려던 결함이다 (형제 두 백업군은 이미 생산자를 부른다).
     * 생산자 본체(`ensurePublicStorageLink()`)는 실 저장소의 `public/storage` 를 건드리므로
     * 이름을 만드는 **그 지점의 표현**을 그대로 쓴다 — 두 조각 모두 생산자 소유다.
     *
     * @return string 백업 디렉토리 이름
     */
    private function nameFromBrokenBackupProducer(): string
    {
        return StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX.StorageLinkHelper::brokenStamp();
    }

    /**
     * 이 서비스가 타는 **전이 나열기**도 `scandir` 가드를 갖는다.
     *
     * 서비스 자신은 수집기 전부에 `ExecutionEnvironment::hasFunction('scandir')` 가드를
     * 갖췄지만, 6개 대상군이 실제로 나열에 쓰는 것은 공용 지점인
     * `ExtensionManager::listDirectoryFiles()` / `listDirectories()` 였고 그 둘이 무가드였다.
     * `scandir` 이 막힌 호스팅에서는 자기 가드를 다 갖춘 채로 그 전이 지점에서 함께
     * 죽는다 — 이 정리 작업은 **웹 요청 안의 예약 작업**으로 돈다.
     *
     * 실행 프로세스에서 `disable_functions` 를 재현할 수 없으므로(그 제약이 이 축의
     * 정적 검사가 존재하는 이유다) 소스 계약으로 잠근다. 모집단은 **도출**한다 —
     * 이 서비스가 실제로 부르는 나열기를 손으로 적으면 새 소비가 생겨도 초록이다.
     *
     * @effects transitive_directory_listers_guard_scandir
     *
     * @return void 반환값 없음
     */
    public function test_transitive_directory_listers_guard_scandir(): void
    {
        $serviceSource = file_get_contents((new \ReflectionClass(StorageLeftoverPruneService::class))->getFileName());
        $this->assertIsString($serviceSource);

        // 이 서비스가 실제로 부르는 `ExtensionManager` 나열기를 소스에서 도출한다.
        preg_match_all('/ExtensionManager::(list[A-Za-z]+)\s*\(/', $serviceSource, $m);
        $listers = array_values(array_unique($m[1] ?? []));

        $this->assertNotEmpty(
            $listers,
            '이 서비스가 ExtensionManager 나열기를 하나도 부르지 않는다 — 모집단이 비면 아래 단언이 '
                .'한 번도 돌지 않는다. 나열 경로가 바뀌었다면 이 검사를 함께 옮긴다',
        );

        $managerFile = (new \ReflectionClass(ExtensionManager::class))->getFileName();
        $managerSource = file_get_contents($managerFile);
        $this->assertIsString($managerSource);

        foreach ($listers as $lister) {
            $start = strpos($managerSource, 'function '.$lister.'(');
            $this->assertNotFalse($start, "ExtensionManager::{$lister}() 를 찾지 못했다");

            // 다음 `public static function` 전까지를 그 메서드 본문으로 본다.
            $next = strpos($managerSource, 'public static function', $start + 1);
            $body = substr($managerSource, $start, $next === false ? null : $next - $start);

            $this->assertStringContainsString(
                "hasFunction('scandir')",
                $body,
                "ExtensionManager::{$lister}() 가 scandir 을 가드 없이 부른다 — scandir 이 막힌 "
                    .'호스팅에서 이 정리 작업 전체가 그 자리에서 치명 종료한다(서비스 자신의 가드는 '
                    .'그 전이 호출을 막지 못한다)',
            );
        }
    }
}
