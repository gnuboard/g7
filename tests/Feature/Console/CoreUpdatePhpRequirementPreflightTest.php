<?php

namespace Tests\Feature\Console;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Services\Core\PhpRequirementPreflight;
use App\Services\CoreUpdateService;
use App\Support\PhpExtensionProbe;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * core:update 의 PHP 확장 사전점검 Feature 테스트 (#151 D9)
 *
 * 새 배포본의 코어 SSoT(`app/Support/Requirements/php-extensions.json`)가 요구하는 필수 확장이 서버에 없으면
 * --source·--zip 은 유지보수 모드 전에, GitHub 다운로드는 받은 직후 받은 파일을 지우고 유지보수를 풀고 멈춘다.
 * 함께 갱신될 설치된 번들 확장의 새 composer.json require ext-* 미충족은 경고만 낸다.
 * CoreUpdateService 는 목으로 바꿔 「유지보수 전 실패 / 다운로드 직후」 지점까지만 실제 커맨드를 돌린다.
 */
class CoreUpdatePhpRequirementPreflightTest extends TestCase
{
    private string $root;

    private string $release;

    private string $originalStoragePath;

    /**
     * 픽스처 배포본·격리 storage 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_core_update_php_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        $this->writeRelease(withRequirements: true);

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
        config(['app.update.spawn_failure_mode' => 'fallback']);
    }

    /**
     * storage 경로와 업데이트 진행 플래그를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * --source 배포본의 코어 필수 확장이 없으면 유지보수 모드를 켜기 전에 실패로 끝납니다.
     *
     * @scenario source_kind=source, core_extension=missing
     *
     * @effects source_preflight_blocks_before_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_source_missing_core_extension_fails_before_maintenance(): void
    {
        $this->useProbe(missing: ['zip']);
        $service = $this->mockService();
        $service->shouldNotReceive('validatePendingPath');
        $service->shouldNotReceive('enableMaintenanceMode');

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain(__('settings.core_update.console.php_extension_core_missing', ['extensions' => 'zip']))
            ->assertExitCode(1);
    }

    /**
     * --zip 은 압축을 풀지 않고 요구 목록을 읽어 유지보수 모드 전에 실패로 끝납니다.
     *
     * @scenario source_kind=zip, core_extension=missing
     *
     * @effects zip_preflight_blocks_before_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_zip_missing_core_extension_fails_before_maintenance(): void
    {
        $this->useProbe(missing: ['pdo_mysql']);
        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('extractZipToPending');

        $this->artisan('core:update', ['--zip' => $this->zipRelease(), '--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain(__('settings.core_update.console.php_extension_core_missing', ['extensions' => 'pdo_mysql']))
            ->assertExitCode(1);
    }

    /**
     * GitHub 다운로드 경로는 받은 직후 판정해, 받은 배포본을 지우고 유지보수를 풀고 실패로 끝냅니다(백업 전).
     *
     * @scenario source_kind=github, core_extension=missing
     *
     * @effects github_preflight_cleans_pending_and_releases_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_github_missing_core_extension_aborts_cleanly_after_download(): void
    {
        $this->useProbe(missing: ['zip']);
        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldReceive('checkForUpdates')->andReturn(['latest_version' => '7.1.9', 'update_available' => true]);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('downloadUpdate')->once()->andReturn($this->release);
        $service->shouldReceive('cleanupPending')->once()->with($this->release);
        $service->shouldReceive('disableMaintenanceMode')->once();
        $service->shouldNotReceive('createBackup');

        $this->artisan('core:update', ['--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain(__('settings.core_update.console.php_extension_core_missing', ['extensions' => 'zip']))
            ->assertExitCode(1);
    }

    /**
     * 새 배포본에 요구 목록이 없으면(체계 도입 전 판) 코어 판정을 건너뛰고 다음 단계로 갑니다.
     *
     * @scenario source_kind=source, core_extension=list_absent
     *
     * @effects old_release_without_list_skips_core_check
     *
     * @return void 반환값 없음
     */
    public function test_release_without_requirement_list_skips_core_check(): void
    {
        File::delete($this->release.'/'.PhpRequirementPreflight::REQUIREMENTS_PATH);
        $this->useProbe(missing: ['zip']);
        $service = $this->mockService();
        // 사전점검을 지나 Step 2 까지 왔다는 증거 — 여기서 멈춘다
        $service->shouldReceive('validatePendingPath')->once()->andReturn([
            'valid' => false, 'errors' => ['stop'], 'path' => 'p', 'owner' => 'o', 'group' => 'g', 'permissions' => '0755',
        ]);

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->doesntExpectOutputToContain(__('settings.core_update.console.php_extension_core_missing', ['extensions' => 'zip']))
            ->assertExitCode(1);
    }

    /**
     * 함께 갱신될 설치된 번들 확장의 필수 PHP 확장 미충족은 경고만 내고 업데이트를 막지 않습니다.
     *
     * @scenario source_kind=source, core_extension=present
     *
     * @effects bundled_extension_requirement_only_warns
     *
     * @return void 반환값 없음
     */
    public function test_bundled_extension_requirement_only_warns(): void
    {
        $this->useProbe(missing: ['soap']);
        $this->app->instance(PhpRequirementPreflight::class, new PhpRequirementPreflight(
            $this->repository(ModuleRepositoryInterface::class, []),
            $this->repository(PluginRepositoryInterface::class, ['g7test-soap_plugin']),
            $this->app->make(PhpExtensionProbe::class),
        ));
        $service = $this->mockService();
        $service->shouldReceive('validatePendingPath')->once()->andReturn([
            'valid' => false, 'errors' => ['stop'], 'path' => 'p', 'owner' => 'o', 'group' => 'g', 'permissions' => '0755',
        ]);

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain(__('settings.core_update.console.php_extension_extension_warning', ['extension' => 'plugin:g7test-soap_plugin', 'extensions' => 'soap']))
            ->assertExitCode(1);
    }

    /**
     * 확장 로드 여부를 주입합니다. (지정한 확장만 없는 것으로 본다)
     *
     * @param  list<string>  $missing  없는 것으로 볼 확장
     * @return void 반환값 없음
     */
    private function useProbe(array $missing): void
    {
        $this->app->instance(PhpExtensionProbe::class, new class($missing) extends PhpExtensionProbe
        {
            /**
             * 없는 것으로 볼 확장 목록을 받습니다.
             *
             * @param  list<string>  $absent  없는 것으로 볼 확장
             */
            public function __construct(private array $absent) {}

            /**
             * 주입한 부재 목록으로 로드 여부를 답합니다.
             *
             * @param  string  $extension  확장 이름
             * @return bool 로드돼 있으면 true
             */
            public function loaded(string $extension): bool
            {
                return ! in_array($extension, $this->absent, true);
            }
        });
    }

    /**
     * 설치 식별자 목록만 답하는 Repository 목을 만듭니다.
     *
     * @param  class-string  $class  Repository 인터페이스
     * @param  list<string>  $identifiers  설치된 식별자
     * @return MockInterface Repository 목
     */
    private function repository(string $class, array $identifiers): MockInterface
    {
        $mock = Mockery::mock($class);
        $mock->shouldReceive('getInstalledIdentifiers')->andReturn($identifiers);

        return $mock;
    }

    /**
     * CoreUpdateService 목을 컨테이너에 등록합니다.
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log')->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }

    /**
     * core:update 진행 확인 질문 문자열을 반환합니다.
     *
     * @return string 질문 문자열 (yes/no 기본값 표기 포함)
     */
    private function confirmQuestion(): string
    {
        return __('settings.core_update.console.confirm_update').' (yes/no) [no]';
    }

    /**
     * 필수 확장 목록(선택)과 soap 을 요구하는 번들 플러그인을 가진 배포본을 씁니다.
     *
     * @param  bool  $withRequirements  코어 요구 목록 JSON 포함 여부
     * @return void 반환값 없음
     */
    private function writeRelease(bool $withRequirements): void
    {
        $put = function (string $rel, string $content): void {
            $file = $this->release.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $content);
        };

        $put('composer.json', '{"name":"test/g7"}');
        $put('config/app.php', "<?php return ['version' => env('APP_VERSION', '7.1.9')];");
        if ($withRequirements) {
            $put(PhpRequirementPreflight::REQUIREMENTS_PATH, (string) json_encode([
                'required' => ['json', 'pdo_mysql', 'zip'],
                'optional' => ['gd'],
                'symbols' => [],
            ]));
        }
        $put('plugins/_bundled/g7test-soap_plugin/plugin.json', '{"identifier":"g7test-soap_plugin","version":"1.0.0"}');
        $put('plugins/_bundled/g7test-soap_plugin/composer.json', '{"require":{"php":"^8.2","ext-soap":"*"}}');
    }

    /**
     * 배포본을 zip 으로 묶습니다.
     *
     * @return string zip 경로
     */
    private function zipRelease(): string
    {
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'release.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->release, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->release) + 1));
            $zip->addFile($file->getPathname(), $relative);
        }
        $zip->close();

        return $zipPath;
    }
}
