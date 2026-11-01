<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 번들 일괄 업데이트가 모듈·플러그인을 서로의 의존 순서로 갱신하는지 검증 (#153 A·B).
 *
 * 종전에는 모듈 전부 → 플러그인 전부(같은 종류 안만 정렬, 같은 프로세스 경로는 DB 순서 그대로)라
 * 모듈이 플러그인에 의존하면 옛 플러그인 기준으로 거짓 「의존 버전 미달」 경고가 나고,
 * 업그레이드 스텝이 플러그인 새 코드를 쓰면 실패했다. 의존은 새 배포본(_bundled) manifest 선언을 읽는다.
 */
class BundledUpdateCrossTypeOrderTest extends TestCase
{
    /** @var array<int, string> 테스트가 만든 임시 경로 */
    private array $tempPaths = [];

    /** @var array<int, string> 매니저 update 호출 순서 */
    private array $calls = [];

    /**
     * 임시 번들 manifest 와 매니저 목을 준비한다 — 모듈 test153-page 가 플러그인 test153-editor 에 의존.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bundled('modules', 'test153-page', 'module.json', ['plugins' => ['test153-editor' => '>=1.0.0']]);
        $this->bundled('modules', 'test153-board', 'module.json', []);
        $this->bundled('plugins', 'test153-editor', 'plugin.json', []);

        $calls = &$this->calls;
        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('updateModule')->andReturnUsing(function (string $id) use (&$calls): array {
            $calls[] = "module:{$id}";

            return [];
        });
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('updatePlugin')->andReturnUsing(function (string $id) use (&$calls): array {
            $calls[] = "plugin:{$id}";

            return [];
        });
        $this->app->instance(ModuleManager::class, $modules);
        $this->app->instance(PluginManager::class, $plugins);
    }

    /**
     * 임시 번들 manifest 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
        }
        parent::tearDown();
    }

    /**
     * `{type}/_bundled/{id}/{file}` 임시 manifest 를 만든다.
     *
     * @param  string  $type  확장 유형 디렉토리 (modules·plugins)
     * @param  string  $identifier  확장 식별자
     * @param  string  $file  manifest 파일 이름
     * @param  array<string, mixed>  $dependencies  dependencies 선언
     * @return void 반환값 없음
     */
    private function bundled(string $type, string $identifier, string $file, array $dependencies): void
    {
        $dir = base_path("{$type}/_bundled/{$identifier}");
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$file}", json_encode(['identifier' => $identifier, 'version' => '1.0.0', 'dependencies' => $dependencies]));
        $this->tempPaths[] = $dir;
    }

    /**
     * 프로세스 분리 경로 — 모듈이 의존하는 플러그인을 먼저 갱신하고, 의존 없는 모듈은 제자리.
     *
     * @scenario surface=bundled_update, edge=module_to_plugin
     *
     * @effects bundled_update_orders_modules_and_plugins_across_types
     *
     * @return void 반환값 없음
     */
    public function test_spawned_child_updates_plugin_before_dependent_module(): void
    {
        $path = storage_path('app/test_bundled_manifest_153_'.uniqid().'.json');
        File::put($path, json_encode([
            'modules' => [['identifier' => 'test153-page', 'strategy' => 'overwrite'], ['identifier' => 'test153-board', 'strategy' => 'keep']],
            'plugins' => [['identifier' => 'test153-editor', 'strategy' => 'overwrite']],
            'templates' => [],
            'lang_packs' => [],
        ]));
        $this->tempPaths[] = $path;

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['plugin:test153-editor', 'module:test153-page', 'module:test153-board'], $this->calls);
        $this->assertStringContainsString('"success":3', $output);
    }

    /**
     * 같은 프로세스 경로(proc_open 없음)도 같은 순서 — 목록이 DB 순서여도 의존 순서로 갱신한다.
     *
     * @scenario surface=bundled_update, edge=module_to_plugin
     *
     * @effects bundled_update_in_process_uses_same_cross_type_order
     *
     * @return void 반환값 없음
     */
    public function test_in_process_fallback_uses_same_order(): void
    {
        $command = $this->app->make(CoreUpdateCommand::class);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $method = new \ReflectionMethod($command, 'executeBulkUpdateInProcess');
        $result = $method->invoke(
            $command,
            $this->app->make(ModuleManager::class),
            $this->app->make(PluginManager::class),
            Mockery::mock(TemplateManager::class),
            [
                'modules' => [['identifier' => 'test153-board'], ['identifier' => 'test153-page']],
                'plugins' => [['identifier' => 'test153-editor']],
                'templates' => [],
                'lang_packs' => [],
            ],
            ['modules:test153-page' => 'keep'],
        );

        $this->assertSame(['module:test153-board', 'plugin:test153-editor', 'module:test153-page'], $this->calls);
        $this->assertSame(3, $result['success'], $output->fetch());
    }

    /**
     * 종류를 넘는 순환은 경고 한 줄을 남기고 원래 순서로 갱신한다 — 항목을 잃지 않는다.
     *
     * @scenario surface=bundled_update, edge=cycle
     *
     * @effects bundled_update_warns_cross_type_cycle
     *
     * @return void 반환값 없음
     */
    public function test_cross_type_cycle_warns_and_keeps_original_order(): void
    {
        $this->bundled('plugins', 'test153-editor', 'plugin.json', ['modules' => ['test153-page' => '*']]);
        $path = storage_path('app/test_bundled_manifest_153_'.uniqid().'.json');
        File::put($path, json_encode([
            'modules' => [['identifier' => 'test153-page']],
            'plugins' => [['identifier' => 'test153-editor']],
        ]));
        $this->tempPaths[] = $path;

        Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
        $output = Artisan::output();

        $this->assertSame(['module:test153-page', 'plugin:test153-editor'], $this->calls);
        $this->assertStringContainsString(
            __('extensions.commands.bundled_update.dependency_cycle', ['cycle' => 'module:test153-page → plugin:test153-editor → module:test153-page']),
            $output
        );
    }
}
