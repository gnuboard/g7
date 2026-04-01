<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * ExtensionPendingHelper 테스트
 *
 * ## File Facade Spy 패턴 (IMPORTANT)
 *
 * 확장 복사(copyToActive) 관련 실패 시나리오 테스트 시,
 * 실제 파일시스템 기반이 아닌 **File Facade Spy 패턴**을 사용해야 합니다.
 *
 * 이유: ProtectsExtensionDirectories trait이 확장 활성 디렉토리 삭제를
 * 차단하므로, Facade를 통하지 않는 직접 파일 조작은 보호 메커니즘과 충돌합니다.
 *
 * 패턴 (copyToActive는 파일별 복사 — File::copy를 mock):
 * ```php
 * $originalFilesystem = File::getFacadeRoot();
 * $mock = Mockery::mock($originalFilesystem)->makePartial();
 * $mock->shouldReceive('copy')
 *     ->andReturnUsing(function ($src, $dst) use ($originalFilesystem) {
 *         if (str_contains($dst, '_updating_')) {
 *             throw new \RuntimeException('Disk full');
 *         }
 *         return $originalFilesystem->copy($src, $dst);
 *     });
 * File::swap($mock);
 * // ... 테스트 ...
 * File::swap($originalFilesystem); // finally에서 복원
 * ```
 *
 * @see ProtectsExtensionDirectories File Facade Spy 보호 trait
 * @see ExtensionPendingHelper::copyToActive() 원자적 디렉토리 교체 로직
 */
class ExtensionPendingHelperTest extends TestCase
{
    private string $modulesPath;

    private string $tempBase;

    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');
        $this->tempBase = storage_path('app/test_pending_helper_'.uniqid());
        File::ensureDirectoryExists($this->tempBase);

        // _pending 테스트 디렉토리 생성
        $pendingPath = $this->modulesPath.'/_pending/test-pending-mod';
        File::ensureDirectoryExists($pendingPath.'/src');
        File::put($pendingPath.'/module.json', json_encode([
            'identifier' => 'test-pending-mod',
            'version' => '1.0.0',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
        ]));
        File::put($pendingPath.'/src/Module.php', '<?php class Module {}');
    }

    protected function tearDown(): void
    {
        // 테스트 디렉토리 정리
        $pendingPath = $this->modulesPath.'/_pending/test-pending-mod';
        if (File::isDirectory($pendingPath)) {
            File::deleteDirectory($pendingPath);
        }

        $activePath = $this->modulesPath.'/test-pending-mod';
        if (File::isDirectory($activePath)) {
            File::deleteDirectory($activePath);
        }

        // _pending 디렉토리 내 테스트용 하위 디렉토리만 정리 (디렉토리 자체는 보존)
        $pendingDir = $this->modulesPath.'/_pending';
        if (File::isDirectory($pendingDir)) {
            foreach (File::directories($pendingDir) as $subDir) {
                $dirName = basename($subDir);
                // 테스트에서 생성된 디렉토리만 삭제 (test- 접두사 또는 _updating_/_old_ 임시)
                if (str_starts_with($dirName, 'test-')
                    || str_contains($dirName, '_updating_')
                    || str_contains($dirName, '_old_')) {
                    File::deleteDirectory($subDir);
                }
            }
        }

        // 추가 임시 디렉토리 정리
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        if (File::isDirectory($this->tempBase)) {
            File::deleteDirectory($this->tempBase);
        }

        parent::tearDown();
    }

    /**
     * _pending에서 메타데이터를 올바르게 읽는지 확인합니다.
     */
    public function test_load_pending_extensions_reads_meta_json(): void
    {
        $result = ExtensionPendingHelper::loadPendingExtensions($this->modulesPath, 'module.json');

        $this->assertArrayHasKey('test-pending-mod', $result);
        $this->assertEquals('test-pending-mod', $result['test-pending-mod']['identifier']);
        $this->assertEquals('1.0.0', $result['test-pending-mod']['version']);
        $this->assertEquals(['ko' => '테스트 모듈', 'en' => 'Test Module'], $result['test-pending-mod']['name']);
    }

    /**
     * JSON 없는 디렉토리를 건너뛰는지 확인합니다.
     */
    public function test_load_pending_extensions_skips_invalid_dirs(): void
    {
        // JSON 없는 디렉토리 생성
        $invalidPath = $this->modulesPath.'/_pending/invalid-mod';
        File::ensureDirectoryExists($invalidPath);

        $result = ExtensionPendingHelper::loadPendingExtensions($this->modulesPath, 'module.json');

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('invalid-mod', $result);

        // 정리
        File::deleteDirectory($invalidPath);
    }

    /**
     * _pending이 비어있으면 빈 배열을 반환하는지 확인합니다.
     */
    public function test_load_pending_extensions_returns_empty_when_no_pending(): void
    {
        // 기존 테스트 데이터 정리
        File::deleteDirectory($this->modulesPath.'/_pending/test-pending-mod');

        $result = ExtensionPendingHelper::loadPendingExtensions($this->modulesPath, 'module.json');

        $this->assertEmpty($result);
    }

    /**
     * _pending 디렉토리 자체가 없으면 빈 배열을 반환하는지 확인합니다.
     */
    public function test_load_pending_extensions_returns_empty_when_dir_not_exists(): void
    {
        // _pending 디렉토리가 없는 경로 사용
        $result = ExtensionPendingHelper::loadPendingExtensions(sys_get_temp_dir().'/nonexistent', 'module.json');

        $this->assertEmpty($result);
    }

    /**
     * _pending에서 활성 디렉토리로 복사되는지 확인합니다.
     */
    public function test_copy_to_active_moves_files(): void
    {
        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';
        $targetPath = $this->modulesPath.'/test-pending-mod';

        ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);

        $this->assertDirectoryExists($targetPath);
        $this->assertFileExists($targetPath.'/module.json');
        $this->assertFileExists($targetPath.'/src/Module.php');

        // 원본도 여전히 존재 확인
        $this->assertDirectoryExists($sourcePath);
    }

    /**
     * 대상에 이미 디렉토리가 있을 때 덮어쓰는지 확인합니다.
     */
    public function test_copy_to_active_overwrites_existing(): void
    {
        $targetPath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($targetPath);
        File::put($targetPath.'/old-file.txt', 'old');

        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';
        ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);

        $this->assertFileDoesNotExist($targetPath.'/old-file.txt');
        $this->assertFileExists($targetPath.'/module.json');
    }

    /**
     * 확장 디렉토리가 완전히 삭제되는지 확인합니다.
     */
    public function test_delete_extension_directory_removes_completely(): void
    {
        // 활성 디렉토리 생성
        $activePath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/file.txt', 'content');

        ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, 'test-pending-mod');

        $this->assertDirectoryDoesNotExist($activePath);
    }

    /**
     * 존재하지 않는 경로 삭제 시 예외 없이 통과하는지 확인합니다.
     */
    public function test_delete_extension_directory_ignores_nonexistent(): void
    {
        ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, 'nonexistent-mod');

        $this->assertTrue(true); // 예외 미발생 확인
    }

    /**
     * 복사 실패 시 기존 대상 디렉토리가 보존되는지 확인합니다.
     */
    public function test_copy_to_active_preserves_target_on_copy_failure(): void
    {
        $targetPath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($targetPath);
        File::put($targetPath.'/existing-file.txt', 'important data');

        // 존재하지 않는 소스로 복사 시도 → 예외 발생
        $invalidSource = $this->modulesPath.'/_pending/nonexistent-mod';

        try {
            ExtensionPendingHelper::copyToActive($invalidSource, $targetPath);
            $this->fail('예외가 발생해야 합니다');
        } catch (\RuntimeException $e) {
            // 기존 대상 디렉토리가 보존되는지 확인
            $this->assertDirectoryExists($targetPath);
            $this->assertFileExists($targetPath.'/existing-file.txt');
            $this->assertEquals('important data', File::get($targetPath.'/existing-file.txt'));
        }
    }

    /**
     * 정상 교체 후 임시 디렉토리가 남아있지 않은지 확인합니다.
     */
    public function test_copy_to_active_cleans_temp_after_success(): void
    {
        $targetPath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($targetPath);
        File::put($targetPath.'/old-file.txt', 'old');

        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';
        ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);

        // _pending/ 하위의 _updating_ 임시 디렉토리가 남아있지 않은지 확인
        $pendingPath = $this->modulesPath.'/_pending';
        $identifier = basename($targetPath);
        $tempDirs = glob($pendingPath.'/'.$identifier.'_updating_*');
        $this->assertEmpty($tempDirs, '임시 디렉토리가 정리되어야 합니다');
        $oldDirs = glob($pendingPath.'/'.$identifier.'_old_*');
        $this->assertEmpty($oldDirs, '_old 디렉토리가 정리되어야 합니다');

        // 새 파일이 정상 복사되었는지 확인
        $this->assertFileExists($targetPath.'/module.json');
        $this->assertFileDoesNotExist($targetPath.'/old-file.txt');
    }

    /**
     * 복사 실패 시 기존 대상 디렉토리가 보존되고 임시 디렉토리가 정리되는지 확인합니다.
     * File Facade Spy 패턴으로 copy(개별 파일 복사)를 가로채어 예외를 발생시킵니다.
     */
    public function test_copy_to_active_preserves_target_and_cleans_temp_on_copy_failure(): void
    {
        $targetPath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($targetPath);
        File::put($targetPath.'/existing-file.txt', 'important data');

        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';

        // File Facade Spy: copy(개별 파일)를 임시 경로 복사 시에만 예외 발생
        $originalFilesystem = File::getFacadeRoot();
        $mock = Mockery::mock($originalFilesystem)->makePartial();
        $mock->shouldReceive('copy')
            ->andReturnUsing(function (string $src, string $dst) use ($originalFilesystem) {
                // 임시 경로(_updating_)로의 복사만 실패시킴
                if (str_contains($dst, '_updating_')) {
                    throw new \RuntimeException('Disk full');
                }

                return $originalFilesystem->copy($src, $dst);
            });
        File::swap($mock);

        try {
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);
            $this->fail('예외가 발생해야 합니다');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Disk full', $e->getMessage());
        } finally {
            File::swap($originalFilesystem);
        }

        // 기존 대상 디렉토리가 보존되는지 확인
        $this->assertDirectoryExists($targetPath);
        $this->assertFileExists($targetPath.'/existing-file.txt');
        $this->assertEquals('important data', File::get($targetPath.'/existing-file.txt'));

        // _pending/ 하위의 임시 디렉토리가 정리되었는지 확인
        $pendingPath = $this->modulesPath.'/_pending';
        $identifier = basename($targetPath);
        $tempDirs = glob($pendingPath.'/'.$identifier.'_updating_*');
        $this->assertEmpty($tempDirs, '임시 디렉토리가 정리되어야 합니다');
    }

    /**
     * EXCLUDED_DIRECTORIES 상수에 node_modules가 포함되어 있는지 확인합니다.
     */
    public function test_excluded_directories_contains_node_modules(): void
    {
        $this->assertContains('node_modules', ExtensionPendingHelper::EXCLUDED_DIRECTORIES);
    }

    /**
     * copyToActive가 node_modules 디렉토리를 제외하는지 확인합니다.
     */
    public function test_copy_to_active_excludes_node_modules(): void
    {
        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';
        $targetPath = $this->modulesPath.'/test-pending-mod';

        // 소스에 node_modules 디렉토리 생성
        File::ensureDirectoryExists($sourcePath.'/node_modules/some-package');
        File::put($sourcePath.'/node_modules/some-package/index.js', 'module.exports = {}');

        ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);

        // 일반 파일은 복사됨
        $this->assertFileExists($targetPath.'/module.json');
        $this->assertFileExists($targetPath.'/src/Module.php');

        // node_modules는 제외됨
        $this->assertDirectoryDoesNotExist($targetPath.'/node_modules');
    }

    /**
     * 중첩 경로의 node_modules도 제외되는지 확인합니다.
     */
    public function test_copy_to_active_excludes_nested_node_modules(): void
    {
        $sourcePath = $this->modulesPath.'/_pending/test-pending-mod';
        $targetPath = $this->modulesPath.'/test-pending-mod';

        // 중첩 경로에 node_modules 생성
        File::ensureDirectoryExists($sourcePath.'/resources/js/node_modules/lodash');
        File::put($sourcePath.'/resources/js/node_modules/lodash/index.js', 'module.exports = {}');
        File::put($sourcePath.'/resources/js/app.js', 'import _ from "lodash"');

        ExtensionPendingHelper::copyToActive($sourcePath, $targetPath);

        // 일반 파일은 복사됨
        $this->assertFileExists($targetPath.'/resources/js/app.js');

        // 중첩 node_modules는 제외됨
        $this->assertDirectoryDoesNotExist($targetPath.'/resources/js/node_modules');
    }

    /**
     * isPending이 존재할 때 true를 반환하는지 확인합니다.
     */
    public function test_is_pending_returns_true_when_exists(): void
    {
        $this->assertTrue(ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod'));
    }

    /**
     * isPending이 미존재 시 false를 반환하는지 확인합니다.
     */
    public function test_is_pending_returns_false_when_not_exists(): void
    {
        $this->assertFalse(ExtensionPendingHelper::isPending($this->modulesPath, 'nonexistent-mod'));
    }

    /**
     * getPendingPath가 올바른 경로를 반환하는지 확인합니다.
     */
    public function test_get_pending_path_returns_correct_path(): void
    {
        $path = ExtensionPendingHelper::getPendingPath($this->modulesPath, 'test-mod');

        $this->assertStringEndsWith('_pending'.DIRECTORY_SEPARATOR.'test-mod', $path);
    }

    // ========================================================================
    // createUpdateStagingPath() - 타임스탬프 기반 스테이징 경로 생성
    // ========================================================================

    /**
     * createUpdateStagingPath()가 타임스탬프 형식의 디렉토리를 생성하는지 검증합니다.
     */
    public function test_create_update_staging_path_creates_timestamped_directory(): void
    {
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-mod');
        $this->tempDirs[] = $stagingPath;

        // 디렉토리가 생성되었는지 확인
        $this->assertTrue(File::isDirectory($stagingPath));

        // _pending 하위에 identifier_timestamp 형식인지 확인
        $dirName = basename($stagingPath);
        $this->assertMatchesRegularExpression('/^test-mod_\d{8}_\d{6}$/', $dirName);

        // _pending 디렉토리 하위인지 확인
        $parentDir = basename(dirname($stagingPath));
        $this->assertEquals('_pending', $parentDir);
    }

    /**
     * createUpdateStagingPath()가 매 호출마다 고유 경로를 생성하는지 검증합니다.
     */
    public function test_create_update_staging_path_creates_unique_paths(): void
    {
        $path1 = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-mod');
        $this->tempDirs[] = $path1;

        sleep(1);

        $path2 = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-mod');
        $this->tempDirs[] = $path2;

        $this->assertNotEquals($path1, $path2);
        $this->assertTrue(File::isDirectory($path1));
        $this->assertTrue(File::isDirectory($path2));
    }

    // ========================================================================
    // stageForUpdate() - 소스를 스테이징으로 복사
    // ========================================================================

    /**
     * stageForUpdate()가 소스 파일을 스테이징 디렉토리로 복사하는지 검증합니다.
     */
    public function test_stage_for_update_copies_files_to_staging(): void
    {
        // 소스 디렉토리 생성
        $sourceDir = $this->tempBase.DIRECTORY_SEPARATOR.'stage_source';
        File::ensureDirectoryExists($sourceDir);
        File::put($sourceDir.DIRECTORY_SEPARATOR.'module.json', '{"name": "test"}');
        File::ensureDirectoryExists($sourceDir.DIRECTORY_SEPARATOR.'src');
        File::put($sourceDir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Provider.php', '<?php // test');

        // 스테이징 경로 생성
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-stage');
        $this->tempDirs[] = $stagingPath;

        // 스테이징 실행
        ExtensionPendingHelper::stageForUpdate($sourceDir, $stagingPath);

        // 파일이 복사되었는지 확인
        $this->assertTrue(File::exists($stagingPath.DIRECTORY_SEPARATOR.'module.json'));
        $this->assertTrue(File::exists($stagingPath.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Provider.php'));
        $this->assertEquals('{"name": "test"}', File::get($stagingPath.DIRECTORY_SEPARATOR.'module.json'));
    }

    /**
     * stageForUpdate()가 존재하지 않는 소스에 대해 예외를 발생시키는지 검증합니다.
     */
    public function test_stage_for_update_throws_on_missing_source(): void
    {
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-missing');
        $this->tempDirs[] = $stagingPath;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source directory does not exist');

        ExtensionPendingHelper::stageForUpdate('/non/existent/path', $stagingPath);
    }

    // ========================================================================
    // cleanupStaging() - 스테이징 디렉토리 정리
    // ========================================================================

    /**
     * cleanupStaging()이 스테이징 디렉토리를 완전히 삭제하는지 검증합니다.
     */
    public function test_cleanup_staging_removes_directory(): void
    {
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, 'test-cleanup');

        // 파일 추가
        File::put($stagingPath.DIRECTORY_SEPARATOR.'test.txt', 'content');

        $this->assertTrue(File::isDirectory($stagingPath));

        ExtensionPendingHelper::cleanupStaging($stagingPath);

        $this->assertFalse(File::isDirectory($stagingPath));
    }

    /**
     * cleanupStaging()이 존재하지 않는 경로에서도 예외 없이 처리되는지 검증합니다.
     */
    public function test_cleanup_staging_does_not_fail_on_missing_path(): void
    {
        $nonExistentPath = $this->tempBase.DIRECTORY_SEPARATOR.'non_existent_staging';

        // 예외 없이 정상 실행
        ExtensionPendingHelper::cleanupStaging($nonExistentPath);

        $this->assertFalse(File::isDirectory($nonExistentPath));
    }
}
