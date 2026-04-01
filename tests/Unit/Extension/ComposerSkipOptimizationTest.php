<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Composer Install 스킵 최적화 테스트
 *
 * composer.json/composer.lock 비교를 통한 composer install 스킵 로직과
 * vendor 디렉토리 보존 로직을 검증합니다.
 */
class ComposerSkipOptimizationTest extends TestCase
{
    private ExtensionManager $extensionManager;

    private CoreUpdateService $coreUpdateService;

    /**
     * 테스트용 임시 디렉토리 경로
     */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->extensionManager = app(ExtensionManager::class);
        $this->coreUpdateService = app(CoreUpdateService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::exists($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 테스트용 디렉토리를 생성하고 추적합니다.
     */
    private function createTempDir(string $name): string
    {
        $dir = storage_path("app/testing/{$name}_".uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    // ========================================================================
    // hasComposerDependenciesAt 테스트
    // ========================================================================

    /**
     * 지정 경로의 composer.json에서 외부 의존성을 감지합니다.
     */
    public function test_has_composer_dependencies_at_detects_external_packages(): void
    {
        $dir = $this->createTempDir('deps-at');
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'php' => '^8.2',
                'vendor/package' => '^1.0',
            ],
        ]));

        $this->assertTrue($this->extensionManager->hasComposerDependenciesAt($dir));
    }

    /**
     * php와 ext-*만 있으면 외부 의존성이 없다고 판단합니다.
     */
    public function test_has_composer_dependencies_at_returns_false_for_php_only(): void
    {
        $dir = $this->createTempDir('no-deps-at');
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'php' => '^8.2',
                'ext-json' => '*',
            ],
        ]));

        $this->assertFalse($this->extensionManager->hasComposerDependenciesAt($dir));
    }

    /**
     * composer.json이 없으면 외부 의존성이 없다고 판단합니다.
     */
    public function test_has_composer_dependencies_at_returns_false_when_no_composer_json(): void
    {
        $dir = $this->createTempDir('no-json');

        $this->assertFalse($this->extensionManager->hasComposerDependenciesAt($dir));
    }

    // ========================================================================
    // isComposerUnchanged 테스트
    // ========================================================================

    /**
     * composer.json과 composer.lock이 모두 동일하면 true를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_true_when_files_identical(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        $composerJson = json_encode(['require' => ['vendor/pkg' => '^1.0']]);
        $composerLock = json_encode(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0']]]);

        File::put($staging.'/composer.json', $composerJson);
        File::put($staging.'/composer.lock', $composerLock);
        File::put($active.'/composer.json', $composerJson);
        File::put($active.'/composer.lock', $composerLock);
        File::ensureDirectoryExists($active.'/vendor');

        $this->assertTrue($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    /**
     * composer.json이 다르면 false를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_false_when_json_differs(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        File::put($staging.'/composer.json', json_encode(['require' => ['vendor/pkg' => '^2.0']]));
        File::put($active.'/composer.json', json_encode(['require' => ['vendor/pkg' => '^1.0']]));
        File::ensureDirectoryExists($active.'/vendor');

        $this->assertFalse($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    /**
     * composer.lock이 다르면 false를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_false_when_lock_differs(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        $composerJson = json_encode(['require' => ['vendor/pkg' => '^1.0']]);
        File::put($staging.'/composer.json', $composerJson);
        File::put($active.'/composer.json', $composerJson);
        File::put($staging.'/composer.lock', json_encode(['hash' => 'new']));
        File::put($active.'/composer.lock', json_encode(['hash' => 'old']));
        File::ensureDirectoryExists($active.'/vendor');

        $this->assertFalse($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    /**
     * 활성 디렉토리가 없으면 false를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_false_when_active_not_exists(): void
    {
        $staging = $this->createTempDir('staging');
        File::put($staging.'/composer.json', json_encode(['require' => []]));

        $this->assertFalse(
            $this->extensionManager->isComposerUnchanged($staging, '/nonexistent/path')
        );
    }

    /**
     * 활성 vendor/가 없으면 false를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_false_when_active_vendor_missing(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        $composerJson = json_encode(['require' => ['vendor/pkg' => '^1.0']]);
        File::put($staging.'/composer.json', $composerJson);
        File::put($active.'/composer.json', $composerJson);
        // vendor/ 디렉토리 없음

        $this->assertFalse($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    /**
     * 한쪽에만 composer.lock이 있으면 false를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_false_when_lock_exists_only_in_one(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        $composerJson = json_encode(['require' => ['vendor/pkg' => '^1.0']]);
        File::put($staging.'/composer.json', $composerJson);
        File::put($staging.'/composer.lock', json_encode(['hash' => 'abc']));
        File::put($active.'/composer.json', $composerJson);
        // active에 lock 없음
        File::ensureDirectoryExists($active.'/vendor');

        $this->assertFalse($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    /**
     * 양쪽 모두 composer.lock이 없으면 json만 비교하여 true를 반환합니다.
     */
    public function test_is_composer_unchanged_returns_true_when_both_no_lock(): void
    {
        $staging = $this->createTempDir('staging');
        $active = $this->createTempDir('active');

        $composerJson = json_encode(['require' => ['vendor/pkg' => '^1.0']]);
        File::put($staging.'/composer.json', $composerJson);
        File::put($active.'/composer.json', $composerJson);
        // 양쪽 모두 lock 없음
        File::ensureDirectoryExists($active.'/vendor');

        $this->assertTrue($this->extensionManager->isComposerUnchanged($staging, $active));
    }

    // ========================================================================
    // copyVendorFromActive 테스트
    // ========================================================================

    /**
     * 활성 vendor/를 스테이징으로 복사합니다.
     */
    public function test_copy_vendor_from_active_copies_vendor_directory(): void
    {
        $active = $this->createTempDir('active');
        $staging = $this->createTempDir('staging');

        // 활성 vendor/ 생성
        File::ensureDirectoryExists($active.'/vendor/some-package');
        File::put($active.'/vendor/autoload.php', '<?php // autoload');
        File::put($active.'/vendor/some-package/file.php', '<?php // pkg');

        ExtensionPendingHelper::copyVendorFromActive($active, $staging);

        $this->assertTrue(File::isDirectory($staging.'/vendor'));
        $this->assertTrue(File::exists($staging.'/vendor/autoload.php'));
        $this->assertTrue(File::exists($staging.'/vendor/some-package/file.php'));
    }

    /**
     * 활성 vendor/가 없으면 예외 없이 무시합니다.
     */
    public function test_copy_vendor_from_active_skips_when_no_vendor(): void
    {
        $active = $this->createTempDir('active');
        $staging = $this->createTempDir('staging');

        // vendor/ 없는 상태에서 호출
        ExtensionPendingHelper::copyVendorFromActive($active, $staging);

        $this->assertFalse(File::isDirectory($staging.'/vendor'));
    }

    // ========================================================================
    // isComposerUnchangedForCore 테스트
    // ========================================================================

    /**
     * 코어: composer.json과 composer.lock이 동일하면 true를 반환합니다.
     */
    public function test_core_is_composer_unchanged_returns_true_when_identical(): void
    {
        $pending = $this->createTempDir('pending');

        // 운영 디렉토리의 파일을 _pending에 복사 (동일하게)
        File::copy(base_path('composer.json'), $pending.'/composer.json');
        File::copy(base_path('composer.lock'), $pending.'/composer.lock');

        $this->assertTrue($this->coreUpdateService->isComposerUnchangedForCore($pending));
    }

    /**
     * 코어: composer.json이 다르면 false를 반환합니다.
     */
    public function test_core_is_composer_unchanged_returns_false_when_json_differs(): void
    {
        $pending = $this->createTempDir('pending');

        File::put($pending.'/composer.json', json_encode(['name' => 'different/project']));
        File::copy(base_path('composer.lock'), $pending.'/composer.lock');

        $this->assertFalse($this->coreUpdateService->isComposerUnchangedForCore($pending));
    }

    /**
     * 코어: composer.lock이 다르면 false를 반환합니다.
     */
    public function test_core_is_composer_unchanged_returns_false_when_lock_differs(): void
    {
        $pending = $this->createTempDir('pending');

        File::copy(base_path('composer.json'), $pending.'/composer.json');
        File::put($pending.'/composer.lock', json_encode(['content-hash' => 'different']));

        $this->assertFalse($this->coreUpdateService->isComposerUnchangedForCore($pending));
    }

    /**
     * 코어: _pending에 composer.json이 없으면 false를 반환합니다.
     */
    public function test_core_is_composer_unchanged_returns_false_when_pending_json_missing(): void
    {
        $pending = $this->createTempDir('pending');

        $this->assertFalse($this->coreUpdateService->isComposerUnchangedForCore($pending));
    }
}
