<?php

namespace Tests\Feature\Console;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Extension\Integrity\PackageVerification;
use App\Services\Core\PhpRequirementPreflight;
use App\Services\CoreUpdateService;
use App\Support\PhpExtensionProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * 점검 모드 우회 비밀값이 기록 파일에 남지 않는지 잰다.
 *
 * 비밀값이 있으면 점검 중인 사이트에 그대로 들어갈 수 있다. 기록 파일(`storage/logs/*`)과
 * 웹 실행 기록(`storage/app/core-update/web-run.json`)은 저장소 폴더 안에 있어, 보호 규칙이
 * 빠진 웹 서버에서는 주소만으로 열린다. 그래서 세 통로 — 업데이트 로그 채널, 명령줄 업데이트의
 * 기록 파일, 웹 실행 기록 — 어디에도 값이 실리지 않아야 한다. 명령줄 실패 시 터미널 안내는
 * 운영자가 그 값으로 사이트에 들어가야 하므로 남긴다(파일이 아니다).
 */
class CoreUpdateMaintenanceSecretTest extends TestCase
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
        $this->root = storage_path('test_core_update_secret_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->release.DIRECTORY_SEPARATOR.'config');
        File::put($this->release.DIRECTORY_SEPARATOR.'composer.json', '{"name":"test/g7"}');
        File::put($this->release.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', "<?php return ['version' => env('APP_VERSION', '7.9.9')];");
        File::ensureDirectoryExists(dirname($this->release.DIRECTORY_SEPARATOR.PhpRequirementPreflight::REQUIREMENTS_PATH));
        File::put($this->release.DIRECTORY_SEPARATOR.PhpRequirementPreflight::REQUIREMENTS_PATH, (string) json_encode([
            'required' => ['json', 'zip'],
            'optional' => [],
            'symbols' => [],
        ]));

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
     * 점검 모드를 켤 때 업데이트 로그 채널에 비밀값이 실리지 않는다.
     *
     * @scenario request_time_limit=short, secret_channel=upgrade_log
     *
     * @effects the_bypass_secret_never_reaches_a_log_file
     *
     * @return void 반환값 없음
     */
    public function test_enabling_maintenance_mode_does_not_log_the_bypass_secret(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(static fn (string $command, array $parameters = []): bool => $command === 'down' && isset($parameters['--secret']))
            ->andReturn(0);

        $captured = [];
        $channel = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $channel->shouldReceive('info')->andReturnUsing(function (...$arguments) use (&$captured): void {
            $captured[] = $arguments;
        });
        Log::partialMock()->shouldReceive('channel')->with('upgrade')->andReturn($channel);

        $secret = (new CoreUpdateService)->enableMaintenanceMode();

        $this->assertNotSame('', $secret, '우회 쿠키를 만들 값은 여전히 돌려줘야 한다');
        $this->assertNotEmpty($captured, '점검 모드를 켰다는 사실은 기록에 남아야 한다');
        $this->assertStringNotContainsString(
            $secret,
            (string) json_encode($captured, JSON_UNESCAPED_UNICODE),
            '로그 파일이 웹으로 열리는 서버에서는 그 값으로 점검 중인 사이트에 들어갈 수 있다',
        );
    }

    /**
     * `core:update` 가 터미널에 안내하는 우회 주소가 실제로 점검 중인 사이트를 연다 — 비밀 경로 302 + 쿠키, 그 쿠키로 200.
     *
     * 점검 미들웨어를 바꾼 뒤로 비밀 경로가 쿠키를 발급하지 않아, 안내받은 주소로 들어가도 503 이었다(#888).
     *
     * @scenario request_time_limit=unlimited, secret_channel=cli_report
     *
     * @effects the_announced_bypass_address_opens_the_site
     *
     * @return void 반환값 없음
     */
    public function test_the_announced_bypass_address_lets_the_operator_in(): void
    {
        File::ensureDirectoryExists(storage_path('framework'));
        $service = new CoreUpdateService;
        $secret = $service->enableMaintenanceMode();

        try {
            $this->assertTrue(app()->isDownForMaintenance());
            $this->get('/')->assertStatus(503);

            // 터미널 안내(관리자 접근)가 보여 주는 값이 곧 비밀 경로다
            $announced = (string) __('settings.core_update.console.admin_access', ['secret' => $secret]);
            $this->assertStringContainsString($secret, $announced);

            $response = $this->get('/'.$secret);
            $response->assertRedirect('/');
            $response->assertCookie('laravel_maintenance');

            $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance');
            $this->assertNotNull($cookie);
            $this->withUnencryptedCookie('laravel_maintenance', (string) $cookie->getValue())->get('/')->assertStatus(200);
        } finally {
            $service->disableMaintenanceMode();
        }

        $this->assertFalse(app()->isDownForMaintenance());
    }

    /**
     * 명령줄 업데이트의 기록 파일에 비밀값이 실리지 않는다 (실패 시 터미널 안내만 값을 보여 준다).
     *
     * @scenario request_time_limit=unlimited, secret_channel=cli_report
     *
     * @effects the_bypass_secret_never_reaches_a_log_file
     *
     * @return void 반환값 없음
     */
    public function test_the_command_line_update_report_does_not_contain_the_bypass_secret(): void
    {
        $secret = 'bypass-'.bin2hex(random_bytes(8));
        $this->useProbe(missing: ['zip']);

        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldReceive('checkForUpdates')->andReturn(['latest_version' => '7.9.9', 'update_available' => true]);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn($secret);
        $service->shouldReceive('downloadUpdate')->once()->andReturn($this->release);
        $service->shouldReceive('cleanupPending')->once()->with($this->release);
        $service->shouldReceive('disableMaintenanceMode')->once();

        $this->artisan('core:update', ['--bundled-sources' => 'none'])
            ->expectsQuestion(__('settings.core_update.console.confirm_update').' (yes/no) [no]', 'yes')
            ->assertExitCode(1);

        $reports = glob(storage_path('logs'.DIRECTORY_SEPARATOR.CoreUpdateService::UPDATE_REPORT_PREFIX.'*.log')) ?: [];
        $this->assertNotEmpty($reports, '업데이트 기록 파일이 만들어지지 않았다');

        $content = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $reports));
        $this->assertStringContainsString('유지보수 모드 활성화', $content, '점검 모드를 켰다는 사실은 기록에 남아야 한다');
        $this->assertStringNotContainsString($secret, $content);
    }

    /**
     * 웹 실행 기록에 비밀값이 실리지 않는다 (우회 쿠키는 점검 모드 파일에서 읽는다).
     *
     * @scenario request_time_limit=short, secret_channel=web_state, run_state=running
     *
     * @effects the_bypass_secret_never_reaches_a_log_file
     *
     * @return void 반환값 없음
     */
    public function test_the_web_run_state_does_not_keep_the_bypass_secret(): void
    {
        $secret = 'bypass-'.bin2hex(random_bytes(8));

        $service = $this->mockService();
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn($secret);

        $state = new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Maintenance->value, 'completed_steps' => ['preflight']]);

        $orchestrator = new CoreUpdateOrchestrator(
            $service,
            $state,
            new CoreUpdatePreflight($service),
            app(CommandRunnerResolver::class),
            new CoreUpdateTimeBudget(null, 300),
        );

        $orchestrator->advance();

        $this->assertTrue((new CoreUpdateRunState($state->path()))->context('maintenance_enabled'));
        $this->assertStringNotContainsString(
            $secret,
            (string) file_get_contents($state->path()),
            '실행 기록은 저장소 폴더 안에 있어 보호 규칙이 빠진 서버에서 그대로 열린다',
        );
    }

    /**
     * 없는 것으로 볼 PHP 확장을 주입합니다.
     *
     * @param  list<string>  $missing  없는 것으로 볼 확장
     * @return void 반환값 없음
     */
    private function useProbe(array $missing): void
    {
        $this->app->instance(PhpExtensionProbe::class, new class($missing) extends PhpExtensionProbe
        {
            /**
             * 없는 것으로 볼 PHP 확장 목록을 받아 탐지기 대역을 만듭니다.
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
     * CoreUpdateService 목을 컨테이너에 등록합니다.
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('acquireRunLock')->andReturn(true)->byDefault();
        $service->shouldReceive('releaseRunLock')->byDefault();
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log')->byDefault();
        // 패키지 서명 검증(7.2.0)은 이 시험의 관심사가 아니다 — 통과(미서명 확인됨)로 둔다
        $service->shouldReceive('preflightReleaseIntegrity')->byDefault();
        $service->shouldReceive('verifyCoreZipIntegrity')->andReturn(PackageVerification::unsigned())->byDefault();
        $service->shouldReceive('confirmCoreIntegrity')->andReturnUsing(fn ($verification) => $verification)->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }
}
