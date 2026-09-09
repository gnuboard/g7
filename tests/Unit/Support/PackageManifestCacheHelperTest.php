<?php

namespace Tests\Unit\Support;

use App\Support\PackageManifestCacheHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 패키지 매니페스트 캐시 헬퍼 단위 테스트 (dev-g7 #658).
 *
 * 이 헬퍼가 삭제 로직의 단일 지점이다 — spawn 직전 선정리(`CoreUpdateCommand`)와 업데이트
 * 마무리(`CoreUpdateService::clearAllCaches()`)가 같은 코드를 쓴다.
 *
 * 격리: 실제 `bootstrap/cache/*` 를 건드리지 않도록 `APP_PACKAGES_CACHE`/`APP_SERVICES_CACHE`
 * 를 base_path 상대 경로로 재지정한다(`Application::normalizeCachePath` 규칙).
 */
class PackageManifestCacheHelperTest extends TestCase
{
    /** @var array<string, string|null> 테스트가 덮어쓴 env 의 원값 (3채널 복원용) */
    private array $originalEnv = [];

    private string $packagesPath;

    private string $servicesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $uniq = uniqid();
        $relPackages = "storage/framework/testing/manifest-helper-{$uniq}/packages.php";
        $relServices = "storage/framework/testing/manifest-helper-{$uniq}/services.php";

        $this->packagesPath = base_path($relPackages);
        $this->servicesPath = base_path($relServices);

        File::ensureDirectoryExists(dirname($this->packagesPath));

        $this->setEnv('APP_PACKAGES_CACHE', $relPackages);
        $this->setEnv('APP_SERVICES_CACHE', $relServices);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $this->originalEnv = [];

        $dir = dirname($this->packagesPath);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * env 로 재지정한 경로의 두 파일을 지운다 (하드코딩된 bootstrap/cache 가 아니라).
     *
     * @effects PackageManifestCacheHelper_clear_unlinks_packages_and_services_at_configured_paths
     */
    #[Test]
    public function clear_는_설정된_경로의_packages_와_services_를_지운다(): void
    {
        File::put($this->packagesPath, "<?php return [];\n");
        File::put($this->servicesPath, "<?php return [];\n");

        $this->assertSame($this->packagesPath, $this->app->getCachedPackagesPath(), '전제: env 재지정이 경로를 정한다');
        $this->assertSame($this->servicesPath, $this->app->getCachedServicesPath(), '전제: env 재지정이 경로를 정한다');

        $remaining = PackageManifestCacheHelper::clear();

        $this->assertFileDoesNotExist($this->packagesPath);
        $this->assertFileDoesNotExist($this->servicesPath);
        $this->assertSame([], $remaining, '전부 지웠으면 남은 파일이 없다');
    }

    /**
     * 파일이 없어도 예외를 던지지 않는다 (부팅 직전에 불리므로 실패가 부팅을 막으면 안 된다).
     */
    #[Test]
    public function clear_는_파일이_없으면_아무_일도_하지_않는다(): void
    {
        $this->assertFileDoesNotExist($this->packagesPath, '전제: 파일이 없다');

        $remaining = PackageManifestCacheHelper::clear();

        $this->assertFileDoesNotExist($this->packagesPath);
        $this->assertFileDoesNotExist($this->servicesPath);
        $this->assertSame([], $remaining, '원래 없던 파일은 "지우지 못한 파일" 이 아니다');
    }

    /**
     * 지우지 못한 파일은 경로로 돌려주고, 지울 수 있는 형제 파일은 그대로 지운다.
     *
     * 호출부(spawn 직전)가 이 목록을 업그레이드 로그에 남긴다 — 권한·소유권 불일치면 자식의 자가
     * 치유도 같은 이유로 실패해 증상은 「Class not found」 그대로인데, 그 기록이 원인을 가리키는
     * 유일한 흔적이다. 삭제 실패는 플랫폼마다 실제로 만든다: Windows 는 열린 핸들이, POSIX 는 부모
     * 디렉토리의 쓰기 권한이 삭제를 막는다.
     *
     * @effects PackageManifestCacheHelper_clear_returns_paths_it_could_not_remove
     */
    #[Test]
    public function clear_는_지우지_못한_파일의_경로를_돌려준다(): void
    {
        File::put($this->packagesPath, "<?php return [];\n");
        File::put($this->servicesPath, "<?php return [];\n");

        $release = $this->makeUndeletable($this->packagesPath);

        try {
            $remaining = PackageManifestCacheHelper::clear();
        } finally {
            $release();
        }

        $this->assertSame([$this->packagesPath], $remaining, '지우지 못한 파일만 경로로 돌려준다');
        $this->assertFileExists($this->packagesPath, '삭제가 막힌 파일은 그대로 남는다');
        $this->assertFileDoesNotExist($this->servicesPath, '지울 수 있는 형제 파일은 지운다');
    }

    /**
     * 파일을 현재 프로세스가 삭제할 수 없는 상태로 만들고, 되돌리는 클로저를 반환합니다.
     *
     * Windows 는 읽기 전용 속성(0444)이 삭제를 막고(PHP 7.3+ 는 파일을 `FILE_SHARE_DELETE` 로 열어
     * 열린 핸들로는 막히지 않는다 — 실측), POSIX 는 부모 디렉토리의 쓰기 권한이 삭제를 막는다(root 는
     * 권한을 우회하므로 이 방법으로 실패를 만들 수 없다).
     *
     * @param  string  $path  삭제를 막을 파일
     * @return \Closure 원상 복구 클로저
     */
    private function makeUndeletable(string $path): \Closure
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertTrue(chmod($path, 0444), '전제: 읽기 전용 속성을 건다');

            return static function () use ($path): void {
                if (is_file($path)) {
                    chmod($path, 0644);
                }
            };
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root 는 디렉토리 권한으로 삭제 실패를 만들 수 없다 — 비-root 로 실행해야 이 축이 측정된다');
        }

        $dir = dirname($path);
        $this->assertTrue(chmod($dir, 0555), '전제: 부모 디렉토리의 쓰기 권한을 뺀다');

        return static function () use ($dir): void {
            chmod($dir, 0755);
        };
    }

    /**
     * rebuild() 는 삭제 후 package:discover 로 다시 만든다.
     *
     * @effects clearAllCaches_rebuilds_package_manifest_via_helper
     */
    #[Test]
    public function rebuild_는_지운_뒤_package_discover_를_한_번_호출한다(): void
    {
        File::put($this->packagesPath, "<?php return [];\n");
        File::put($this->servicesPath, "<?php return [];\n");

        Artisan::shouldReceive('call')->once()->with('package:discover')->andReturn(0);

        PackageManifestCacheHelper::rebuild();

        $this->assertFileDoesNotExist($this->packagesPath, 'rebuild 는 먼저 지운다');
        $this->assertFileDoesNotExist($this->servicesPath, 'rebuild 는 먼저 지운다');
    }

    /**
     * env 를 세 채널($_ENV/$_SERVER/putenv)에 세우고 원값을 복원용으로 보관합니다.
     *
     * @param  string  $key  환경변수 이름
     * @param  string|null  $value  세울 값. null 이면 제거
     */
    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $current = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $this->originalEnv[$key] = ($current === false || $current === null) ? null : (string) $current;
        }

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
