<?php

namespace Tests\Feature\Console;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Custom\CustomUpdatePreflight;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * core:update 의 custom 자리 사전 검사 Feature 테스트 (#792 WP-K1·WP-K3, D26)
 *
 * 배포본이 custom 예약 자리에 파일을 싣거나 개발자 패키지(custom/composer.lock)가 새 코어 패키지와 맞지 않으면
 * 유지보수 모드·백업·파일 적용 전에 멈추고, 그 결과를 관리자 확인 창용 기록으로 남기는지 잠근다.
 * 격리 base·storage 에서 실행하고 CoreUpdateService 는 목이다(유지보수 진입을 호출 여부로 판정).
 */
class CoreUpdateCustomPreflightTest extends TestCase
{
    private const CONFIRM = '코어를 업데이트하시겠습니까? (yes/no) [no]';

    private string $root;

    private string $release;

    private string $fakeBase;

    private string $originalBasePath;

    private string $originalStoragePath;

    /**
     * 픽스처 배포본·격리 base·storage 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_custom_preflight_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        $this->fakeBase = $this->root.DIRECTORY_SEPARATOR.'base';
        File::ensureDirectoryExists($this->fakeBase);
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');

        $this->writeFixture($this->release, 'composer.json', '{"name":"test/g7"}');
        $this->writeFixture($this->release, 'app/.gitkeep', '');
        $this->writeFixture($this->release, 'config/app.php', "<?php return ['version' => env('APP_VERSION', '7.2.0')];");
        $this->writeFixture($this->release, 'composer.lock', (string) json_encode(['packages' => [
            ['name' => 'symfony/console', 'version' => 'v7.1.0'],
            ['name' => 'psr/log', 'version' => '3.0.2'],
        ]]));

        $this->originalBasePath = base_path();
        $this->originalStoragePath = storage_path();
        app()->setBasePath($this->fakeBase);
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
        config(['app.update.reserved_paths' => ['routes/custom.php', 'app/Custom', 'resources/views/custom', 'custom']]);

        $this->app->instance(BundledSourcePlanner::class, new BundledSourcePlanner(...$this->plannerRepositories()));
    }

    /**
     * 경로·플래그를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * 배포본이 예약 자리에 파일을 실으면 유지보수 모드 전에 멈추고 기록을 남깁니다.
     *
     * @scenario case=command_blocks_reserved_release
     *
     * @effects update_not_started_before_maintenance, preflight_result_recorded_for_admin
     *
     * @return void 반환값 없음
     */
    public function test_release_with_reserved_files_is_blocked_before_maintenance(): void
    {
        $this->writeFixture($this->release, 'routes/custom.php', '<?php // shipped by mistake');
        $service = $this->mockService();
        $service->shouldNotReceive('validatePendingPath');
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('createBackup');
        $service->shouldNotReceive('applyUpdate');

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion(self::CONFIRM, 'yes')
            ->expectsOutputToContain('새 코어 7.2.0 업데이트를 시작하지 않았습니다')
            ->expectsOutputToContain('대상: routes/custom.php')
            ->assertExitCode(1);

        $record = CustomUpdatePreflight::lastRecord();
        $this->assertSame('7.2.0', $record['to_version'] ?? null);
        $this->assertSame(['routes/custom.php'], $record['reserved_files'] ?? null);
    }

    /**
     * --zip 배포본은 풀기 전에 막혀도 zip 안의 새 코어 버전으로 문구·기록을 남깁니다 — 현재 버전으로 남기면
     * 관리자 확인 창(같은 새 버전일 때만 표시)에 충돌 표가 뜨지 않는다.
     *
     * @scenario case=command_blocks_zip_with_new_version
     *
     * @effects update_not_started_before_maintenance, preflight_result_recorded_for_admin
     *
     * @return void 반환값 없음
     */
    public function test_zip_release_block_records_version_inside_zip(): void
    {
        $this->writeFixture($this->release, 'config/app.php', "<?php return ['version' => env('APP_VERSION', '7.9.0')];");
        $this->writeFixture($this->release, 'routes/custom.php', '<?php // shipped by mistake');
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'release.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (File::allFiles($this->release) as $file) {
            $zip->addFile($file->getPathname(), 'g7-7.9.0/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()));
        }
        $zip->close();

        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('extractZipToPending');

        $this->artisan('core:update', ['--zip' => $zipPath, '--bundled-sources' => 'none'])
            ->expectsQuestion(self::CONFIRM, 'yes')
            ->expectsOutputToContain('새 코어 7.9.0 업데이트를 시작하지 않았습니다')
            ->assertExitCode(1);

        $this->assertSame('7.9.0', CustomUpdatePreflight::lastRecord()['to_version'] ?? null);
    }

    /**
     * 개발자 패키지가 새 코어 패키지 제약과 맞지 않으면 vendor 모드와 무관하게 같은 판정으로 멈추고 충돌 표를 보여 줍니다.
     *
     * @scenario case=command_blocks_package_conflict
     *
     * @effects update_not_started_before_maintenance, conflict_table_printed, preflight_result_recorded_for_admin
     *
     * @param  string  $vendorMode  vendor 모드
     * @return void 반환값 없음
     */
    #[DataProvider('vendorModeProvider')]
    public function test_package_conflict_is_blocked_in_every_vendor_mode(string $vendorMode): void
    {
        $this->writeFixture($this->fakeBase, 'custom/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'acme/cli-kit', 'version' => '2.0.0', 'require' => ['symfony/console' => '^6.4']],
        ]]));
        $service = $this->mockService();
        $service->shouldNotReceive('validatePendingPath');
        $service->shouldNotReceive('enableMaintenanceMode');

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none', '--vendor-mode' => $vendorMode])
            ->expectsQuestion(self::CONFIRM, 'yes')
            ->expectsOutputToContain('업데이트를 시작하지 않았습니다')
            ->expectsOutputToContain('acme/cli-kit (2.0.0)')
            ->assertExitCode(1);

        $record = CustomUpdatePreflight::lastRecord();
        $this->assertCount(1, $record['conflicts'] ?? []);
        $this->assertSame('symfony/console', $record['conflicts'][0]['requires']);
    }

    /**
     * vendor 모드 2종을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 데이터셋 이름 => [모드]
     */
    public static function vendorModeProvider(): array
    {
        return [
            'composer' => ['composer'],
            'bundled' => ['bundled'],
        ];
    }

    /**
     * 충돌이 없으면 사전 검사를 통과해 다음 단계(_pending 검증)로 갑니다.
     *
     * @scenario case=command_passes_without_conflict
     *
     * @effects preflight_passes_without_conflict
     *
     * @return void 반환값 없음
     */
    public function test_compatible_custom_packages_pass_preflight(): void
    {
        $this->writeFixture($this->fakeBase, 'custom/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'twig/twig', 'version' => 'v3.14.0', 'require' => ['php' => '>=8.1']],
        ]]));
        $service = $this->mockService();
        $service->shouldReceive('validatePendingPath')->once()->andReturn([
            'valid' => false, 'errors' => ['stop'], 'path' => 'p', 'owner' => 'o', 'group' => 'g', 'permissions' => '0755',
        ]);

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion(self::CONFIRM, 'yes')
            ->doesntExpectOutputToContain('업데이트를 시작하지 않았습니다')
            ->assertExitCode(1);

        $this->assertNull(CustomUpdatePreflight::lastRecord());
    }

    /**
     * CoreUpdateService 목을 바인딩합니다.
     *
     * @return MockInterface 서비스 목
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }

    /**
     * 판정 서비스 생성자에 넣을 설치 상태 Repository 목 (아무 확장도 설치되지 않음).
     *
     * @return array<int, mixed> Repository 목
     */
    private function plannerRepositories(): array
    {
        $repo = function (string $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('getInstalledIdentifiers')->andReturn([]);

            return $mock;
        };
        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);

        return [
            $repo(ModuleRepositoryInterface::class),
            $repo(PluginRepositoryInterface::class),
            $repo(TemplateRepositoryInterface::class),
            $lp,
        ];
    }

    /**
     * 루트 아래 상대 경로에 파일을 씁니다.
     *
     * @param  string  $root  루트
     * @param  string  $rel  슬래시 구분 상대 경로
     * @param  string  $content  내용
     * @return void 반환값 없음
     */
    private function writeFixture(string $root, string $rel, string $content): void
    {
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $content);
    }
}
