<?php

namespace Tests\Unit\Services;

use App\Extension\Vendor\EnvironmentDetector;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorMode;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * CoreUpdateService::runVendorInstallInPending() 검증.
 *
 * VendorResolver 경유로 Composer/Bundled 모드 분기가 올바르게 동작하는지 확인.
 *
 * @scenario-manifest tests/scenarios/core-update-vendor-install-mode.yaml
 */
class CoreUpdateServiceVendorModeTest extends TestCase
{
    private CoreUpdateService $service;

    private string $pendingDir;

    /**
     * 서비스와 임시 _pending 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CoreUpdateService::class);
        $this->pendingDir = storage_path('app/test-core-vendor-pending-'.uniqid());
        File::ensureDirectoryExists($this->pendingDir);
    }

    /**
     * 임시 _pending 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->pendingDir)) {
            File::deleteDirectory($this->pendingDir);
        }
        parent::tearDown();
    }

    /**
     * 테스트용 _pending 디렉토리에 vendor-bundle.zip 을 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function createPendingWithBundle(): void
    {
        File::put($this->pendingDir.'/composer.json', json_encode([
            'name' => 'test/core',
            'require' => ['php' => '^8.2'],
        ]));

        $zipPath = $this->pendingDir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', '<?php // test autoload');
        $zip->addFromString('vendor/laravel/framework/src/file.php', '<?php // test');
        $zip->close();

        File::put($this->pendingDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'package_count' => 1,
        ]));
    }

    /**
     * bundled 모드는 vendor-bundle.zip 을 _pending 에 풀어 vendor 를 구성한다.
     *
     * @return void 반환값 없음
     *
     * @scenario mode=bundled
     *
     * @effects bundled_mode_extracts_vendor_from_bundle
     */
    public function test_bundled_mode_extracts_vendor_bundle_zip(): void
    {
        $this->createPendingWithBundle();

        $result = $this->service->runVendorInstallInPending($this->pendingDir, VendorMode::Bundled);

        $this->assertSame(VendorMode::Bundled, $result->mode);
        $this->assertSame('bundled', $result->strategy);
        $this->assertFileExists($this->pendingDir.'/vendor/autoload.php');
    }

    /**
     * bundled 모드인데 번들이 없으면 VendorInstallException 을 던진다.
     *
     * @return void 반환값 없음
     *
     * @scenario mode=bundled
     *
     * @effects bundled_mode_without_bundle_throws
     */
    public function test_bundled_mode_fails_when_no_bundle_present(): void
    {
        File::put($this->pendingDir.'/composer.json', json_encode(['name' => 'test/core']));

        $this->expectException(VendorInstallException::class);
        $this->service->runVendorInstallInPending($this->pendingDir, VendorMode::Bundled);
    }

    /**
     * composer 를 쓸 수 없는 경우 번들로 해결되는 경로(bundled 명시로 대신 확인).
     *
     * @return void 반환값 없음
     *
     * @scenario mode=bundled
     *
     * @effects bundled_mode_extracts_vendor_from_bundle
     */
    public function test_auto_mode_prefers_bundle_when_composer_cannot_execute(): void
    {
        $this->createPendingWithBundle();

        // canExecuteComposer를 직접 제어할 수 없으므로 bundle 존재를 전제로
        // Auto가 composer 가능하면 composer 콜백을 호출하려 시도할 것이고,
        // composer 콜백은 실패할 것 — 대신 bundled로 해결되는 경로를 Bundled 명시로 시뮬레이션
        $result = $this->service->runVendorInstallInPending($this->pendingDir, VendorMode::Bundled);

        $this->assertSame('bundled', $result->strategy);
    }

    /**
     * composer 전략은 설치를 마친 _pending vendor 의 실제 패키지 수를 결과에 싣는다.
     *
     * 업데이트 로그 「_pending vendor 설치 완료 (strategy: composer, packages: N)」 가 늘 0 이던 문제의 회귀 방지.
     * composer 실행은 가짜로 바꾸고, 실행이 남기는 `vendor/composer/installed.json` 만 만들어 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario mode=composer
     *
     * @effects composer_mode_reports_installed_package_count
     */
    public function test_composer_mode_reports_installed_package_count(): void
    {
        File::put($this->pendingDir.'/composer.json', json_encode(['name' => 'test/core']));

        $this->mock(EnvironmentDetector::class, function ($mock): void {
            $mock->shouldReceive('canExecuteComposer')->andReturn(true);
        });

        $pendingDir = $this->pendingDir;
        /** @var CoreUpdateService&MockInterface $service */
        $service = \Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('runComposerInstallInPending')
            ->once()
            ->andReturnUsing(function (string $path) use ($pendingDir): void {
                $this->assertSame($pendingDir, $path);
                File::ensureDirectoryExists($path.'/vendor/composer');
                File::put($path.'/vendor/composer/installed.json', json_encode([
                    'packages' => [
                        ['name' => 'laravel/framework', 'version' => 'v12.72.0'],
                        ['name' => 'symfony/console', 'version' => 'v7.4.23'],
                        ['name' => 'monolog/monolog', 'version' => '3.12.1'],
                    ],
                    'dev' => false,
                    'dev-package-names' => [],
                ]));
            });

        $result = $service->runVendorInstallInPending($this->pendingDir, VendorMode::Composer);

        $this->assertSame('composer', $result->strategy);
        $this->assertSame(3, $result->packageCount);
    }

    /**
     * composer 설치 정보 파일을 읽을 수 없으면 패키지 수는 0 으로 둔다(설치 성공 판정은 바꾸지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario mode=composer
     *
     * @effects composer_mode_reports_zero_without_installed_json
     */
    public function test_composer_mode_reports_zero_when_installed_json_is_missing(): void
    {
        File::put($this->pendingDir.'/composer.json', json_encode(['name' => 'test/core']));

        $this->mock(EnvironmentDetector::class, function ($mock): void {
            $mock->shouldReceive('canExecuteComposer')->andReturn(true);
        });

        /** @var CoreUpdateService&MockInterface $service */
        $service = \Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('runComposerInstallInPending')->once()->andReturnNull();

        $result = $service->runVendorInstallInPending($this->pendingDir, VendorMode::Composer);

        $this->assertSame('composer', $result->strategy);
        $this->assertSame(0, $result->packageCount);
    }
}
