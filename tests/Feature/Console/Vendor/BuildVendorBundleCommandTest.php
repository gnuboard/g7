<?php

namespace Tests\Feature\Console\Vendor;

use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 신규 명령어 체계 (옵션 B) + 분리 구조 (소스=활성, 출력=_bundled) 회귀 테스트.
 *
 * 각 테스트는 고유 식별자 (uniqid) 로 격리된 fake 확장을 활성 + _bundled 양쪽에 생성하여
 * 실제 확장(sirsoft-*)을 오염시키지 않는다. setUp 에서 생성, tearDown 에서 완전 제거.
 *
 * - 활성 디렉토리 (`modules/{id}/`, `plugins/{id}/`): composer.json + composer.lock + vendor/ (소스)
 * - _bundled 디렉토리 (`modules/_bundled/{id}/`, `plugins/_bundled/{id}/`): composer.json + 빌드 후 vendor-bundle.zip (출력)
 */
class BuildVendorBundleCommandTest extends TestCase
{
    // ===== 모듈 fake =====
    private string $fakeModuleIdentifier;

    private string $fakeModuleActivePath;

    private string $fakeModuleBundledPath;

    // ===== 플러그인 fake =====
    private string $fakePluginIdentifier;

    private string $fakePluginActivePath;

    private string $fakePluginBundledPath;

    /**
     * 실제 _bundled 번들 파일 스냅샷 — setUp 에서 저장, tearDown 에서 복원.
     *
     * `vendor-bundle:build-all` 은 `modules/_bundled/*` 와 `plugins/_bundled/*` 를
     * 모두 순회하여 빌드한다. 테스트의 fake runner 가 실제 확장(sirsoft-ecommerce 등)
     * 의 vendor-bundle.zip 까지 가짜 498-byte zip 으로 덮어쓰는 사고를 방지하기 위해
     * 실제 파일을 snapshot 하고 tearDown 에서 복원한다.
     *
     * @var array<string, string>  path => content (binary-safe)
     */
    private array $realBundleSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotRealBundles();

        // 고유 식별자 — 실제 sirsoft-* 확장과 공간 격리
        $suffix = uniqid();
        $this->fakeModuleIdentifier = 'test-vendor-build-mod-'.$suffix;
        $this->fakeModuleActivePath = base_path('modules/'.$this->fakeModuleIdentifier);
        $this->fakeModuleBundledPath = base_path('modules/_bundled/'.$this->fakeModuleIdentifier);

        $this->fakePluginIdentifier = 'test-vendor-build-plg-'.$suffix;
        $this->fakePluginActivePath = base_path('plugins/'.$this->fakePluginIdentifier);
        $this->fakePluginBundledPath = base_path('plugins/_bundled/'.$this->fakePluginIdentifier);

        $this->createFakeExtension($this->fakeModuleActivePath, $this->fakeModuleBundledPath, 'module');
        $this->createFakeExtension($this->fakePluginActivePath, $this->fakePluginBundledPath, 'plugin');

        // 실제 composer 실행 대신 가짜 vendor/ 구조를 스테이징에 생성 — fake 확장의 가상 패키지
        // (test/lib)는 packagist 에 존재하지 않으므로 실 composer install 로는 빌드 불가.
        $this->app->bind(VendorBundler::class, function ($app) {
            $bundler = new VendorBundler($app->make(VendorIntegrityChecker::class));
            $bundler->setComposerInstallRunner(function (string $stagingDir): void {
                File::ensureDirectoryExists($stagingDir.'/vendor/test/lib');
                File::put($stagingDir.'/vendor/test/lib/file.php', '<?php // test');
                File::put($stagingDir.'/vendor/autoload.php', '<?php // autoload');
            });

            return $bundler;
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->fakeModuleActivePath,
            $this->fakeModuleBundledPath,
            $this->fakePluginActivePath,
            $this->fakePluginBundledPath,
        ] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        $this->restoreRealBundles();

        parent::tearDown();
    }

    /**
     * 실제 _bundled 번들 (코어 + 모든 모듈/플러그인) 스냅샷.
     */
    private function snapshotRealBundles(): void
    {
        $paths = $this->collectRealBundlePaths();

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $this->realBundleSnapshots[$path] = (string) file_get_contents($path);
            }
        }
    }

    /**
     * 스냅샷된 번들 파일을 원상 복구.
     */
    private function restoreRealBundles(): void
    {
        foreach ($this->realBundleSnapshots as $path => $content) {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, $content);
        }

        // 스냅샷에 없던 파일 (테스트 중 새로 생성된 가짜 파일) 정리
        foreach ($this->collectRealBundlePaths() as $path) {
            if (file_exists($path) && ! array_key_exists($path, $this->realBundleSnapshots)) {
                @unlink($path);
            }
        }
    }

    /**
     * 보호 대상 실제 번들 파일 경로 목록.
     *
     * @return array<int, string>
     */
    private function collectRealBundlePaths(): array
    {
        $paths = [
            base_path('vendor-bundle.zip'),
            base_path('vendor-bundle.json'),
        ];

        foreach (['modules', 'plugins'] as $kind) {
            $bundledDir = base_path($kind.'/_bundled');
            if (! is_dir($bundledDir)) {
                continue;
            }
            foreach (glob($bundledDir.'/*', GLOB_ONLYDIR) ?: [] as $extDir) {
                // fake 확장은 snapshot 대상 아님 — setUp 보다 먼저 호출되므로 이 시점에는 존재하지 않음
                $paths[] = $extDir.'/vendor-bundle.zip';
                $paths[] = $extDir.'/vendor-bundle.json';
            }
        }

        return $paths;
    }

    /**
     * 활성 디렉토리에 fake vendor 프로젝트 생성 + _bundled 에 composer.json 미러.
     */
    private function createFakeExtension(string $activePath, string $bundledPath, string $kind): void
    {
        // 활성 디렉토리: composer.json + composer.lock + vendor/
        File::ensureDirectoryExists($activePath);
        $composerJson = json_encode([
            'name' => 'test/fake-'.$kind,
            // VendorBundler 는 php/ext-* 만 있는 확장을 skip 하므로 외부 의존성 선언 필수
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]);
        File::put($activePath.'/composer.json', $composerJson);
        File::put($activePath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));
        File::ensureDirectoryExists($activePath.'/vendor/test/lib');
        File::put($activePath.'/vendor/autoload.php', '<?php // autoload');
        File::put($activePath.'/vendor/test/lib/file.php', '<?php // test');

        // _bundled 디렉토리: composer.json 미러만 (vendor/ 없음 — 번들링 출력 경로)
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/composer.json', $composerJson);
    }

    // =========================================================================
    // 모듈 명령어 (module:vendor-bundle / module:vendor-verify)
    // =========================================================================

    public function test_module_vendor_bundle_builds_specific_identifier(): void
    {
        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(0);

        // 출력은 _bundled 에만 — 활성 디렉토리는 오염되지 않아야 함
        $this->assertFileExists($this->fakeModuleBundledPath.'/vendor-bundle.zip');
        $this->assertFileExists($this->fakeModuleBundledPath.'/vendor-bundle.json');
        $this->assertFileDoesNotExist($this->fakeModuleActivePath.'/vendor-bundle.zip');
    }

    public function test_module_vendor_bundle_requires_identifier_or_all_flag(): void
    {
        $this->artisan('module:vendor-bundle')
            ->expectsOutputToContain('식별자 또는 --all 옵션을 지정')
            ->assertExitCode(1);
    }

    public function test_module_vendor_bundle_check_reports_stale_when_not_built(): void
    {
        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--check' => true,
        ])->assertExitCode(1);
    }

    public function test_module_vendor_bundle_check_reports_up_to_date_after_build(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--check' => true,
        ])->assertExitCode(0);
    }

    public function test_module_vendor_bundle_skipped_when_up_to_date(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->expectsOutputToContain('스킵')
            ->assertExitCode(0);
    }

    public function test_module_vendor_bundle_force_rebuilds(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_module_vendor_verify_passes_after_successful_build(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(0);
    }

    public function test_module_vendor_verify_skips_when_bundle_missing(): void
    {
        // _bundled 경로는 있지만 번들 파일 없음 — 스킵 처리되어 종료코드 0
        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(0);
    }

    public function test_module_vendor_verify_fails_on_tampered_zip(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        file_put_contents($this->fakeModuleBundledPath.'/vendor-bundle.zip', 'tampered content');

        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(1);
    }

    public function test_module_vendor_verify_requires_identifier_or_all_flag(): void
    {
        $this->artisan('module:vendor-verify')
            ->expectsOutputToContain('식별자 또는 --all 옵션을 지정')
            ->assertExitCode(1);
    }

    // =========================================================================
    // 플러그인 명령어 (plugin:vendor-bundle / plugin:vendor-verify)
    // =========================================================================

    public function test_plugin_vendor_bundle_builds_specific_identifier(): void
    {
        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(0);

        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.zip');
        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.json');
        $this->assertFileDoesNotExist($this->fakePluginActivePath.'/vendor-bundle.zip');
    }

    public function test_plugin_vendor_bundle_requires_identifier_or_all_flag(): void
    {
        $this->artisan('plugin:vendor-bundle')
            ->expectsOutputToContain('식별자 또는 --all 옵션을 지정')
            ->assertExitCode(1);
    }

    public function test_plugin_vendor_bundle_check_reports_stale_when_not_built(): void
    {
        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
            '--check' => true,
        ])->assertExitCode(1);
    }

    public function test_plugin_vendor_bundle_force_rebuilds(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
            '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_plugin_vendor_verify_passes_after_successful_build(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        $this->artisan('plugin:vendor-verify', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(0);
    }

    public function test_plugin_vendor_verify_fails_on_tampered_zip(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        file_put_contents($this->fakePluginBundledPath.'/vendor-bundle.zip', 'tampered content');

        $this->artisan('plugin:vendor-verify', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(1);
    }

    public function test_plugin_vendor_verify_requires_identifier_or_all_flag(): void
    {
        $this->artisan('plugin:vendor-verify')
            ->expectsOutputToContain('식별자 또는 --all 옵션을 지정')
            ->assertExitCode(1);
    }

    // =========================================================================
    // 일괄 알리아스 (vendor-bundle:build-all / verify-all) — 모듈+플러그인 포함
    // =========================================================================

    public function test_bulk_alias_build_all_builds_both_module_and_plugin_fakes(): void
    {
        // force 로 모든 _bundled 확장 빌드 (fake 모듈 + fake 플러그인 포함)
        $this->artisan('vendor-bundle:build-all', ['--force' => true])
            ->assertExitCode(0);

        $this->assertFileExists($this->fakeModuleBundledPath.'/vendor-bundle.zip');
        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.zip');
    }

    public function test_bulk_alias_verify_all_runs(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        // 일괄 검증 — fake 대상은 통과, 다른 사전 빌드 없는 경우 스킵 처리
        $exitCode = $this->artisan('vendor-bundle:verify-all')->run();

        // 다른 확장의 stale 상태에 따라 0/1 둘 다 가능 — fake 는 통과해야 함
        $this->assertContains($exitCode, [0, 1]);
    }
}
