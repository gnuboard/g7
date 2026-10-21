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
     * 스냅샷은 **파일 복사**로 잡는다 — 내용을 PHP 문자열로 들고 있지 않는다.
     *
     * 루트 `vendor-bundle.zip` 하나가 15MB 에 육박하는데, 종전 구현은 그것을 `setUp` 마다
     * 메모리로 통째 읽었다. 이 클래스의 테스트 수(14+)만큼 반복되면서 전량 스위트의
     * `memory_limit`(1G)을 밀어붙였고, 실제로 이 지점에서 **OOM 으로 스위트가 중단**돼
     * 그 뒤 결과 전부를 신호로 쓸 수 없게 만들었다.
     *
     * @var array<string, string> 원본 경로 => 임시 복사본 경로
     */
    private array $realBundleSnapshots = [];

    /** 스냅샷 복사본을 모아 두는 임시 디렉토리 */
    private ?string $snapshotDir = null;

    /**
     * 실제 번들 파일을 스냅샷하고, 고유 식별자의 가짜 모듈·플러그인을 만들고, composer 실행을 가짜 vendor 생성으로 바꾼다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가짜 확장 디렉토리를 지우고 실제 번들 파일을 스냅샷에서 복원한다.
     *
     * @return void 반환값 없음
     */
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

        // 스냅샷 해제: 실제 vendor-bundle.zip 은 10MB 단위라 PHPUnit 이 테스트 인스턴스를 스위트
        // 종료까지 붙들면 테스트 메서드 수만큼 메모리에 남는다. 전체 스위트를 한 프로세스로
        // 실행할 때 memory_limit 을 소진시키는 원인이므로 복구 직후 즉시 비운다.
        $this->realBundleSnapshots = [];

        parent::tearDown();
    }

    /**
     * 실제 _bundled 번들 (코어 + 모든 모듈/플러그인) 스냅샷.
     *
     * @return void 반환값 없음
     */
    private function snapshotRealBundles(): void
    {
        $this->snapshotDir = storage_path('framework/testing/vendor_bundle_snapshot_'.uniqid());
        File::ensureDirectoryExists($this->snapshotDir);

        foreach ($this->collectRealBundlePaths() as $index => $path) {
            if (! file_exists($path)) {
                continue;
            }

            // 내용을 메모리로 읽지 않고 파일 대 파일로 복사한다 (바이너리 안전 + 상수 메모리).
            $copy = $this->snapshotDir.DIRECTORY_SEPARATOR.$index.'_'.basename($path);
            copy($path, $copy);
            $this->realBundleSnapshots[$path] = $copy;
        }
    }

    /**
     * 스냅샷된 번들 파일을 원상 복구.
     *
     * @return void 반환값 없음
     */
    private function restoreRealBundles(): void
    {
        foreach ($this->realBundleSnapshots as $path => $copy) {
            File::ensureDirectoryExists(dirname($path));
            copy($copy, $path);
        }

        // 스냅샷에 없던 파일 (테스트 중 새로 생성된 가짜 파일) 정리
        foreach ($this->collectRealBundlePaths() as $path) {
            if (file_exists($path) && ! array_key_exists($path, $this->realBundleSnapshots)) {
                @unlink($path);
            }
        }

        if ($this->snapshotDir !== null && File::isDirectory($this->snapshotDir)) {
            File::deleteDirectory($this->snapshotDir);
        }

        $this->realBundleSnapshots = [];
        $this->snapshotDir = null;
    }

    /**
     * 보호 대상 실제 번들 파일 경로 목록.
     *
     * @return array<int, string> 코어와 모든 _bundled 확장의 vendor-bundle 파일 경로 목록
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
     *
     * @param  string  $activePath  composer.json·lock·vendor 를 둘 활성 디렉토리 경로
     * @param  string  $bundledPath  composer.json 만 미러할 _bundled 디렉토리 경로
     * @param  string  $kind  확장 종류(module 또는 plugin)
     * @return void 반환값 없음
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

    /**
     * 지정한 모듈의 번들을 _bundled 에만 만들고 활성 디렉토리는 건드리지 않는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_writes_output_to_bundled_only
     *
     * @return void 반환값 없음
     */
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

    /**
     * 식별자와 --all 이 모두 없으면 안내를 출력하고 실패 종료하는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_requires_identifier_or_all_flag
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_requires_identifier_or_all_flag(): void
    {
        $this->artisan('module:vendor-bundle')
            ->expectsOutputToContain(__('modules.commands.vendor_bundle.missing_identifier'))
            ->assertExitCode(1);
    }

    /**
     * 빌드 전 --check 는 번들이 낡았다고 보고하고 실패 종료하는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_check_reports_stale_until_built
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_check_reports_stale_when_not_built(): void
    {
        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--check' => true,
        ])->assertExitCode(1);
    }

    /**
     * 미설치 확장(활성 디렉토리 없음)도 --check 는 _bundled 의 composer.json 을 기준으로 판정한다.
     *
     * 종전에는 활성 디렉토리 부재를 "소스 경로 없음" 으로 보고하고 판정을 건너뛰어, _bundled 에
     * 외부 의존성이 선언됐는데 번들이 없거나 stale 인 상태가 --check 를 통과했다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_check_judges_bundled_composer_when_not_installed
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_check_judges_bundled_when_extension_not_installed(): void
    {
        $identifier = 'test-uninstalled-'.uniqid();
        $bundledPath = base_path('modules/_bundled/'.$identifier);
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/composer.json', json_encode([
            'name' => 'test/uninstalled',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));

        try {
            $this->assertDirectoryDoesNotExist(base_path('modules/'.$identifier));

            $this->artisan('module:vendor-bundle', [
                'identifier' => $identifier,
                '--check' => true,
            ])
                ->doesntExpectOutputToContain('소스 경로 없음')
                ->expectsOutputToContain('STALE')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($bundledPath);
        }
    }

    /**
     * 미설치 + 외부 의존성 없음은 종전과 같이 SKIPPED (번들 대상 아님).
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_check_skips_extension_without_external_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_check_skips_uninstalled_extension_without_external_dependencies(): void
    {
        $identifier = 'test-uninstalled-'.uniqid();
        $bundledPath = base_path('modules/_bundled/'.$identifier);
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/composer.json', json_encode([
            'name' => 'test/uninstalled-plain',
            'require' => ['php' => '^8.2'],
        ]));

        try {
            $this->artisan('module:vendor-bundle', [
                'identifier' => $identifier,
                '--check' => true,
            ])
                ->doesntExpectOutputToContain('소스 경로 없음')
                ->expectsOutputToContain('SKIPPED (외부 composer 의존성 없음)')
                ->assertExitCode(0);
        } finally {
            File::deleteDirectory($bundledPath);
        }
    }

    /**
     * 빌드 직후 --check 는 최신으로 판정해 성공 종료하는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_check_reports_stale_until_built
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_check_reports_up_to_date_after_build(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--check' => true,
        ])->assertExitCode(0);
    }

    /**
     * 이미 최신이면 다시 빌드하지 않고 스킵 안내를 출력하는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_skips_rebuild_when_up_to_date
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_skipped_when_up_to_date(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->expectsOutputToContain('스킵')
            ->assertExitCode(0);
    }

    /**
     * --force 는 최신 상태여도 다시 빌드해 성공 종료하는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_bundle_force_option_rebuilds_successfully
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_bundle_force_rebuilds(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-bundle', [
            'identifier' => $this->fakeModuleIdentifier,
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * 정상 빌드 직후 무결성 검증이 통과하는지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_verify_passes_after_build
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_verify_passes_after_successful_build(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(0);
    }

    /**
     * 번들 파일이 없으면 무결성 검증을 스킵하고 성공 종료하는지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_verify_skips_when_bundle_missing
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_verify_skips_when_bundle_missing(): void
    {
        // _bundled 경로는 있지만 번들 파일 없음 — 스킵 처리되어 종료코드 0
        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(0);
    }

    /**
     * 빌드 뒤 zip 이 변조되면 무결성 검증이 실패하는지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_verify_fails_on_tampered_zip
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_verify_fails_on_tampered_zip(): void
    {
        $this->artisan('module:vendor-bundle', ['identifier' => $this->fakeModuleIdentifier])
            ->assertExitCode(0);

        file_put_contents($this->fakeModuleBundledPath.'/vendor-bundle.zip', 'tampered content');

        $this->artisan('module:vendor-verify', [
            'identifier' => $this->fakeModuleIdentifier,
        ])->assertExitCode(1);
    }

    /**
     * 검증 커맨드도 식별자와 --all 이 모두 없으면 안내 후 실패 종료하는지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_command_requires_identifier_or_all_flag
     *
     * @return void 반환값 없음
     */
    public function test_module_vendor_verify_requires_identifier_or_all_flag(): void
    {
        $this->artisan('module:vendor-verify')
            ->expectsOutputToContain(__('modules.commands.vendor_verify.missing_identifier'))
            ->assertExitCode(1);
    }

    // =========================================================================
    // 플러그인 명령어 (plugin:vendor-bundle / plugin:vendor-verify)
    // =========================================================================

    /**
     * 지정한 플러그인의 번들을 _bundled 에만 만들고 활성 디렉토리는 건드리지 않는지 검증한다.
     *
     * @scenario command=plugin_bundle
     *
     * @effects vendor_bundle_writes_output_to_bundled_only
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_bundle_builds_specific_identifier(): void
    {
        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(0);

        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.zip');
        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.json');
        $this->assertFileDoesNotExist($this->fakePluginActivePath.'/vendor-bundle.zip');
    }

    /**
     * 플러그인 번들 커맨드가 식별자와 --all 없이 실행되면 안내 후 실패 종료하는지 검증한다.
     *
     * @scenario command=plugin_bundle
     *
     * @effects vendor_command_requires_identifier_or_all_flag
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_bundle_requires_identifier_or_all_flag(): void
    {
        $this->artisan('plugin:vendor-bundle')
            ->expectsOutputToContain(__('plugins.commands.vendor_bundle.missing_identifier'))
            ->assertExitCode(1);
    }

    /**
     * 플러그인 빌드 전 --check 는 번들이 낡았다고 보고하고 실패 종료하는지 검증한다.
     *
     * @scenario command=plugin_bundle
     *
     * @effects vendor_bundle_check_reports_stale_until_built
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_bundle_check_reports_stale_when_not_built(): void
    {
        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
            '--check' => true,
        ])->assertExitCode(1);
    }

    /**
     * 플러그인도 --force 로 다시 빌드해 성공 종료하는지 검증한다.
     *
     * @scenario command=plugin_bundle
     *
     * @effects vendor_bundle_force_option_rebuilds_successfully
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_bundle_force_rebuilds(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        $this->artisan('plugin:vendor-bundle', [
            'identifier' => $this->fakePluginIdentifier,
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * 플러그인 정상 빌드 직후 무결성 검증이 통과하는지 검증한다.
     *
     * @scenario command=plugin_verify
     *
     * @effects vendor_verify_passes_after_build
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_verify_passes_after_successful_build(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        $this->artisan('plugin:vendor-verify', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(0);
    }

    /**
     * 플러그인 번들 zip 이 변조되면 무결성 검증이 실패하는지 검증한다.
     *
     * @scenario command=plugin_verify
     *
     * @effects vendor_verify_fails_on_tampered_zip
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_verify_fails_on_tampered_zip(): void
    {
        $this->artisan('plugin:vendor-bundle', ['identifier' => $this->fakePluginIdentifier])
            ->assertExitCode(0);

        file_put_contents($this->fakePluginBundledPath.'/vendor-bundle.zip', 'tampered content');

        $this->artisan('plugin:vendor-verify', [
            'identifier' => $this->fakePluginIdentifier,
        ])->assertExitCode(1);
    }

    /**
     * 플러그인 검증 커맨드가 식별자와 --all 없이 실행되면 안내 후 실패 종료하는지 검증한다.
     *
     * @scenario command=plugin_verify
     *
     * @effects vendor_command_requires_identifier_or_all_flag
     *
     * @return void 반환값 없음
     */
    public function test_plugin_vendor_verify_requires_identifier_or_all_flag(): void
    {
        $this->artisan('plugin:vendor-verify')
            ->expectsOutputToContain(__('plugins.commands.vendor_verify.missing_identifier'))
            ->assertExitCode(1);
    }

    // =========================================================================
    // 일괄 알리아스 (vendor-bundle:build-all / verify-all) — 모듈+플러그인 포함
    // =========================================================================

    /**
     * vendor-bundle:build-all 이 모듈과 플러그인 번들을 함께 만드는지 검증한다.
     *
     * @scenario command=bulk_alias
     *
     * @effects vendor_bulk_alias_builds_and_verifies_all_extensions
     *
     * @return void 반환값 없음
     */
    public function test_bulk_alias_build_all_builds_both_module_and_plugin_fakes(): void
    {
        // force 로 모든 _bundled 확장 빌드 (fake 모듈 + fake 플러그인 포함)
        $this->artisan('vendor-bundle:build-all', ['--force' => true])
            ->assertExitCode(0);

        $this->assertFileExists($this->fakeModuleBundledPath.'/vendor-bundle.zip');
        $this->assertFileExists($this->fakePluginBundledPath.'/vendor-bundle.zip');
    }

    /**
     * vendor-bundle:verify-all 이 모듈·플러그인 빌드 뒤 충돌 없이 끝나는지 검증한다.
     *
     * @scenario command=bulk_alias
     *
     * @effects vendor_bulk_alias_builds_and_verifies_all_extensions
     *
     * @return void 반환값 없음
     */
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
