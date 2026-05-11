<?php

namespace Tests\Feature\Installer;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 인스톨러 vendor-bundle-installer shim 의 bootstrap/cache 정리 회귀 테스트.
 *
 * 시나리오: 이전 개발 환경에서 생성된 bootstrap/cache/packages.php 가
 * 제거된 dev 패키지(Laravel\Boost 등) ServiceProvider 를 참조하는 경우,
 * bundled vendor 추출 후 key:generate 단계에서 "Class ... not found" 가 발생한다.
 *
 * 이 테스트는 clearLaravelCompiledCache() shim 이 packages.php / services.php /
 * config.php 를 모두 제거하여 재생성을 강제하는지 검증한다.
 */
class BootstrapCacheCleanupTest extends TestCase
{
    private string $testBase;

    private string $bootstrapCacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBase = storage_path('app/test-bootstrap-cache-'.uniqid());
        $this->bootstrapCacheDir = $this->testBase.'/bootstrap/cache';
        File::ensureDirectoryExists($this->bootstrapCacheDir);

        require_once base_path('public/install/includes/vendor-bundle-installer.php');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testBase)) {
            File::deleteDirectory($this->testBase);
        }
        parent::tearDown();
    }

    public function test_clear_laravel_compiled_cache_removes_packages_services_config(): void
    {
        $packages = $this->bootstrapCacheDir.'/packages.php';
        $services = $this->bootstrapCacheDir.'/services.php';
        $config = $this->bootstrapCacheDir.'/config.php';

        // stale dev 캐시 시뮬레이션 — 존재하지 않는 BoostServiceProvider 참조
        File::put($packages, "<?php return ['laravel/boost' => ['providers' => ['Laravel\\\\Boost\\\\BoostServiceProvider']]];");
        File::put($services, "<?php return ['providers' => ['Laravel\\\\Boost\\\\BoostServiceProvider']];");
        File::put($config, "<?php return [];");

        $cleared = clearLaravelCompiledCache($this->testBase);

        $this->assertFileDoesNotExist($packages, 'packages.php 가 삭제되어야 합니다');
        $this->assertFileDoesNotExist($services, 'services.php 가 삭제되어야 합니다');
        $this->assertFileDoesNotExist($config, 'config.php 가 삭제되어야 합니다');

        $this->assertContains('packages.php', $cleared);
        $this->assertContains('services.php', $cleared);
        $this->assertContains('config.php', $cleared);
    }

    public function test_clear_laravel_compiled_cache_is_idempotent_when_files_missing(): void
    {
        // 캐시 파일이 처음부터 없는 환경 (신규 설치)
        $cleared = clearLaravelCompiledCache($this->testBase);

        $this->assertSame([], $cleared, '삭제할 파일이 없으면 빈 배열을 반환해야 합니다');
    }

    public function test_clear_laravel_compiled_cache_preserves_unrelated_files(): void
    {
        $unrelated = $this->bootstrapCacheDir.'/autoload-extensions.php';
        File::put($unrelated, '<?php return [];');

        clearLaravelCompiledCache($this->testBase);

        $this->assertFileExists($unrelated, '관련 없는 캐시 파일은 보존되어야 합니다');
    }

    public function test_clear_laravel_compiled_cache_returns_empty_when_cache_dir_missing(): void
    {
        File::deleteDirectory($this->bootstrapCacheDir);

        $cleared = clearLaravelCompiledCache($this->testBase);

        $this->assertSame([], $cleared);
    }
}
