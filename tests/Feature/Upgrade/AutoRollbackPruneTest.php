<?php

namespace Tests\Feature\Upgrade;

use App\Extension\Helpers\CoreBackupHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 자동 롤백 시 신 버전 신규 파일 prune 통합 테스트.
 *
 * 본 테스트는 CoreUpdateCommand 의 Step 5 백업 → Step 6.5 manifest 생성 → Step 7 applyUpdate
 * → 강제 fatal → restoreFromBackup 의 흐름을 격리된 임시 디렉토리 트리에서 재현하여
 * 신 버전 신규 파일이 정확히 prune 되는지 + 사용자 추가 파일이 보존되는지 검증한다.
 *
 * 실제 base_path() 는 건드리지 않는다 — backup/active/_pending 3개 디렉토리를 모두
 * storage/app/test_rollback_prune_xxx 아래 임시 fixture 로 구성하고 CoreBackupHelper 의
 * 신규 메서드 (`writeNewFilesManifest`, `pruneNewFiles`) 를 명시 호출한다.
 *
 * Feature 디렉토리에 배치한 이유는 CoreUpdateCommand 의 흐름(backup → manifest → apply
 * → rollback) 을 end-to-end 로 재현하는 통합 테스트이기 때문 — Unit 의 helper 단건
 * 동작 검증과 구분.
 */
class AutoRollbackPruneTest extends TestCase
{
    private string $testRoot;

    private string $backupPath;

    private string $sourcePath;

    private string $activePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = storage_path('app/test_rollback_prune_'.uniqid());
        $this->backupPath = $this->testRoot.'/backup';
        $this->sourcePath = $this->testRoot.'/_pending';
        $this->activePath = $this->testRoot.'/active';

        File::ensureDirectoryExists($this->backupPath);
        File::ensureDirectoryExists($this->sourcePath);
        File::ensureDirectoryExists($this->activePath);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testRoot)) {
            File::deleteDirectory($this->testRoot);
        }
        parent::tearDown();
    }

    /**
     * 시나리오 1: 잔존 결함 재현 — 신 ServiceProvider 가 활성 디렉토리에서 삭제됨.
     */
    public function test_new_service_provider_is_removed_on_rollback(): void
    {
        // (1) 활성 디렉토리 사전 상태 — backup 의 source 가 됨
        File::ensureDirectoryExists($this->activePath.'/app/Services');
        File::put($this->activePath.'/app/Services/ExistingService.php', '<?php // v_old');

        // (2) backup 생성 — 사전 스냅샷
        File::ensureDirectoryExists($this->backupPath.'/app/Services');
        File::put($this->backupPath.'/app/Services/ExistingService.php', '<?php // v_old');

        // (3) _pending 신 버전 — 신규 ServiceProvider 추가
        File::ensureDirectoryExists($this->sourcePath.'/app/Services/LanguagePack');
        File::put($this->sourcePath.'/app/Services/ExistingService.php', '<?php // v_new');
        File::put($this->sourcePath.'/app/Services/LanguagePack/Module.php', '<?php // new');

        // (4) Step 6.5 manifest 생성
        $stats = CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );
        $this->assertGreaterThanOrEqual(1, $stats['new_files_count']);

        // (5) Step 7 applyUpdate 시뮬레이션 — 활성 디렉토리에 신 파일 반영
        File::put($this->activePath.'/app/Services/ExistingService.php', '<?php // v_new');
        File::ensureDirectoryExists($this->activePath.'/app/Services/LanguagePack');
        File::put($this->activePath.'/app/Services/LanguagePack/Module.php', '<?php // new');

        // (6) fatal 발생 → manifest 기반 prune (restoreFromBackup 의 prune 단계만 분리 호출)
        $pruneResult = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertFileDoesNotExist($this->activePath.'/app/Services/LanguagePack/Module.php');
        $this->assertDirectoryDoesNotExist($this->activePath.'/app/Services/LanguagePack');
        $this->assertSame(1, $pruneResult['removed_files']);
    }

    /**
     * 시나리오 2: 사용자가 활성 디렉토리에 직접 추가한 파일은 보존.
     */
    public function test_user_added_files_are_preserved(): void
    {
        // 사용자 파일 사전 배치 — applyUpdate 직전 활성 디렉토리에 존재
        File::ensureDirectoryExists($this->activePath.'/app');
        File::put($this->activePath.'/app/CustomUserHelper.php', '<?php // user code');
        File::ensureDirectoryExists($this->activePath.'/database/migrations');
        File::put(
            $this->activePath.'/database/migrations/2026_05_13_custom_user_migration.php',
            '<?php // user migration',
        );

        // backup 단계가 이 사용자 파일들을 백업에 포함
        File::ensureDirectoryExists($this->backupPath.'/app');
        File::put($this->backupPath.'/app/CustomUserHelper.php', '<?php // user code');
        File::ensureDirectoryExists($this->backupPath.'/database/migrations');
        File::put(
            $this->backupPath.'/database/migrations/2026_05_13_custom_user_migration.php',
            '<?php // user migration',
        );

        // _pending 신 버전 — 신규 ServiceProvider 추가 (사용자 파일과 무관)
        File::ensureDirectoryExists($this->sourcePath.'/app/Services');
        File::put($this->sourcePath.'/app/Services/NewProvider.php', '<?php // new');

        // manifest 생성
        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app', 'database'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);

        // 사용자 파일은 manifest 에서 제외되어야 함 (백업에 있으므로)
        $this->assertNotContains('app/CustomUserHelper.php', $manifest['new_files']);
        $this->assertNotContains(
            'database/migrations/2026_05_13_custom_user_migration.php',
            $manifest['new_files'],
        );
        $this->assertContains('app/Services/NewProvider.php', $manifest['new_files']);

        // applyUpdate 시뮬레이션
        File::ensureDirectoryExists($this->activePath.'/app/Services');
        File::put($this->activePath.'/app/Services/NewProvider.php', '<?php // new');

        // fatal → prune
        CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        // 사용자 파일 2개 모두 보존
        $this->assertFileExists($this->activePath.'/app/CustomUserHelper.php');
        $this->assertFileExists(
            $this->activePath.'/database/migrations/2026_05_13_custom_user_migration.php',
        );
        // 신 파일은 prune
        $this->assertFileDoesNotExist($this->activePath.'/app/Services/NewProvider.php');
    }

    /**
     * 시나리오 4: 보호 경로 (storage, vendor, .env 등) 는 manifest 와 prune 양쪽에서 제외.
     */
    public function test_protected_paths_are_ignored_at_both_stages(): void
    {
        File::ensureDirectoryExists($this->activePath.'/storage/app');
        File::put($this->activePath.'/storage/app/user_upload.jpg', 'binary');
        File::put($this->activePath.'/.env', 'APP_KEY=...');

        File::ensureDirectoryExists($this->sourcePath.'/storage/app');
        File::put($this->sourcePath.'/storage/app/seed.php', '<?php');

        $protected = ['storage', '.env', 'vendor'];

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['storage'],
            $protected,
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);
        $this->assertEmpty($manifest['new_files']);
        $this->assertEmpty($manifest['new_dirs']);

        // 사용자 파일과 .env 가 prune 의 보호 가드도 통과해야 — manifest 에 가상으로 등재해도 살아남음
        File::put($this->backupPath.'/_new_files_manifest.json', json_encode([
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => ['storage/app/user_upload.jpg', '.env'],
            'new_dirs' => [],
        ]));

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, $protected);
        $this->assertFileExists($this->activePath.'/storage/app/user_upload.jpg');
        $this->assertFileExists($this->activePath.'/.env');
        $this->assertSame(2, $result['protected_count']);
        $this->assertSame(0, $result['removed_files']);
    }

    /**
     * 시나리오 6: 외부 모듈/플러그인 디렉토리는 보호 경로로 등재되어 prune 영향 없음.
     */
    public function test_extension_directories_are_protected(): void
    {
        File::ensureDirectoryExists($this->activePath.'/modules/sirsoft-board');
        File::put($this->activePath.'/modules/sirsoft-board/module.json', '{}');

        $protected = ['modules', 'plugins', 'templates', 'lang-packs'];

        File::put($this->backupPath.'/_new_files_manifest.json', json_encode([
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => ['modules/sirsoft-board/module.json'],
            'new_dirs' => ['modules/sirsoft-board'],
        ]));

        CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, $protected);

        $this->assertFileExists($this->activePath.'/modules/sirsoft-board/module.json');
        $this->assertDirectoryExists($this->activePath.'/modules/sirsoft-board');
    }

    /**
     * 시나리오 7: 멀티 버전 chain — applyUpdate 가 final source 와 backup 의 차이만 보면
     * 충분하므로 N+1 / N+2 양 버전의 신규 파일이 모두 prune.
     */
    public function test_multi_version_chain_prunes_all_new_files(): void
    {
        File::ensureDirectoryExists($this->backupPath.'/app');
        File::put($this->backupPath.'/app/Old.php', '<?php // beta.N');

        // _pending = 최종 beta.N+2 — 중간 beta.N+1 의 변경도 누적되어 있음
        File::ensureDirectoryExists($this->sourcePath.'/app');
        File::put($this->sourcePath.'/app/Old.php', '<?php // updated');
        File::put($this->sourcePath.'/app/IntroducedInBetaN1.php', '<?php');
        File::put($this->sourcePath.'/app/IntroducedInBetaN2.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            [],
            '7.0.0-beta.4',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);
        $this->assertContains('app/IntroducedInBetaN1.php', $manifest['new_files']);
        $this->assertContains('app/IntroducedInBetaN2.php', $manifest['new_files']);

        // 활성 디렉토리에 두 버전의 신 파일 반영
        File::ensureDirectoryExists($this->activePath.'/app');
        File::put($this->activePath.'/app/IntroducedInBetaN1.php', '<?php');
        File::put($this->activePath.'/app/IntroducedInBetaN2.php', '<?php');

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);
        $this->assertSame(2, $result['removed_files']);
    }

    /**
     * 시나리오 8: manifest 부재 시 prune 은 noop (기존 동작 유지).
     */
    public function test_missing_manifest_skips_prune(): void
    {
        File::ensureDirectoryExists($this->activePath.'/app/Services/LanguagePack');
        File::put($this->activePath.'/app/Services/LanguagePack/Module.php', '<?php');

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);
        $this->assertFalse($result['manifest_loaded']);
        $this->assertFileExists($this->activePath.'/app/Services/LanguagePack/Module.php');
    }

    /**
     * 시나리오 9: manifest JSON 손상 시 warning + 기존 overlay 만 (noop prune).
     */
    public function test_corrupted_manifest_falls_back_safely(): void
    {
        File::put($this->backupPath.'/_new_files_manifest.json', '{not json');
        File::ensureDirectoryExists($this->activePath.'/app/Services');
        File::put($this->activePath.'/app/Services/keep.php', '<?php');

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);
        $this->assertFalse($result['manifest_loaded']);
        $this->assertFileExists($this->activePath.'/app/Services/keep.php');
    }

    /**
     * 시나리오 10: --no-backup 모드 Step 6.5 skip 정적 구조 검증.
     *
     * CoreUpdateCommand 의 catch 블록 mocking 없이 Step 6.5 의 가드 패턴을 정적으로
     * 검증한다. backupPath 가 null 일 때 manifest 생성이 스킵됨을 코드 레벨로 보장.
     */
    public function test_step6_5_is_gated_by_backup_path_null_check(): void
    {
        $cmdPath = base_path('app/Console/Commands/Core/CoreUpdateCommand.php');
        $this->assertFileExists($cmdPath);

        $content = File::get($cmdPath);

        $this->assertStringContainsString(
            "CoreBackupHelper::writeNewFilesManifest",
            $content,
            'Step 6.5 가 writeNewFilesManifest 를 호출해야 함',
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$backupPath\s*!==\s*null\s*\)\s*\{[^}]*writeNewFilesManifest/s',
            $content,
            '--no-backup 모드 보호: writeNewFilesManifest 호출은 `if ($backupPath !== null)` 가드 안에 위치해야 함',
        );
    }

    /**
     * 시나리오 12: 자동 롤백 후 캐시 자동 정리 정적 구조 검증.
     *
     * catch 블록의 restoreFromBackup 호출 직후 clearAllCaches() 가 자동 호출되어
     * `bootstrap/cache/*.php` 의 stale PHP 캐시로 인한 부팅 실패가 차단됨을 코드 레벨로
     * 보장한다.
     */
    public function test_rollback_catch_invokes_clear_all_caches(): void
    {
        $cmdPath = base_path('app/Console/Commands/Core/CoreUpdateCommand.php');
        $content = File::get($cmdPath);

        // restoreFromBackup 와 clearAllCaches 호출이 동일 catch 블록 내에 존재해야 함
        $this->assertStringContainsString('$service->restoreFromBackup(', $content);
        $this->assertStringContainsString('$service->clearAllCaches()', $content);

        // 두 호출 사이에 다른 catch 블록 (catch \Throwable) 등이 없어야 함 — 같은 백업 복원
        // 흐름 내에서 캐시 정리가 일어남을 보장
        $restoreOffset = strpos($content, '$service->restoreFromBackup(');
        $clearOffset = strpos($content, '$service->clearAllCaches()', $restoreOffset);
        $this->assertNotFalse($clearOffset, 'restoreFromBackup 이후 clearAllCaches 호출이 위치해야 함');

        $between = substr($content, $restoreOffset, $clearOffset - $restoreOffset);
        $this->assertStringNotContainsString(
            'public function handle',
            $between,
            '두 호출 사이에 다른 메서드 시작이 없어야 — 동일 catch 블록 내 위치',
        );
    }

    /**
     * 시나리오 11: 빈 디렉토리 정리 + 사용자 파일 존재 시 디렉토리 유지.
     */
    public function test_empty_dir_cleanup_and_user_dir_preservation(): void
    {
        // 케이스 A: 빈 디렉토리 → 제거
        File::ensureDirectoryExists($this->activePath.'/app/EmptyAfterPrune');
        File::put($this->activePath.'/app/EmptyAfterPrune/Only.php', '<?php');

        // 케이스 B: 사용자 파일이 남는 디렉토리 → 유지
        File::ensureDirectoryExists($this->activePath.'/app/MixedDir');
        File::put($this->activePath.'/app/MixedDir/NewFromCore.php', '<?php');
        File::put($this->activePath.'/app/MixedDir/UserAdded.php', '<?php // user');

        File::put($this->backupPath.'/_new_files_manifest.json', json_encode([
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => [
                'app/EmptyAfterPrune/Only.php',
                'app/MixedDir/NewFromCore.php',
            ],
            'new_dirs' => [
                'app/EmptyAfterPrune',
                'app/MixedDir',
            ],
        ]));

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertDirectoryDoesNotExist($this->activePath.'/app/EmptyAfterPrune');
        $this->assertDirectoryExists($this->activePath.'/app/MixedDir');
        $this->assertFileExists($this->activePath.'/app/MixedDir/UserAdded.php');
        $this->assertFileDoesNotExist($this->activePath.'/app/MixedDir/NewFromCore.php');
        $this->assertSame(2, $result['removed_files']);
        $this->assertSame(1, $result['removed_dirs']);
    }
}
