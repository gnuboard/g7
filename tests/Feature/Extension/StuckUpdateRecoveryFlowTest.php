<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 멈춘 업데이트 정리 — 업데이트(--force)·활성화 진입점이 실제로 고착을 푸는지 (7.1.1 출시 전)
 *
 * 실측: 번들 업데이트 자식 프로세스가 죽은 뒤 `module:update --force`·`module:activate` 가 모두 「진행 중인 작업(updating)」
 * 으로 거절돼 DB 를 직접 고쳐야 했다.
 */
class StuckUpdateRecoveryFlowTest extends TestCase
{
    use DatabaseTransactions;

    /** 활성화 경로 시험용 가짜 플러그인 식별자 (네임스페이스 Plugins\G7test\Stuckplug) */
    private const FIXTURE_PLUGIN = 'g7test-stuckplug';

    /** 시험이 만든 확장 폴더 (tearDown 정리) */
    private array $fixtureDirs = [];

    /**
     * 테스트 준비 — 앞 테스트가 남긴 정리 기록(프로세스 정적 버퍼)을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        StuckUpdateRecovery::takeRecovered();
    }

    /**
     * 시험이 만든 가짜 확장 폴더를 지우고 라우트 캐시 미룸을 푼다(재생성은 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->fixtureDirs = [];
        $this->setRouteCacheDeferDepth(0);

        parent::tearDown();
    }

    /**
     * 확장 행을 updating 으로 멈춘 상태로 만듭니다.
     *
     * @param  class-string  $model  모델 클래스
     * @param  string  $identifier  식별자
     * @param  int  $secondsAgo  마지막 갱신이 몇 초 전인지
     * @return void 반환값 없음
     */
    private function stuck(string $model, string $identifier, int $secondsAgo = 3600): void
    {
        $row = $model::query()->where('identifier', $identifier)->first() ?? new $model;
        $row->forceFill([
            'identifier' => $identifier,
            'vendor' => 'g7test',
            'name' => json_encode(['ko' => $identifier]),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Updating->value,
            ...($model === Template::class ? ['type' => 'user'] : []),
        ]);
        $row->timestamps = false;
        $row->updated_at = Carbon::now()->subSeconds($secondsAgo);
        $row->save();

        Cache::lock(ExtensionOperationLock::key(strtolower(class_basename($model)), $identifier))->forceRelease();
    }

    /**
     * --force 업데이트는 멈춘 updating 을 inactive 로 정리한 뒤 진행하고, 없으면 종전처럼 거절한다.
     *
     * 파일이 없는 식별자를 써서 정리 직후 단계(확장 조회)에서 멈추게 한다 — 실제 파일 교체 없이 정리 여부만 본다.
     *
     * @scenario entry_point=update
     *
     * @effects update_without_force_rejects_and_points_to_force, force_update_resets_stale_updating_to_inactive
     *
     * @return void 반환값 없음
     */
    public function test_force_update_recovers_stuck_updating(): void
    {
        foreach ([
            [Module::class, 'g7test-stuck-ghost-mod', fn (bool $force) => app(ModuleManager::class)->updateModule('g7test-stuck-ghost-mod', $force)],
            [Plugin::class, 'g7test-stuck-ghost-plg', fn (bool $force) => app(PluginManager::class)->updatePlugin('g7test-stuck-ghost-plg', $force)],
            [Template::class, 'g7test-stuck-ghost-tpl', fn (bool $force) => app(TemplateManager::class)->updateTemplate('g7test-stuck-ghost-tpl', $force)],
        ] as [$model, $identifier, $update]) {
            $this->stuck($model, $identifier);

            // --force 없이는 정리하지 않고, 해결 방법을 알려 준다.
            try {
                $update(false);
                $this->fail("{$identifier}: 멈춘 업데이트인데 막지 않았다");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('--force', $e->getMessage(), $identifier);
            }
            $this->assertSame(ExtensionStatus::Updating->value, $model::query()->where('identifier', $identifier)->value('status'));

            try {
                $update(true);
            } catch (\Throwable) {
                // 파일이 없는 식별자라 정리 뒤 단계에서 멈춘다 — 정리 여부만 본다.
            }
            $this->assertSame(ExtensionStatus::Inactive->value, $model::query()->where('identifier', $identifier)->value('status'), "{$identifier}: --force 가 멈춘 업데이트를 풀지 못했다");
        }
    }

    /**
     * 실제로 도는(600초 이내) 업데이트는 --force 여도 건드리지 않는다.
     *
     * @scenario entry_point=update
     *
     * @effects recent_updating_left_alone, running_update_guard_message_has_no_force_hint
     *
     * @return void 반환값 없음
     */
    public function test_force_update_leaves_recent_updating_alone(): void
    {
        $this->stuck(Module::class, 'g7test-stuck-ghost-mod', 30);

        try {
            app(ModuleManager::class)->updateModule('g7test-stuck-ghost-mod', true);
            $this->fail('진행 중 업데이트인데 막지 않았다');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('--force', $e->getMessage());
        }

        $this->assertSame(ExtensionStatus::Updating->value, Module::query()->where('identifier', 'g7test-stuck-ghost-mod')->value('status'));
    }

    /**
     * 활성화도 같은 판정으로 멈춘 updating 을 정리한 뒤 진행하고, 명령이 출력할 정리 기록을 남긴다.
     *
     * @scenario entry_point=activate
     *
     * @effects activate_resets_stale_updating_and_proceeds, recovery_recorded_for_command_output
     *
     * @return void 반환값 없음
     */
    public function test_activate_recovers_stuck_updating(): void
    {
        // 실제 설치 확장 대신 시험 전용 가짜 플러그인 — 오토로드·훅 캐시는 목으로, 라우트 캐시 재생성은 미뤄 공유 개발 서버의 캐시를 건드리지 않는다
        $manager = $this->makeStuckFixturePlugin();
        $this->stuck(Plugin::class, self::FIXTURE_PLUGIN);

        try {
            $result = $manager->activatePlugin(self::FIXTURE_PLUGIN);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }

        $this->assertNotSame(ExtensionStatus::Updating->value, Plugin::query()->where('identifier', self::FIXTURE_PLUGIN)->value('status'), '활성화가 멈춘 업데이트를 풀지 못했다: '.json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            [['type' => 'plugin', 'identifier' => self::FIXTURE_PLUGIN, 'status' => ExtensionStatus::Inactive->value, 'unfinished' => null]],
            StuckUpdateRecovery::takeRecovered(),
            '명령이 출력할 정리 기록이 남아야 한다',
        );
    }

    /**
     * 시험 전용 가짜 플러그인(디스크)을 만들고 확장 관리자를 목으로 바꾼 플러그인 관리자를 돌려줍니다 — tearDown 이 폴더를 지운다.
     *
     * @return PluginManager 가짜 플러그인을 읽은 플러그인 관리자
     */
    private function makeStuckFixturePlugin(): PluginManager
    {
        $path = base_path('plugins/'.self::FIXTURE_PLUGIN);
        $this->fixtureDirs[] = $path;
        File::ensureDirectoryExists($path);

        File::put($path.'/plugin.php', <<<'PHP'
<?php

namespace Plugins\G7test\Stuckplug;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getName(): string|array
    {
        return ['ko' => '멈춤 정리 시험 플러그인', 'en' => 'Stuck Recovery Test Plugin'];
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string|array
    {
        return ['ko' => '시험용', 'en' => 'For testing'];
    }
}
PHP);
        File::put($path.'/plugin.json', json_encode([
            'identifier' => self::FIXTURE_PLUGIN,
            'vendor' => 'g7test',
            'name' => ['ko' => '멈춤 정리 시험 플러그인', 'en' => 'Stuck Recovery Test Plugin'],
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT));

        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, function (MockInterface $m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('hasComposerDependenciesAt')->andReturn(false);
        }));
        $this->setRouteCacheDeferDepth(1);

        PluginManager::invalidatePluginStatusCache();
        $this->app->forgetInstance(PluginManager::class);
        $manager = app(PluginManager::class);
        $manager->loadPlugins();

        return $manager;
    }

    /**
     * 라우트 캐시 재생성 미룸 깊이를 정합니다 — 0 으로 되돌릴 때 쌓인 재생성 요청은 버린다(형제 시험 UnfinishedUpdateStepsResumeTest 와 같음).
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
     * `--force` 없는 업데이트 명령은 「업데이트 없음」보다 먼저 멈춘 업데이트 안내를 낸다(7.1.1 출시 전).
     *
     * 끊긴 업데이트는 DB 판을 이미 새 판으로 적어 두므로 업데이트 확인이 「업데이트 없음」으로 끝나 안내가 운영자에게 닿지 않았다.
     *
     * @scenario entry_point=update
     *
     * @effects update_command_reports_stuck_before_no_update, update_without_force_rejects_and_points_to_force
     *
     * @return void 반환값 없음
     */
    public function test_update_command_without_force_reports_stuck_before_no_update(): void
    {
        app()->setLocale('ko');

        foreach ([
            [Module::class, 'module', 'g7test-stuck-ghost-mod'],
            [Plugin::class, 'plugin', 'g7test-stuck-ghost-plg'],
            [Template::class, 'template', 'g7test-stuck-ghost-tpl'],
        ] as [$model, $type, $identifier]) {
            $this->stuck($model, $identifier);

            $this->artisan("{$type}:update", ['identifier' => $identifier])
                ->expectsOutputToContain('--force')
                ->assertExitCode(1);

            $this->assertSame(ExtensionStatus::Updating->value, $model::query()->where('identifier', $identifier)->value('status'), "{$identifier}: --force 없이 정리하면 안 된다");
        }
    }

    /**
     * `--force` 업데이트로 정리하면 상태가 비활성으로 끝나므로, 다시 켜는 명령을 함께 안내한다(7.1.1 출시 전).
     *
     * @scenario entry_point=update
     *
     * @effects force_update_recovery_output_tells_how_to_reactivate, force_update_resets_stale_updating_to_inactive
     *
     * @return void 반환값 없음
     */
    public function test_force_update_recovery_tells_how_to_reactivate(): void
    {
        app()->setLocale('ko');
        $this->stuck(Module::class, 'g7test-stuck-ghost-mod');

        // 파일이 없는 식별자라 정리 뒤 단계에서 실패로 끝난다 — 정리 안내와 다음 행동만 본다.
        $this->artisan('module:update', ['identifier' => 'g7test-stuck-ghost-mod', '--force' => true])
            ->expectsOutputToContain('php artisan module:activate g7test-stuck-ghost-mod');

        $this->assertSame(ExtensionStatus::Inactive->value, Module::query()->where('identifier', 'g7test-stuck-ghost-mod')->value('status'));
    }
}
