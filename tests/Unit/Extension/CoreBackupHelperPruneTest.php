<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\CoreBackupHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreBackupHelper 의 manifest 기반 prune 단위 테스트.
 *
 * 자동 롤백 시 신 버전 신규 파일이 활성 디렉토리에 잔존하는 결함을 차단하는
 * `writeNewFilesManifest()` / `pruneNewFiles()` 의 동작을 검증한다.
 *
 * 본 테스트는 BASE_PATH 를 건드리지 않는다 — 백업/_pending/활성 디렉토리 3개를
 * 모두 storage/app/test_core_prune/ 아래 임시 fixture 로 구성하고 helper 의
 * "활성" 인자에 임시 경로를 명시 전달하는 방식으로 격리한다.
 */
class CoreBackupHelperPruneTest extends TestCase
{
    private string $testRoot;

    private string $backupPath;

    private string $sourcePath;

    private string $activePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRoot = storage_path('app/test_core_prune_'.uniqid());
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

    // ========================================================================
    // writeNewFilesManifest() — manifest 생성
    // ========================================================================

    /**
     * _pending 에만 있고 backup 에는 없는 파일이 new_files 로 식별되는지 검증.
     */
    public function test_write_manifest_identifies_new_files_in_pending_only(): void
    {
        // backup (= 활성 디렉토리의 사전 스냅샷) 에 있는 기존 파일
        File::ensureDirectoryExists($this->backupPath.'/app/Services');
        File::put($this->backupPath.'/app/Services/ExistingService.php', '<?php');

        // _pending 에는 기존 + 신규
        File::ensureDirectoryExists($this->sourcePath.'/app/Services');
        File::put($this->sourcePath.'/app/Services/ExistingService.php', '<?php // updated');
        File::put($this->sourcePath.'/app/Services/NewServiceProviderClass.php', '<?php // new');

        $result = CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifestPath = $this->backupPath.'/_new_files_manifest.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(File::get($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('7.0.0-beta.5', $manifest['from_version']);
        $this->assertSame('7.0.0-beta.6', $manifest['to_version']);
        $this->assertContains('app/Services/NewServiceProviderClass.php', $manifest['new_files']);
        $this->assertNotContains('app/Services/ExistingService.php', $manifest['new_files']);
        $this->assertGreaterThanOrEqual(1, $result['new_files_count']);
    }

    /**
     * _pending 에만 있는 신규 디렉토리가 new_dirs 로 식별되는지 검증.
     */
    public function test_write_manifest_identifies_new_dirs(): void
    {
        File::ensureDirectoryExists($this->backupPath.'/app');
        File::put($this->backupPath.'/app/keep.php', '<?php');

        File::ensureDirectoryExists($this->sourcePath.'/app');
        File::put($this->sourcePath.'/app/keep.php', '<?php');
        File::ensureDirectoryExists($this->sourcePath.'/app/Services/LanguagePack');
        File::put($this->sourcePath.'/app/Services/LanguagePack/Module.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);
        $this->assertContains('app/Services/LanguagePack', $manifest['new_dirs']);
        $this->assertContains('app/Services/LanguagePack/Module.php', $manifest['new_files']);
    }

    /**
     * protectedPaths 하위는 manifest 에서 제외되는지 검증 (방어 깊이).
     */
    public function test_write_manifest_excludes_protected_paths(): void
    {
        // _pending 에 storage/.env 가상 신규 파일 — 보호 경로
        File::ensureDirectoryExists($this->sourcePath.'/storage');
        File::put($this->sourcePath.'/storage/secret.txt', 'should not be tracked');

        File::ensureDirectoryExists($this->sourcePath.'/app');
        File::put($this->sourcePath.'/app/Real.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app', 'storage'],
            ['storage'],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);
        $this->assertNotContains('storage/secret.txt', $manifest['new_files']);
        $this->assertContains('app/Real.php', $manifest['new_files']);
    }

    /**
     * excludes 패턴 (node_modules, .git 등) 은 manifest 에서 제외.
     */
    public function test_write_manifest_excludes_pattern_matches(): void
    {
        File::ensureDirectoryExists($this->sourcePath.'/app/node_modules');
        File::put($this->sourcePath.'/app/node_modules/lib.js', '');
        File::ensureDirectoryExists($this->sourcePath.'/app');
        File::put($this->sourcePath.'/app/Real.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            ['node_modules'],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);
        $this->assertNotContains('app/node_modules/lib.js', $manifest['new_files']);
        $this->assertContains('app/Real.php', $manifest['new_files']);
    }

    /**
     * manifest JSON 의 키 순서 / 정렬 규칙 invariant — beta.6 Upgrade DataMigration 01
     * 의 사후 작성본과 바이트 단위 호환을 보장하기 위해 키 순서/정렬을 고정한다.
     */
    public function test_manifest_schema_invariant(): void
    {
        File::ensureDirectoryExists($this->sourcePath.'/app');
        File::put($this->sourcePath.'/app/Beta.php', '<?php');
        File::put($this->sourcePath.'/app/Alpha.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $this->backupPath,
            $this->sourcePath,
            ['app'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $manifest = json_decode(File::get($this->backupPath.'/_new_files_manifest.json'), true);

        // 정렬 규칙: new_files / new_dirs 는 lexicographic ascending
        $sorted = $manifest['new_files'];
        $copy = $sorted;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $sorted, 'new_files 는 알파벳 오름차순 정렬되어야 함');

        // 최상위 키 6종 명시 (DataMigration 01 의 invariant)
        $this->assertSame(
            ['version', 'created_at', 'from_version', 'to_version', 'new_files', 'new_dirs'],
            array_keys($manifest),
        );
    }

    // ========================================================================
    // pruneNewFiles() — manifest 기반 신규 파일 정리
    // ========================================================================

    /**
     * manifest 에 등록된 신규 파일이 활성 디렉토리에서 삭제되는지 검증.
     */
    public function test_prune_removes_new_files_listed_in_manifest(): void
    {
        File::ensureDirectoryExists($this->activePath.'/app/Services/LanguagePack');
        File::put($this->activePath.'/app/Services/LanguagePack/Module.php', '<?php');
        File::put($this->activePath.'/app/Services/UserKeep.php', '<?php // user');

        $this->writeManifest([
            'new_files' => ['app/Services/LanguagePack/Module.php'],
            'new_dirs' => ['app/Services/LanguagePack'],
        ]);

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertFileDoesNotExist($this->activePath.'/app/Services/LanguagePack/Module.php');
        $this->assertDirectoryDoesNotExist($this->activePath.'/app/Services/LanguagePack');
        // 사용자 파일은 보존
        $this->assertFileExists($this->activePath.'/app/Services/UserKeep.php');

        $this->assertSame(1, $result['removed_files']);
        $this->assertSame(1, $result['removed_dirs']);
    }

    /**
     * manifest 의 new_dirs 중 사용자 파일이 남아있는 디렉토리는 rmdir 안 함.
     */
    public function test_prune_keeps_dirs_with_user_files(): void
    {
        File::ensureDirectoryExists($this->activePath.'/app/Services/LanguagePack');
        File::put($this->activePath.'/app/Services/LanguagePack/Module.php', '<?php');
        File::put($this->activePath.'/app/Services/LanguagePack/UserAdded.php', '<?php // user');

        $this->writeManifest([
            'new_files' => ['app/Services/LanguagePack/Module.php'],
            'new_dirs' => ['app/Services/LanguagePack'],
        ]);

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertFileDoesNotExist($this->activePath.'/app/Services/LanguagePack/Module.php');
        $this->assertDirectoryExists($this->activePath.'/app/Services/LanguagePack');
        $this->assertFileExists($this->activePath.'/app/Services/LanguagePack/UserAdded.php');

        $this->assertSame(1, $result['removed_files']);
        $this->assertSame(0, $result['removed_dirs']);
    }

    /**
     * protected_paths 하위 manifest 항목은 prune 시점에 재검증되어 삭제되지 않음.
     */
    public function test_prune_respects_protected_paths_double_guard(): void
    {
        // manifest 에 가상으로 보호 경로 항목을 주입 — 운영 결함 또는 악의적 매니페스트 시뮬레이션
        File::ensureDirectoryExists($this->activePath.'/storage');
        File::put($this->activePath.'/storage/secret.txt', 'must keep');

        $this->writeManifest([
            'new_files' => ['storage/secret.txt'],
            'new_dirs' => [],
        ]);

        $result = CoreBackupHelper::pruneNewFiles(
            $this->backupPath,
            $this->activePath,
            ['storage'],
        );

        $this->assertFileExists($this->activePath.'/storage/secret.txt');
        $this->assertSame(0, $result['removed_files']);
        $this->assertGreaterThanOrEqual(1, $result['protected_count']);
    }

    /**
     * 활성 디렉토리에 symlink 가 있고 manifest 에 등재되어 있어도 절대 삭제하지 않음.
     * Windows 에서는 markTestSkipped (symlink() 권한 제약).
     */
    public function test_prune_skips_symlinks_unconditionally(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows 에서는 PHP symlink() 권한이 필요하여 건너뜀');
        }

        File::ensureDirectoryExists($this->activePath.'/public');
        File::ensureDirectoryExists($this->activePath.'/storage/app/public');
        symlink($this->activePath.'/storage/app/public', $this->activePath.'/public/storage');

        $this->writeManifest([
            'new_files' => ['public/storage'],
            'new_dirs' => [],
        ]);

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertTrue(is_link($this->activePath.'/public/storage'));
        $this->assertGreaterThanOrEqual(1, $result['symlink_skipped']);
    }

    /**
     * manifest 부재 시 prune 은 noop + warning 없이 빈 결과 반환.
     */
    public function test_prune_returns_zero_when_manifest_absent(): void
    {
        File::put($this->activePath.'/keep.php', '<?php');

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertFileExists($this->activePath.'/keep.php');
        $this->assertSame(0, $result['removed_files']);
        $this->assertSame(0, $result['removed_dirs']);
        $this->assertFalse($result['manifest_loaded']);
    }

    /**
     * manifest JSON 파싱 실패 시 prune 은 noop + manifest_loaded:false.
     */
    public function test_prune_handles_corrupted_manifest_gracefully(): void
    {
        File::put($this->backupPath.'/_new_files_manifest.json', '{this is not valid json');
        File::put($this->activePath.'/keep.php', '<?php');

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertFileExists($this->activePath.'/keep.php');
        $this->assertFalse($result['manifest_loaded']);
    }

    /**
     * manifest 의 new_files 가 실제 디스크에 없으면 (이미 삭제됨) skip.
     */
    public function test_prune_skips_missing_files_gracefully(): void
    {
        $this->writeManifest([
            'new_files' => ['app/Nonexistent.php'],
            'new_dirs' => ['app/Ghost'],
        ]);

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertSame(0, $result['removed_files']);
        $this->assertSame(0, $result['removed_dirs']);
    }

    /**
     * 빈 디렉토리 정리 — new_dirs 가 깊이 역순으로 처리되어 중첩 디렉토리도 제거.
     */
    public function test_prune_empty_dirs_in_depth_reverse_order(): void
    {
        File::ensureDirectoryExists($this->activePath.'/a/b/c');
        File::put($this->activePath.'/a/b/c/file.php', '<?php');

        $this->writeManifest([
            'new_files' => ['a/b/c/file.php'],
            'new_dirs' => ['a', 'a/b', 'a/b/c'],
        ]);

        $result = CoreBackupHelper::pruneNewFiles($this->backupPath, $this->activePath, []);

        $this->assertDirectoryDoesNotExist($this->activePath.'/a');
        $this->assertSame(3, $result['removed_dirs']);
    }

    private function writeManifest(array $data): void
    {
        $manifest = [
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => $data['new_files'] ?? [],
            'new_dirs' => $data['new_dirs'] ?? [],
        ];

        File::put($this->backupPath.'/_new_files_manifest.json', json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
