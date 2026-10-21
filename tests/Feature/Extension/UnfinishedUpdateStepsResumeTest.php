<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateResource;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 여러 판을 건너뛴 확장 업데이트의 중간 판 업그레이드 스텝이 끊긴 뒤에도 순서대로 정확히 한 번 도는지 (7.1.1 출시 전)
 *
 * 결함(수정 전): 업데이트는 DB 판을 새 판으로 적은 뒤 스텝을 돈다. 1.0.0 → 1.0.4 업데이트가 1.0.2 스텝 도중 죽으면 DB 판은 1.0.4 라
 * `--force` 재업데이트가 `from == to` 로 1.0.4 스텝만 돌았다 — 1.0.2·1.0.3 은 다시 돌 길이 없었다. 예외로 실패하면 반대로 판을
 * 1.0.0 으로 되돌려 다음 시도가 이미 끝낸 1.0.1 부터 다시 돌았다. 파일을 바꾼 직후 죽으면 정리(활성화)가 파일 판·DB 판 불일치를
 * 알리지 않았다.
 *
 * 실제 업데이트 경로(백업 → 번들 스테이징 → 파일 교체 → DB 갱신 → 스텝)를 가짜 확장(`testmerge-*`)으로 돌린다. 스텝은 끝날 때 기록
 * 파일에 자기 판을 한 줄 쓴다. 새 판 소스는 `modules/_bundled` 가 아니라 `storage/framework/testing` 아래 임시 폴더에 두고 ZIP 업데이트
 * 경로로 넣는다 — 번들 경로에 시험 폴더가 생기면 번들 동기화·감사·설치에 섞인다. 오토로드·훅 캐시 재생성은 실제 파일을 건드리므로
 * 확장 관리자를 목으로(ZIP 준비는 임시 폴더 사본을 돌려준다), 라우트 캐시 재생성은 공유 개발 서버의 캐시를 지우지 않도록 미뤄 둔다.
 */
class UnfinishedUpdateStepsResumeTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /** 스텝 실행 기록 파일 */
    private string $stepLog;

    /** 새 판 소스 임시 폴더 (식별자 => 경로) */
    private array $newVersionSources = [];

    /** 시험이 만든 임시 폴더 (tearDown 정리) */
    private array $tempDirs = [];

    /**
     * 테스트 준비 — 스텝 기록 파일·진행 기록·정리 기록을 비우고 라우트 캐시 재생성을 미룬다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->stepLog = storage_path('framework/testing/g7test-f46-steps-'.getmypid().'.log');
        File::ensureDirectoryExists(dirname($this->stepLog));
        File::delete($this->stepLog);
        config(['g7test_f46.log' => $this->stepLog, 'g7test_f46.fail_at' => null]);

        ExtensionUpdateJournal::discard('module', $this->fixtureModule);
        ExtensionUpdateJournal::discard('plugin', $this->fixturePlugin);
        ExtensionUpdateJournal::discard('template', $this->fixtureTemplate);
        StuckUpdateRecovery::takeRecovered();

        $this->setRouteCacheDeferDepth(1);
    }

    /**
     * 픽스처 디스크 산출물·진행 기록·스텝 기록을 지우고 라우트 캐시 미룸을 푼다(재생성은 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();
        ExtensionUpdateJournal::discard('module', $this->fixtureModule);
        ExtensionUpdateJournal::discard('plugin', $this->fixturePlugin);
        ExtensionUpdateJournal::discard('template', $this->fixtureTemplate);
        File::delete($this->stepLog);
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->setRouteCacheDeferDepth(0);

        parent::tearDown();
    }

    /**
     * 라우트 캐시 재생성 미룸 깊이를 정합니다 — 0 으로 되돌릴 때 쌓인 재생성 요청은 버린다.
     *
     * @param  int  $depth  미룸 깊이
     * @return void 반환값 없음
     */
    private function setRouteCacheDeferDepth(int $depth): void
    {
        $ref = new \ReflectionClass(RouteCacheHelper::class);
        $ref->setStaticPropertyValue('deferDepth', $depth);
        if ($depth === 0) {
            $ref->setStaticPropertyValue('rebuildPending', false);
        }
    }

    /**
     * 시험 전용 임시 폴더를 만듭니다 — tearDown 이 지운다.
     *
     * @param  string  $name  폴더 이름 접두
     * @return string 폴더 경로
     */
    private function tempDir(string $name): string
    {
        $dir = storage_path('framework/testing/g7test-f46-'.$name.'-'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * 오토로드·훅 캐시를 실제로 굽지 않도록 확장 관리자를 목으로 바꿉니다 — ZIP 준비는 새 판 소스의 임시 사본을 돌려준다.
     *
     * @return void 반환값 없음
     */
    private function fakeExtensionManager(): void
    {
        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, function (MockInterface $m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('hasComposerDependenciesAt')->andReturn(false);
            $m->shouldReceive('prepareZipSource')->andReturnUsing(function (string $zipPath, string $identifier): array {
                $temp = $this->tempDir('zip');
                File::copyDirectory($this->newVersionSources[$identifier], $temp.'/extracted');

                return ['temp_dir' => $temp, 'extracted_dir' => $temp.'/extracted', 'to_version' => '1.0.4', 'manifest' => []];
            });
        }));
        $this->app->forgetInstance(ModuleManager::class);
        $this->app->forgetInstance(PluginManager::class);
    }

    /**
     * 활성 디렉토리를 임시 폴더로 복사하고 1.0.4 판 + 업그레이드 스텝 1.0.1~1.0.4 를 넣습니다 — ZIP 업데이트의 새 판 소스.
     *
     * @param  string  $type  확장 종류 디렉토리 (modules|plugins)
     * @param  string  $identifier  확장 식별자
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  string  $namespace  스텝 네임스페이스 (예: Modules\Testmerge\Kmod\Upgrades)
     * @return string 새 판 소스 경로
     */
    private function stageNewVersionWithSteps(string $type, string $identifier, string $manifestFile, string $namespace): string
    {
        $bundled = $this->tempDir('src-'.$identifier);
        $this->newVersionSources[$identifier] = $bundled;
        File::copyDirectory(base_path("{$type}/{$identifier}"), $bundled);

        $manifest = json_decode(File::get("{$bundled}/{$manifestFile}"), true);
        $manifest['version'] = '1.0.4';
        File::put("{$bundled}/{$manifestFile}", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        File::ensureDirectoryExists("{$bundled}/upgrades");
        foreach (['1.0.1', '1.0.2', '1.0.3', '1.0.4'] as $version) {
            $class = 'Upgrade_'.str_replace('.', '_', $version);
            File::put("{$bundled}/upgrades/{$class}.php", <<<PHP
<?php

namespace {$namespace};

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Extension\\UpgradeContext;

class {$class} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        if (config('g7test_f46.fail_at') === '{$version}') {
            throw new \\RuntimeException('g7test step failure {$version}');
        }

        file_put_contents(config('g7test_f46.log'), "{$version}\\n", FILE_APPEND);
    }
}
PHP);
        }

        return $bundled;
    }

    /**
     * 스텝 기록 파일에 적힌 판 목록.
     *
     * @return list<string> 끝낸 스텝 판 (실행 순서)
     */
    private function stepsRun(): array
    {
        return is_file($this->stepLog) ? array_values(array_filter(explode("\n", (string) File::get($this->stepLog)))) : [];
    }

    /**
     * 프로세스가 죽은 직후 상태를 만듭니다 — 파일은 번들(1.0.4)로 바뀌었고, 행은 `updating` 으로 10분 넘게 남았습니다.
     *
     * @param  string  $type  확장 종류 디렉토리 (modules|plugins)
     * @param  class-string  $model  모델 클래스
     * @param  string  $identifier  확장 식별자
     * @param  string  $recordedVersion  DB 에 남은 판
     * @return void 반환값 없음
     */
    private function simulateKilledUpdate(string $type, string $model, string $identifier, string $recordedVersion): void
    {
        File::copyDirectory($this->newVersionSources[$identifier], base_path("{$type}/{$identifier}"));

        $row = $model::query()->where('identifier', $identifier)->firstOrFail();
        $row->timestamps = false;
        $row->forceFill([
            'version' => $recordedVersion,
            'status' => ExtensionStatus::Updating->value,
            'updated_at' => Carbon::now()->subHour(),
        ])->save();

        Cache::lock(ExtensionOperationLock::key(rtrim($type, 's'), $identifier))->forceRelease();
    }

    /**
     * 스텝이 예외로 실패한 업데이트를 다시 하면, 이미 끝낸 스텝은 건너뛰고 남은 스텝만 순서대로 돈다.
     *
     * @scenario source=module, path=update_failed_mid_steps_then_retry
     *
     * @effects failed_update_keeps_completed_steps, retry_runs_each_intermediate_step_exactly_once_in_order
     *
     * @return void 반환값 없음
     */
    public function test_retry_after_step_failure_runs_each_intermediate_step_once_in_order(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => ['version' => '1.0.0', 'layout_name' => 'index', 'components' => [['id' => 'index', 'type' => 'basic', 'name' => 'Div', 'text' => 'A1']]]]);
        $this->stageNewVersionWithSteps('modules', $this->fixtureModule, 'module.json', 'Modules\\Testmerge\\Kmod\\Upgrades');
        $this->fakeExtensionManager();

        // 1회차 — 1.0.3 스텝에서 실패한다
        config(['g7test_f46.fail_at' => '1.0.3']);
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        try {
            $manager->updateModule($this->fixtureModule, false, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-f46.zip');
            $this->fail('1.0.3 스텝이 실패하면 업데이트도 실패해야 합니다.');
        } catch (\RuntimeException) {
            // 기대한 실패
        }

        $this->assertSame(['1.0.1', '1.0.2'], $this->stepsRun());
        $this->assertSame('1.0.0', Module::where('identifier', $this->fixtureModule)->value('version'), '파일을 되돌렸으니 판도 1.0.0');
        $journal = ExtensionUpdateJournal::find('module', $this->fixtureModule);
        $this->assertNotNull($journal, '끝낸 스텝이 있는 실패는 진행 기록을 남겨야 합니다.');
        $this->assertSame(['1.0.1', '1.0.2'], $journal->completedSteps());

        // 2회차 — 원인을 고치고 다시 업데이트한다
        config(['g7test_f46.fail_at' => null]);
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $result = $manager->updateModule($this->fixtureModule, false, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-f46.zip');

        $this->assertTrue($result['success']);
        $this->assertSame(['1.0.1', '1.0.2', '1.0.3', '1.0.4'], $this->stepsRun(), '끝낸 1.0.1·1.0.2 는 다시 돌지 않고 1.0.3·1.0.4 만 순서대로 돌아야 합니다.');
        $this->assertSame('1.0.4', Module::where('identifier', $this->fixtureModule)->value('version'));
        $this->assertNull(ExtensionUpdateJournal::find('module', $this->fixtureModule), '끝난 업데이트는 진행 기록을 지워야 합니다.');
    }

    // 아래 시험이 함께 도는 확장 종류 — docblock 은 @scenario 를 1건만 인식하므로 나머지 종류는 라인 주석으로 적는다.
    // @scenario source=plugin, path=update_killed_mid_steps_then_force_update

    /**
     * 스텝 도중 프로세스가 죽은 뒤 `--force` 재업데이트는 정리하며 판을 시작 판으로 되돌리고, 못 끝낸 중간 판 스텝을 순서대로 한 번씩 돈다.
     *
     * @scenario source=module, path=update_killed_mid_steps_then_force_update
     *
     * @effects stuck_recovery_reverts_recorded_version_to_journal_start, force_update_runs_remaining_intermediate_steps_once_in_order
     *
     * @return void 반환값 없음
     */
    public function test_force_update_after_kill_mid_steps_runs_remaining_intermediate_steps(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => ['version' => '1.0.0', 'layout_name' => 'index', 'components' => [['id' => 'index', 'type' => 'basic', 'name' => 'Div', 'text' => 'A1']]]]);
        $this->makeFixturePlugin(['settings.json' => ['version' => '1.0.0', 'layout_name' => 'settings', 'components' => [['id' => 'settings', 'type' => 'basic', 'name' => 'Div', 'text' => 'P1']]]]);
        $this->stageNewVersionWithSteps('modules', $this->fixtureModule, 'module.json', 'Modules\\Testmerge\\Kmod\\Upgrades');
        $this->stageNewVersionWithSteps('plugins', $this->fixturePlugin, 'plugin.json', 'Plugins\\Testmerge\\Kplug\\Upgrades');
        $this->fakeExtensionManager();

        foreach ([
            ['modules', Module::class, $this->fixtureModule, 'module', fn () => tap(app(ModuleManager::class), fn ($m) => $m->loadModules())->updateModule($this->fixtureModule, true, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-f46.zip')],
            ['plugins', Plugin::class, $this->fixturePlugin, 'plugin', fn () => tap(app(PluginManager::class), fn ($m) => $m->loadPlugins())->updatePlugin($this->fixturePlugin, true, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-f46.zip')],
        ] as [$dir, $model, $identifier, $type, $forceUpdate]) {
            File::delete($this->stepLog);

            // 1.0.0 → 1.0.4 업데이트가 DB 판을 1.0.4 로 적고 1.0.1 스텝을 끝낸 뒤 1.0.2 스텝 도중 죽었다
            $journal = ExtensionUpdateJournal::begin($type, $identifier, '1.0.0', '1.0.4', 'zip');
            $journal->markFilesTouched();
            $journal->markStepCompleted('1.0.1');
            $this->simulateKilledUpdate($dir, $model, $identifier, '1.0.4');

            $result = $forceUpdate();

            $this->assertTrue($result['success'], $identifier);
            $this->assertSame(['1.0.2', '1.0.3', '1.0.4'], $this->stepsRun(), "{$identifier}: 못 끝낸 1.0.2·1.0.3·1.0.4 가 순서대로 한 번씩 돌아야 합니다(1.0.1 은 끝냈다).");
            $this->assertSame('1.0.0', $result['from_version'], "{$identifier}: 정리가 판을 끊긴 업데이트의 시작 판으로 되돌려야 합니다.");
            $row = $model::query()->where('identifier', $identifier)->firstOrFail();
            $this->assertSame('1.0.4', $row->version, $identifier);
            $this->assertSame(ExtensionStatus::Inactive->value, $row->status, "{$identifier}: 정리한 확장은 비활성으로 남는다(설계 결정).");
            $this->assertNull(ExtensionUpdateJournal::find($type, $identifier), $identifier);

            $recovered = StuckUpdateRecovery::takeRecovered();
            $this->assertSame(['from' => '1.0.0', 'to' => '1.0.4'], $recovered[0]['unfinished'] ?? null, $identifier);
        }
    }

    /**
     * 파일을 바꾼 직후(DB 판 기록 전) 죽은 업데이트를 정리하면, 끝나지 않았다고 알리고 「업데이트 있음」으로 남긴다 — 템플릿 포함.
     *
     * @scenario source=template, path=update_killed_after_files_before_version
     *
     * @effects recovery_reports_unfinished_version_change, recovery_marks_update_available
     *
     * @return void 반환값 없음
     */
    public function test_recovery_after_kill_between_files_and_version_reports_unfinished(): void
    {
        $template = $this->makeFixtureTemplate();
        $template->timestamps = false;
        $template->forceFill([
            'version' => '1.0.3',
            'latest_version' => '1.0.3',
            'update_available' => false,
            'status' => ExtensionStatus::Updating->value,
            'updated_at' => Carbon::now()->subHour(),
        ])->save();
        Cache::lock(ExtensionOperationLock::key('template', $this->fixtureTemplate))->forceRelease();

        $journal = ExtensionUpdateJournal::begin('template', $this->fixtureTemplate, '1.0.3', '1.0.4', 'bundled');
        $journal->markFilesTouched();

        $repository = app(TemplateRepositoryInterface::class);
        $record = $repository->findByIdentifier($this->fixtureTemplate);
        $restored = StuckUpdateRecovery::recover('template', $this->fixtureTemplate, $record, fn (array $attributes) => $repository->updateByIdentifier($this->fixtureTemplate, $attributes));

        $this->assertSame(ExtensionStatus::Inactive->value, $restored);
        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.0.3', $row->version, '기록 판은 끝난 판(시작 판) 그대로');
        $this->assertSame('1.0.4', $row->latest_version);
        $this->assertTrue((bool) $row->update_available, '다음 업데이트가 --force 없이도 판 전환을 마칠 수 있어야 합니다.');
        $this->assertSame('bundled', $row->update_source);
        $this->assertSame([['type' => 'template', 'identifier' => $this->fixtureTemplate, 'status' => 'inactive', 'unfinished' => ['from' => '1.0.3', 'to' => '1.0.4']]], StuckUpdateRecovery::takeRecovered());
        $this->assertNotNull(ExtensionUpdateJournal::find('template', $this->fixtureTemplate), '판 전환을 마칠 때까지 진행 기록을 남겨야 합니다.');
    }

    /**
     * 파일을 건드리기 전에 멈춘 업데이트는 아무 일도 없던 것 — 정리는 판을 건드리지 않고 진행 기록만 지운다.
     *
     * @scenario source=template, path=update_killed_before_files
     *
     * @effects recovery_discards_untouched_journal
     *
     * @return void 반환값 없음
     */
    public function test_recovery_before_files_touched_discards_journal(): void
    {
        $template = $this->makeFixtureTemplate();
        $template->timestamps = false;
        $template->forceFill(['version' => '1.0.3', 'update_available' => false, 'status' => ExtensionStatus::Updating->value, 'updated_at' => Carbon::now()->subHour()])->save();
        Cache::lock(ExtensionOperationLock::key('template', $this->fixtureTemplate))->forceRelease();

        ExtensionUpdateJournal::begin('template', $this->fixtureTemplate, '1.0.3', '1.0.4', 'bundled');

        $repository = app(TemplateRepositoryInterface::class);
        StuckUpdateRecovery::recover('template', $this->fixtureTemplate, $repository->findByIdentifier($this->fixtureTemplate), fn (array $attributes) => $repository->updateByIdentifier($this->fixtureTemplate, $attributes));

        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.0.3', $row->version);
        $this->assertFalse((bool) $row->update_available);
        $this->assertNull(StuckUpdateRecovery::takeRecovered()[0]['unfinished']);
        $this->assertNull(ExtensionUpdateJournal::find('template', $this->fixtureTemplate));
    }

    // 아래 시험이 함께 도는 확장 종류 — docblock 은 @scenario 를 1건만 인식하므로 나머지 종류는 라인 주석으로 적는다.
    // @scenario source=plugin, path=unfinished_version_change_info
    // @scenario source=template, path=unfinished_version_change_info

    /**
     * 판 전환이 끝나지 않은 확장의 정보(활성화 응답 `data.module` 등)는 DB 판(끝난 판)과 「업데이트 있음」을 말한다 — 파일 판이 아니다.
     *
     * @scenario source=module, path=unfinished_version_change_info
     *
     * @effects extension_info_reports_recorded_version_and_update_available
     *
     * @return void 반환값 없음
     */
    public function test_info_of_unfinished_update_reports_recorded_version(): void
    {
        $this->makeFixtureTemplate();
        $this->writeFixtureTemplateManifest();
        $this->makeFixtureModule([]);
        $this->makeFixturePlugin([]);

        // 파일은 1.0.0(새 판)인데 정리가 DB 판을 시작 판 0.9.0 으로 되돌리고 「업데이트 있음」으로 둔 상태
        $unfinished = ['version' => '0.9.0', 'latest_version' => '1.0.0', 'update_available' => true, 'update_source' => 'bundled'];
        Module::where('identifier', $this->fixtureModule)->update($unfinished);
        Plugin::where('identifier', $this->fixturePlugin)->update($unfinished);
        Template::where('identifier', $this->fixtureTemplate)->update($unfinished);

        $templates = app(TemplateManager::class);
        $templates->loadTemplates();

        foreach ([
            ['module', $this->reloadFixtureModuleManager()->getModuleInfo($this->fixtureModule), ModuleResource::class],
            ['plugin', $this->reloadFixturePluginManager()->getPluginInfo($this->fixturePlugin), PluginResource::class],
            ['template', $templates->getTemplateInfo($this->fixtureTemplate), TemplateResource::class],
        ] as [$type, $info, $resource]) {
            $this->assertIsArray($info, $type);
            $this->assertSame('0.9.0', $info['version'], "{$type}: 정보의 판이 DB 판(끝난 판)이어야 합니다 — 목록·카드와 같게.");
            $this->assertSame('1.0.0', $info['file_version'] ?? null, "{$type}: 파일 판은 file_version 칸으로 따로 알린다.");
            $this->assertTrue((bool) ($info['update_available'] ?? false), "{$type}: 「업데이트 있음」도 함께 알려야 합니다.");
            $this->assertSame('1.0.0', $info['latest_version'] ?? null, $type);

            $payload = (new $resource($info))->resolve();
            $this->assertSame('0.9.0', $payload['version'], "{$type}: 활성화 응답 칸");
            $this->assertTrue((bool) $payload['update_available'], "{$type}: 활성화 응답 칸");
        }
    }

    // 아래 시험이 함께 도는 확장 종류 — docblock 은 @scenario 를 1건만 인식하므로 나머지 종류는 라인 주석으로 적는다.
    // @scenario source=plugin, path=update_rejected_in_progress
    // @scenario source=template, path=update_rejected_in_progress

    /**
     * 다른 업데이트가 진행 중이라 거절된 업데이트 명령은 거절 사유만 알리고 「백업에서 이전 버전이 복원되었습니다」를 붙이지 않는다.
     *
     * @scenario source=module, path=update_rejected_in_progress
     *
     * @effects rejected_update_has_no_restore_hint
     *
     * @return void 반환값 없음
     */
    public function test_rejected_update_command_has_no_restore_hint(): void
    {
        $this->makeFixtureTemplate();
        $this->writeFixtureTemplateManifest();
        $this->makeFixtureModule([]);
        $this->makeFixturePlugin([]);
        $this->fakeExtensionManager();

        foreach ([
            ['module', $this->fixtureModule, 'module:update', __('modules.commands.update.backup_restored')],
            ['plugin', $this->fixturePlugin, 'plugin:update', __('plugins.commands.update.backup_restored')],
            ['template', $this->fixtureTemplate, 'template:update', __('templates.commands.update.backup_restored')],
        ] as [$type, $identifier, $command, $restoredHint]) {
            // 다른 프로세스가 같은 확장을 업데이트하는 중
            $lock = ExtensionOperationLock::acquire($type, $identifier);
            try {
                $exit = Artisan::call($command, ['identifier' => $identifier, '--force' => true, '--no-interaction' => true]);
                $output = Artisan::output();
            } finally {
                $lock->release();
            }

            $this->assertSame(1, $exit, "{$type}: 거절은 실패로 끝나야 합니다. 출력: {$output}");
            $this->assertStringContainsString(__('extensions.errors.update_already_running', ['name' => $identifier]), $output, $type);
            $this->assertStringNotContainsString($restoredHint, $output, "{$type}: 아무것도 복원하지 않았는데 복원 안내가 붙었습니다.");
            $this->assertDoesNotMatchRegularExpression('/\b(\d+)\/\1 \[=+\]/', $output, "{$type}: 거절됐는데 진행 표시줄이 끝까지 찼습니다.");
            $model = ['module' => Module::class, 'plugin' => Plugin::class, 'template' => Template::class][$type];
            $this->assertSame('1.0.0', $model::where('identifier', $identifier)->value('version'), $type);
        }
    }
}
