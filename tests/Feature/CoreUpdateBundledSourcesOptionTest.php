<?php

namespace Tests\Feature;

use App\Console\Commands\Core\Concerns\BundledSourcePlacementPrompt;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Services\Core\BundledPlacementPlan;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * core:update 의 공식 번들 배치 질문·옵션·기록 Feature 테스트 (공개 #146)
 *
 * 풀 체인 성공 경로는 새 프로세스 훅 캐시·config/route 캐시 재생성을 실제로 돌리므로 테스트에서 실행하지
 * 않는다. CoreUpdateService 를 목으로 바꿔 실제 커맨드를 「유지보수 전 실패 / GitHub 다운로드 직후 /
 * 파일 적용 뒤 롤백」 지점까지 돌리고, 완료 요약 4구분은 트레이트를 직접 검증한다.
 */
class CoreUpdateBundledSourcesOptionTest extends TestCase
{
    private string $root;

    private string $release;

    private string $originalStoragePath;

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
     * 픽스처 배포본·격리 storage·판정 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_core_update_bundled_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        $this->writeRelease();

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');

        $this->app->instance(BundledSourcePlanner::class, $this->planner());
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
     * --source 는 유지보수 모드를 켜기 전에 묻고, [배치하지 않음] 이 기본 선택지입니다.
     *
     * @scenario source_kind=source,selection=none
     *
     * @effects source_question_before_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_source_asks_before_maintenance(): void
    {
        $service = $this->serviceStoppingAtStep2();
        $service->shouldNotReceive('enableMaintenanceMode');

        $this->artisan('core:update', ['--source' => $this->release])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('이 프로젝트에 없는 공식 번들 확장 3개가 새 배포본에 있습니다')
            ->expectsOutputToContain('[플러그인] g7test-bundled_probe — 프로브 (1.0.0)')
            ->expectsOutputToContain('[플러그인] g7test-bundled_hidden — g7test-bundled_hidden (1.0.0) (샘플)')
            ->expectsChoice('어떻게 할까요?', '배치하지 않음 (기본)', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);
    }

    /**
     * [골라서 배치] 는 다중 선택을 받고, 고른 번들의 의존 번들을 함께 배치한다고 알립니다.
     *
     * @scenario selection=pick,dependency=direct
     *
     * @effects pick_adds_dependencies_with_notice
     *
     * @return void 반환값 없음
     */
    public function test_pick_adds_dependencies(): void
    {
        $this->serviceStoppingAtStep2();

        $this->artisan('core:update', ['--source' => $this->release])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsChoice('어떻게 할까요?', '골라서 배치', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->expectsChoice(
                '배치할 번들을 고르세요 (쉼표로 여러 개)',
                ['plugin:g7test-bundled_probe'],
                ['plugin:g7test-bundled_probe', 'plugin:g7test-bundled_hidden', 'module:g7test-bundled_probe_module'],
            )
            ->expectsOutputToContain('의존성으로 함께 배치: module:g7test-bundled_probe_module')
            ->assertExitCode(1);
    }

    /**
     * --force(비대화형)는 묻지 않고, 옵션이 없으면 배치하지 않습니다.
     *
     * @scenario selection=noninteractive_default
     *
     * @effects noninteractive_defaults_to_none
     *
     * @return void 반환값 없음
     */
    public function test_noninteractive_defaults_to_none(): void
    {
        $this->serviceStoppingAtStep2();

        $this->artisan('core:update', ['--source' => $this->release, '--force' => true])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('--bundled-sources 를 지정하지 않아, 이 프로젝트에 없는 공식 번들 3개를 배치하지 않습니다')
            ->doesntExpectOutputToContain('어떻게 할까요?')
            ->assertExitCode(1);
    }

    /**
     * --bundled-sources=all·식별자 목록은 묻지 않고 그대로 적용합니다.
     *
     * @scenario selection=option
     *
     * @effects option_applies_without_question
     *
     * @param  string  $value  옵션 값
     * @param  string  $expected  적용 결과 문구
     * @return void 반환값 없음
     */
    #[DataProvider('optionProvider')]
    public function test_option_applies_without_question(string $value, string $expected): void
    {
        $this->serviceStoppingAtStep2();

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => $value])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain($expected)
            ->doesntExpectOutputToContain('어떻게 할까요?')
            ->assertExitCode(1);
    }

    /**
     * 옵션 값과 기대 문구를 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string}> 데이터셋
     */
    public static function optionProvider(): array
    {
        return [
            'all' => ['all', '미배치 0개, 선택 배치 3개'],
            'ids' => ['plugin:g7test-bundled_probe', '미배치 1개, 선택 배치 1개'],
            'none' => ['none', '미배치 4개, 선택 배치 0개'],
        ];
    }

    /**
     * 배포본에 없는 식별자·알 수 없는 유형은 유지보수 모드 전에 실패합니다.
     *
     * @scenario selection=invalid
     *
     * @effects invalid_option_fails_before_maintenance
     *
     * @param  string  $value  옵션 값
     * @param  bool  $confirmFirst  시작 확인 뒤에 실패하는지 (유형 오류는 확인 전)
     * @return void 반환값 없음
     */
    #[DataProvider('invalidOptionProvider')]
    public function test_invalid_option_fails_before_maintenance(string $value, bool $confirmFirst): void
    {
        $service = $this->mockService();
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('validatePendingPath');

        $pending = $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => $value]);
        if ($confirmFirst) {
            $pending->expectsQuestion($this->confirmQuestion(), 'yes');
        }
        $pending->assertExitCode(1);
    }

    /**
     * 잘못된 옵션 값을 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: bool}> 데이터셋
     */
    public static function invalidOptionProvider(): array
    {
        return [
            'unknown_id' => ['acme-nothing', true],
            'unknown_type' => ['widget:g7test-bundled_probe', false],
        ];
    }

    /**
     * 미배치 후보가 없으면 묻지 않습니다(--local 은 현재 디스크가 배포본이라 항상 공집합).
     *
     * @scenario selection=empty_candidates
     *
     * @effects no_question_when_nothing_unplaced
     *
     * @return void 반환값 없음
     */
    public function test_no_question_when_nothing_unplaced(): void
    {
        $this->serviceStoppingAtStep2();
        // 디스크에 이미 있는 번들만 있는 배포본
        File::deleteDirectory($this->release.DIRECTORY_SEPARATOR.'plugins');
        File::deleteDirectory($this->release.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'g7test-bundled_probe_module');

        $this->artisan('core:update', ['--source' => $this->release])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->doesntExpectOutputToContain('어떻게 할까요?')
            ->assertExitCode(1);
    }

    /**
     * GitHub 경로는 다운로드 직후(유지보수 중) 「사이트가 유지보수 중입니다」 경고 뒤에 묻습니다.
     *
     * @scenario source_kind=github
     *
     * @effects github_question_after_download_with_maintenance_warning
     *
     * @return void 반환값 없음
     */
    public function test_github_asks_after_download_with_warning(): void
    {
        $service = $this->mockService();
        $this->stubGithubUntilDownload($service);
        $service->shouldReceive('createBackup')->once()->andThrow(new \RuntimeException('stop after question'));
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');

        $this->artisan('core:update')
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('사이트가 유지보수 중입니다 — 빠르게 답해 주세요.')
            ->expectsChoice('어떻게 할까요?', '배치하지 않음 (기본)', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);
    }

    /**
     * GitHub 경로에서 배포본에 없는 식별자를 주면 파일을 건드리기 전에 받은 배포본을 지우고 유지보수를 풀고 끝냅니다.
     *
     * @scenario source_kind=github,selection=invalid
     *
     * @effects github_invalid_option_aborts_cleanly
     *
     * @return void 반환값 없음
     */
    public function test_github_invalid_option_aborts_cleanly(): void
    {
        $service = $this->mockService();
        $this->stubGithubUntilDownload($service);
        $service->shouldNotReceive('createBackup');
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('disableMaintenanceMode')->once();

        $this->artisan('core:update', ['--bundled-sources' => 'acme-nothing'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('공식 번들 선택이 잘못되어 업데이트를 중단했습니다')
            ->assertExitCode(1);
    }

    /**
     * 대화형 [모두 배치] 는 후보 전부와 그 언어팩을 배치해 Step 7 에 건너뛸 경로가 남지 않습니다.
     *
     * @scenario source_kind=source,selection=all
     *
     * @effects interactive_all_places_every_candidate
     *
     * @return void 반환값 없음
     */
    public function test_interactive_all_places_every_candidate(): void
    {
        $captured = $this->serviceCapturingApplyThenRollback();

        $this->artisan('core:update', ['--source' => $this->release])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsChoice('어떻게 할까요?', '모두 배치', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);

        $this->assertSame([], $captured->skip, '모두 배치면 건너뛸 번들 경로가 없어야 한다');
        $this->assertSame([], $captured->record['unplaced']);
        $this->assertEqualsCanonicalizing(
            ['plugin:g7test-bundled_probe', 'plugin:g7test-bundled_hidden', 'module:g7test-bundled_probe_module'],
            array_map(fn (array $row) => $row['type'].':'.$row['identifier'], $captured->record['placed_by_selection']),
        );

        // 업그레이드 로그에 배치·미배치 수와 사유가 남는다
        $logs = File::glob(storage_path('logs').DIRECTORY_SEPARATOR.'core_update_*.log');
        $this->assertCount(1, $logs);
        $this->assertMatchesRegularExpression(
            '/공식 번들 배치 판정\(interactive_all\): 배치 \d+ \([^)]*selected=3[^)]*\), 미배치 0/u',
            File::get($logs[0]),
        );
    }

    /**
     * --zip 은 압축을 풀지 않고 목록만 읽어 유지보수 모드를 켜기 전에 묻습니다(최상위 래퍼 폴더 포함).
     *
     * @scenario source_kind=zip,selection=none
     *
     * @effects zip_question_before_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_zip_asks_before_maintenance(): void
    {
        $service = $this->serviceStoppingAtStep2();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('extractZipToPending');

        $this->artisan('core:update', ['--zip' => $this->zipRelease()])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('이 프로젝트에 없는 공식 번들 확장 3개가 새 배포본에 있습니다')
            ->doesntExpectOutputToContain('사이트가 유지보수 중입니다')
            ->expectsChoice('어떻게 할까요?', '배치하지 않음 (기본)', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);
    }

    /**
     * zip 목록을 읽지 못하면(ZipArchive 부재) 추출 직후 유지보수 경고와 함께 묻습니다.
     *
     * @scenario source_kind=zip,selection=none
     *
     * @effects zip_listing_unreadable_defers_question_after_extract
     *
     * @return void 반환값 없음
     */
    public function test_zip_listing_unreadable_defers_question_after_extract(): void
    {
        $planner = $this->partialPlanner();
        $planner->shouldReceive('readInventoryFromZip')->once()->andReturnNull();
        $this->app->instance(BundledSourcePlanner::class, $planner);

        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['unzip']]);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('extractZipToPending')->once()->andReturn($this->release);
        $service->shouldReceive('createBackup')->once()->andThrow(new \RuntimeException('stop after question'));
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');

        $this->artisan('core:update', ['--zip' => $this->zipRelease()])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->expectsOutputToContain('ZIP 목록을 읽을 수 없어 공식 번들 배치 여부는 압축을 푼 뒤에 묻습니다.')
            ->expectsOutputToContain('사이트가 유지보수 중입니다 — 빠르게 답해 주세요.')
            ->expectsChoice('어떻게 할까요?', '배치하지 않음 (기본)', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);
    }

    /**
     * --local 은 현재 코드베이스를 배포본으로 읽어 유지보수 모드를 켜기 전에 판정합니다.
     *
     * @scenario source_kind=local,selection=none
     *
     * @effects local_question_before_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_local_asks_before_maintenance(): void
    {
        $planner = $this->partialPlanner();
        $planner->shouldReceive('readInventoryFromDirectory')->once()->with(base_path())
            ->andReturn($this->planner()->readInventoryFromDirectory($this->release));
        $this->app->instance(BundledSourcePlanner::class, $planner);

        $service = $this->serviceStoppingAtStep2();
        $service->shouldNotReceive('enableMaintenanceMode');

        $this->artisan('core:update', ['--local' => true])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->doesntExpectOutputToContain('사이트가 유지보수 중입니다')
            ->expectsChoice('어떻게 할까요?', '배치하지 않음 (기본)', ['배치하지 않음 (기본)', '모두 배치', '골라서 배치'])
            ->assertExitCode(1);
    }

    /**
     * Step 6.5·7 에 미배치 경로가 전달되고, 기록 파일이 쓰였다가 롤백 시 이전 내용으로 되돌아갑니다.
     *
     * @scenario update_mode=incremental,selection=none
     *
     * @effects skip_paths_reach_apply_and_record_written, record_restored_on_rollback
     *
     * @return void 반환값 없음
     */
    public function test_skip_paths_reach_apply_and_record_restored_on_rollback(): void
    {
        $planner = $this->app->make(BundledSourcePlanner::class);
        File::ensureDirectoryExists(dirname($planner->recordPath()));
        File::put($planner->recordPath(), '{"previous":true}');

        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backup);

        $captured = ['skip' => null, 'record' => null];
        $service = $this->mockService();
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('validatePendingUpdate')->once();
        $service->shouldReceive('copySourceToPending')->once()->andReturn($this->release);
        $service->shouldReceive('createBackup')->once()->andReturn($backup);
        $service->shouldReceive('snapshotOwnership')->andReturn([]);
        $service->shouldReceive('resolveStagingRoot')->andReturn($this->release);
        $service->shouldReceive('snapshotOwnershipDetailed')->andReturn([]);
        $service->shouldReceive('isComposerUnchangedForCore')->andReturn(true);
        $service->shouldReceive('applyUpdate')->once()->andReturnUsing(
            function ($path, $progress, $prune, $applyList, $skipPaths) use (&$captured): void {
                $captured['skip'] = $skipPaths;
            },
        );
        $service->shouldReceive('runMigrations')->once()->andReturnUsing(function () use (&$captured, $planner): void {
            $captured['record'] = json_decode((string) file_get_contents($planner->recordPath()), true);
            throw new \RuntimeException('migration failed');
        });
        $service->shouldReceive('restoreFromBackup')->once()->andReturn([]);
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');
        $service->shouldReceive('disableMaintenanceMode')->once();

        $this->artisan('core:update', ['--source' => $this->release, '--bundled-sources' => 'none'])
            ->expectsQuestion($this->confirmQuestion(), 'yes')
            ->assertExitCode(1);

        $this->assertSame([
            'lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja',
            'modules/_bundled/g7test-bundled_probe_module',
            'plugins/_bundled/g7test-bundled_hidden',
            'plugins/_bundled/g7test-bundled_probe',
        ], $captured['skip']);
        $this->assertSame('core:update', $captured['record']['source']);
        $this->assertSame('7.1.2', $captured['record']['core_version']);
        $this->assertCount(4, $captured['record']['unplaced']);
        $this->assertSame('{"previous":true}', File::get($planner->recordPath()), '롤백 시 이전 기록으로 되돌아가야 한다');
    }

    /**
     * 완료 요약은 기존 설치 확장·의존 배치·선택 배치·미배치(배치 명령 안내 포함) 4구분과 디스크 갱신 수를 출력합니다.
     *
     * @effects summary_prints_four_sections
     *
     * @return void 반환값 없음
     */
    public function test_summary_prints_four_sections(): void
    {
        $planner = $this->app->make(BundledSourcePlanner::class);
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release), ['plugin:g7test-bundled_probe']);
        $this->registerSummaryCommand($plan);

        $this->artisan('test:bundled-summary')
            ->expectsOutputToContain('── 공식 번들 처리 결과 ──')
            ->expectsOutputToContain('1) 기존 설치 확장 업데이트: 성공 2, 실패 0, 건너뜀 1')
            ->expectsOutputToContain('2) 의존성으로 배치한 번들: 1개')
            ->expectsOutputToContain('module:g7test-bundled_probe_module ← plugin:g7test-bundled_probe')
            ->expectsOutputToContain('3) 운영자 선택으로 배치한 번들: 1개')
            ->expectsOutputToContain('4) 배치하지 않은 번들: 1개')
            ->expectsOutputToContain('php artisan core:bundled-sources place <식별자>')
            ->assertExitCode(0);
    }

    /**
     * 완료 요약은 디스크에 있던 미설치 번들을 새 버전으로 갱신한 수를 한 줄로 알립니다.
     *
     * @scenario disk_state=present,install_state=not_installed
     *
     * @effects summary_counts_on_disk_bundles
     *
     * @return void 반환값 없음
     */
    public function test_summary_counts_on_disk_bundles(): void
    {
        $planner = $this->partialPlanner();
        $planner->shouldReceive('isOnDisk')->andReturnUsing(
            fn (string $type, string $identifier) => $type === 'plugin' && $identifier === 'g7test-bundled_hidden',
        );
        $this->registerSummaryCommand($planner->plan($planner->readInventoryFromDirectory($this->release)));

        $this->artisan('test:bundled-summary')
            ->expectsOutputToContain('디스크에 있던 미설치 번들 1개는 새 버전으로 갱신했습니다.')
            ->assertExitCode(0);
    }

    /**
     * 기록 파일 디렉토리가 sudo 업데이트 소유권 복원 대상에 있습니다.
     *
     * @effects record_dir_in_restore_ownership
     *
     * @return void 반환값 없음
     */
    public function test_record_dir_is_restore_ownership_target(): void
    {
        $this->assertContains('storage/app/core_update', config('app.update.restore_ownership'));
    }

    /**
     * 요약 트레이트를 그대로 쓰는 시험용 커맨드를 등록합니다.
     *
     * @param  BundledPlacementPlan  $plan  출력할 판정 결과
     * @return void 반환값 없음
     */
    private function registerSummaryCommand(BundledPlacementPlan $plan): void
    {
        $command = new class($plan) extends Command
        {
            use BundledSourcePlacementPrompt;
            use HasUnifiedConfirm;

            protected $signature = 'test:bundled-summary';

            /**
             * 출력할 판정 결과를 받아 둡니다.
             *
             * @param  BundledPlacementPlan  $plan  출력할 판정 결과
             */
            public function __construct(private readonly BundledPlacementPlan $plan)
            {
                parent::__construct();
            }

            /**
             * 요약 블록을 출력합니다.
             *
             * @return int 종료 코드
             */
            public function handle(): int
            {
                $this->summarizeBundledPlacement($this->plan, ['success' => 2, 'failed' => 0, 'skipped' => 1, 'has_updates' => true]);

                return self::SUCCESS;
            }
        };
        $command->setLaravel($this->app);
        $this->app->make(Kernel::class)->registerCommand($command);
    }

    /**
     * 빈 기본 동작의 CoreUpdateService 목을 컨테이너에 넣습니다.
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }

    /**
     * Step 2(_pending 검증)에서 실패해 유지보수 모드 전에 멈추는 목을 만듭니다.
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function serviceStoppingAtStep2(): MockInterface
    {
        $service = $this->mockService();
        $service->shouldReceive('validatePendingPath')->once()->andReturn([
            'valid' => false, 'errors' => ['stop'], 'path' => 'p', 'owner' => 'o', 'group' => 'g', 'permissions' => '0755',
        ]);

        return $service;
    }

    /**
     * GitHub 경로를 다운로드(Step 4)까지 진행시키는 목 동작을 붙입니다.
     *
     * @param  MockInterface  $service  CoreUpdateService 목
     * @return void 반환값 없음
     */
    private function stubGithubUntilDownload(MockInterface $service): void
    {
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldReceive('checkForUpdates')->andReturn(['latest_version' => '7.1.2', 'update_available' => true]);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('downloadUpdate')->once()->andReturn($this->release);
    }

    /**
     * Step 7 까지 진행해 applyUpdate 의 건너뛸 경로와 그 직후 기록을 잡고, 마이그레이션 실패로 롤백시키는 목을 붙입니다.
     *
     * @return \stdClass 잡은 값 (skip: 건너뛸 경로, record: 기록 내용) — 실행 뒤 채워진다
     */
    private function serviceCapturingApplyThenRollback(): \stdClass
    {
        $planner = $this->app->make(BundledSourcePlanner::class);
        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backup);

        $captured = (object) ['skip' => null, 'record' => null];
        $service = $this->mockService();
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('validatePendingUpdate')->once();
        $service->shouldReceive('copySourceToPending')->once()->andReturn($this->release);
        $service->shouldReceive('createBackup')->once()->andReturn($backup);
        $service->shouldReceive('snapshotOwnership')->andReturn([]);
        $service->shouldReceive('resolveStagingRoot')->andReturn($this->release);
        $service->shouldReceive('snapshotOwnershipDetailed')->andReturn([]);
        $service->shouldReceive('isComposerUnchangedForCore')->andReturn(true);
        $service->shouldReceive('applyUpdate')->once()->andReturnUsing(
            function ($path, $progress, $prune, $applyList, $skipPaths) use ($captured): void {
                $captured->skip = $skipPaths;
            },
        );
        $service->shouldReceive('runMigrations')->once()->andReturnUsing(function () use ($captured, $planner): void {
            $captured->record = json_decode((string) file_get_contents($planner->recordPath()), true);
            throw new \RuntimeException('migration failed');
        });
        $service->shouldReceive('restoreFromBackup')->once()->andReturn([]);
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');
        $service->shouldReceive('disableMaintenanceMode')->once();

        return $captured;
    }

    /**
     * 픽스처 배포본을 최상위 래퍼 폴더(`g7-7.1.2/`)가 있는 zip 으로 묶습니다.
     *
     * @return string zip 파일 경로
     */
    private function zipRelease(): string
    {
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'release.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (File::allFiles($this->release) as $file) {
            $zip->addFile($file->getPathname(), 'g7-7.1.2/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * 설치 상태를 흉내 내는 판정 서비스를 만듭니다(g7test-installed_mod 만 설치).
     *
     * @return BundledSourcePlanner 판정 서비스
     */
    private function planner(): BundledSourcePlanner
    {
        return new BundledSourcePlanner(...$this->plannerRepositories());
    }

    /**
     * 일부 메서드만 바꿀 수 있는 판정 서비스 부분 목을 만듭니다(나머지는 실제 구현).
     *
     * @return MockInterface 판정 서비스 부분 목
     */
    private function partialPlanner(): MockInterface
    {
        return Mockery::mock(BundledSourcePlanner::class, $this->plannerRepositories())->makePartial();
    }

    /**
     * 판정 서비스 생성자에 넣을 설치 상태 Repository 목을 만듭니다(g7test-installed_mod 만 설치).
     *
     * @return array<int, mixed> 모듈·플러그인·템플릿·언어팩 Repository 목
     */
    private function plannerRepositories(): array
    {
        $repo = function (string $class, array $ids) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('getInstalledIdentifiers')->andReturn($ids);

            return $mock;
        };
        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);

        return [
            $repo(ModuleRepositoryInterface::class, ['g7test-installed_mod']),
            $repo(PluginRepositoryInterface::class, []),
            $repo(TemplateRepositoryInterface::class, []),
            $lp,
        ];
    }

    /**
     * 실측 픽스처와 같은 구성의 배포본을 씁니다(모두 개발 디스크에 없는 g7test-* 식별자).
     *
     * @return void 반환값 없음
     */
    private function writeRelease(): void
    {
        $put = function (string $rel, string $content): void {
            $file = $this->release.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $content);
        };
        $json = fn (array $data) => (string) json_encode($data, JSON_UNESCAPED_UNICODE);

        $put('composer.json', '{"name":"test/g7"}');
        $put('app/.gitkeep', '');
        $put('config/app.php', "<?php return ['version' => env('APP_VERSION', '7.1.2')];");
        $put('modules/_bundled/g7test-installed_mod/module.json', $json(['identifier' => 'g7test-installed_mod', 'version' => '1.0.0', 'name' => ['ko' => '설치됨']]));
        $put('modules/_bundled/g7test-bundled_probe_module/module.json', $json(['identifier' => 'g7test-bundled_probe_module', 'version' => '1.0.0', 'name' => ['ko' => '프로브 모듈']]));
        $put('plugins/_bundled/g7test-bundled_probe/plugin.json', $json([
            'identifier' => 'g7test-bundled_probe', 'version' => '1.0.0', 'name' => ['ko' => '프로브'],
            'dependencies' => ['modules' => ['g7test-bundled_probe_module' => '>=1.0.0']],
        ]));
        $put('plugins/_bundled/g7test-bundled_hidden/plugin.json', $json(['identifier' => 'g7test-bundled_hidden', 'version' => '1.0.0', 'hidden' => true]));
        $put('lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja/language-pack.json', $json([
            'identifier' => 'g7-plugin-g7test-bundled_probe-ja', 'version' => '1.0.0', 'scope' => 'plugin', 'target_identifier' => 'g7test-bundled_probe',
        ]));
    }
}
