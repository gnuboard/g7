<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * uninstall 시 활성 디렉토리 삭제 검증 테스트
 *
 * ModuleManager, PluginManager, TemplateManager의 uninstall 시
 * ExtensionPendingHelper::deleteExtensionDirectory()가 활성 디렉토리를
 * 완전히 삭제하며, _bundled/_pending 원본은 보존되는지 검증합니다.
 */
class UninstallDirectoryDeletionTest extends TestCase
{
    private string $testBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 임시 디렉토리 생성
        $this->testBasePath = sys_get_temp_dir().'/g7_test_uninstall_'.uniqid();
        File::ensureDirectoryExists($this->testBasePath);
    }

    protected function tearDown(): void
    {
        // 테스트 디렉토리 정리
        if (File::isDirectory($this->testBasePath)) {
            File::deleteDirectory($this->testBasePath);
        }

        parent::tearDown();
    }

    /**
     * 활성 디렉토리가 완전히 삭제되는지 확인합니다.
     */
    public function test_delete_extension_directory_removes_active_directory(): void
    {
        // 활성 디렉토리 생성 (설치된 모듈을 시뮬레이션)
        $activePath = $this->testBasePath.'/test-module';
        File::ensureDirectoryExists($activePath.'/src');
        File::put($activePath.'/module.json', '{}');
        File::put($activePath.'/src/Module.php', '<?php');

        $this->assertDirectoryExists($activePath);

        // 삭제 실행
        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'test-module');

        // 활성 디렉토리가 완전히 삭제됨
        $this->assertDirectoryDoesNotExist($activePath);
    }

    /**
     * 활성 디렉토리 삭제 후에도 _bundled 원본이 보존되는지 확인합니다.
     */
    public function test_bundled_preserved_after_active_directory_deletion(): void
    {
        // _bundled 원본 생성
        $bundledPath = $this->testBasePath.'/_bundled/test-module';
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/module.json', json_encode(['version' => '1.0.0']));

        // 활성 디렉토리 생성 (설치된 복사본)
        $activePath = $this->testBasePath.'/test-module';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/module.json', json_encode(['version' => '1.0.0']));

        // 활성 디렉토리만 삭제
        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'test-module');

        // 활성 디렉토리 삭제됨
        $this->assertDirectoryDoesNotExist($activePath);

        // _bundled 원본은 보존됨
        $this->assertDirectoryExists($bundledPath);
        $this->assertFileExists($bundledPath.'/module.json');
    }

    /**
     * 활성 디렉토리 삭제 후에도 _pending 원본이 보존되는지 확인합니다.
     */
    public function test_pending_preserved_after_active_directory_deletion(): void
    {
        // _pending 원본 생성
        $pendingPath = $this->testBasePath.'/_pending/test-plugin';
        File::ensureDirectoryExists($pendingPath);
        File::put($pendingPath.'/plugin.json', json_encode(['version' => '2.0.0']));

        // 활성 디렉토리 생성 (설치된 복사본)
        $activePath = $this->testBasePath.'/test-plugin';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/plugin.json', json_encode(['version' => '1.0.0']));

        // 활성 디렉토리만 삭제
        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'test-plugin');

        // 활성 디렉토리 삭제됨
        $this->assertDirectoryDoesNotExist($activePath);

        // _pending 원본은 보존됨
        $this->assertDirectoryExists($pendingPath);
        $this->assertFileExists($pendingPath.'/plugin.json');
    }

    /**
     * 삭제 후 _bundled에서 재설치(복사)가 가능한지 확인합니다.
     */
    public function test_reinstall_from_bundled_after_uninstall(): void
    {
        // _bundled 원본 생성
        $bundledPath = $this->testBasePath.'/_bundled/test-template';
        File::ensureDirectoryExists($bundledPath.'/layouts');
        File::put($bundledPath.'/template.json', json_encode(['identifier' => 'test-template', 'version' => '1.0.0']));
        File::put($bundledPath.'/layouts/base.json', '{}');

        // 활성 디렉토리 생성 → 삭제 (uninstall 시뮬레이션)
        $activePath = $this->testBasePath.'/test-template';
        ExtensionPendingHelper::copyToActive($bundledPath, $activePath);
        $this->assertDirectoryExists($activePath);

        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'test-template');
        $this->assertDirectoryDoesNotExist($activePath);

        // _bundled에서 다시 복사 (재설치 시뮬레이션)
        ExtensionPendingHelper::copyToActive($bundledPath, $activePath);

        // 재설치 성공
        $this->assertDirectoryExists($activePath);
        $this->assertFileExists($activePath.'/template.json');
        $this->assertFileExists($activePath.'/layouts/base.json');
    }

    /**
     * 중첩된 서브디렉토리가 포함된 디렉토리도 완전히 삭제되는지 확인합니다.
     */
    public function test_deeply_nested_directories_deleted_completely(): void
    {
        $activePath = $this->testBasePath.'/test-module';
        File::ensureDirectoryExists($activePath.'/src/Http/Controllers/Admin');
        File::ensureDirectoryExists($activePath.'/database/migrations');
        File::ensureDirectoryExists($activePath.'/resources/layouts/admin');
        File::ensureDirectoryExists($activePath.'/vendor/autoload');

        File::put($activePath.'/module.json', '{}');
        File::put($activePath.'/src/Http/Controllers/Admin/TestController.php', '<?php');
        File::put($activePath.'/database/migrations/2025_01_01_000001_test.php', '<?php');
        File::put($activePath.'/vendor/autoload/autoload.php', '<?php');

        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'test-module');

        $this->assertDirectoryDoesNotExist($activePath);
    }

    /**
     * 존재하지 않는 디렉토리 삭제 시 예외가 발생하지 않는지 확인합니다.
     */
    public function test_deleting_nonexistent_directory_does_not_throw(): void
    {
        // 예외 미발생 확인
        ExtensionPendingHelper::deleteExtensionDirectory($this->testBasePath, 'nonexistent-extension');

        $this->assertTrue(true);
    }
}
