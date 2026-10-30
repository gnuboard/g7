<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\ConfigCacheHelper;
use App\Support\RouteCacheHelper;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Console\NoSpawnExecuteBundledUpdatesCommand;
use Tests\TestCase;

/**
 * 번들 일괄 업데이트 자식(`core:execute-bundled-updates`)은 확장·언어팩을 하나씩 새 PHP 프로세스에서 처리한다.
 *
 * 한 프로세스에서 확장 19개를 차례로 갱신하면, 확장마다 config·라우트 캐시를 다시 굽는 일회용 앱 부팅이 회수되지 않고
 * 쌓여 512 MB 에서 마지막 템플릿 도중 프로세스가 죽었다. 결과 줄을 남기지 못한 자식을 부모는 「성공 0, 실패 1」로 셌고,
 * 실패한 확장 이름도, 처리되지 못한 언어팩도 화면에 나오지 않았다.
 *
 * 고친 뒤: 자식은 항목마다 새 프로세스를 띄우고(누적이 확장 수에 비례하지 않는다), 항목별 성공·실패를 세며, 실패한
 * 항목 이름과 다시 실행할 명령을 출력한다. 새 프로세스를 띄울 수 없으면 같은 프로세스에서 돌되 캐시 재굽기를 끝에
 * 한 번으로 모은다. 결과 줄(`[BUNDLED-RESULT] {"success":n,"failed":m,…}`)은 이전 판 `core:update` 가 읽는 모양 그대로다.
 */
class BundledUpdateItemProcessIsolationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $tempFiles = [];

    /**
     * 시험이 만든 매니페스트 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    /**
     * 항목이 여럿이면 이 프로세스에서 확장 매니저를 부르지 않고 항목마다 새 프로세스로 처리하며, 실패한 항목을 모두 이름으로 남긴다.
     *
     * 이전 판 `core:update` 가 부르는 모양(`--manifest` 하나)으로 부르고, 그 판의 결과 줄 읽기 방식으로 집계를 읽는다.
     *
     * @scenario spawn=available, items=many, item_result=failed
     *
     * @effects bundled_items_run_each_in_fresh_process, bundled_failed_items_are_named_with_retry_command, bundled_result_payload_keeps_previous_parent_contract
     *
     * @return void 반환값 없음
     */
    public function test_each_item_runs_in_its_own_process_and_every_failure_is_named(): void
    {
        $inProcessCalls = [];
        $this->recordInProcessCalls($inProcessCalls);

        $path = $this->writeManifest([
            'modules' => [['identifier' => 'g9-ghost-module', 'strategy' => 'overwrite']],
            'plugins' => [['identifier' => 'g9-ghost-plugin', 'strategy' => 'keep']],
            'templates' => [['identifier' => 'g9-ghost-template', 'strategy' => 'overwrite']],
            'lang_packs' => [['identifier' => 'g9-ghost-pack-ja']],
            'rebuild_search_index' => false,
        ]);

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
        $output = Artisan::output();

        $this->assertSame([], $inProcessCalls, '항목이 여럿이면 이 프로세스에서 확장 업데이트를 돌리지 않는다 — 항목마다 새 프로세스');
        $this->assertSame(1, $exit, '실패가 있으면 FAILURE');

        $payload = $this->readPayloadLikePreviousParent($output);
        $this->assertSame(0, $payload['success']);
        $this->assertSame(4, $payload['failed'], '실패 수는 항목 수 그대로 — 자식 전체를 1건으로 세지 않는다');
        $this->assertSame(
            ['module:g9-ghost-module', 'plugin:g9-ghost-plugin', 'template:g9-ghost-template', 'lang_pack:g9-ghost-pack-ja'],
            array_map(fn (array $item) => $item['type'].':'.$item['identifier'], $payload['failed_items']),
            '실패 항목은 처리 순서대로 이름과 함께 결과에 남는다',
        );

        foreach (['module:update g9-ghost-module', 'plugin:update g9-ghost-plugin', 'template:update g9-ghost-template', 'language-pack:update g9-ghost-pack-ja'] as $retry) {
            $this->assertStringContainsString($retry, $output, "실패 항목을 다시 실행할 명령이 화면에 나온다: {$retry}");
        }
        $this->assertSame(1, substr_count($output, ExecuteBundledUpdatesCommand::RESULT_PREFIX), '항목 프로세스의 결과 줄은 부모 화면으로 넘기지 않는다');
    }

    /**
     * 항목 프로세스 결과를 집계한다 — 성공·실패는 항목 단위, 결과 줄 없이 끝난 프로세스는 그 항목만 실패로 이름과 종료 코드를 남긴다.
     *
     * @scenario spawn=available, items=many, item_result=aborted
     *
     * @effects bundled_aborted_item_counts_as_that_item_only
     *
     * @return void 반환값 없음
     */
    public function test_item_outcome_counts_per_item_and_names_an_aborted_process(): void
    {
        $item = ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'overwrite'];

        $ok = ExecuteBundledUpdatesCommand::itemOutcome($item, ['success' => 1, 'failed' => 0], 0);
        $this->assertSame([1, 0, []], [$ok['success'], $ok['failed'], $ok['failed_items']]);

        $failed = ExecuteBundledUpdatesCommand::itemOutcome($item, ['success' => 0, 'failed' => 1, 'failed_items' => [$item + ['reason' => 'boom']]], 1);
        $this->assertSame([0, 1], [$failed['success'], $failed['failed']]);
        $this->assertSame('boom', $failed['failed_items'][0]['reason']);

        $failedWithoutNames = ExecuteBundledUpdatesCommand::itemOutcome($item, ['success' => 0, 'failed' => 1], 1);
        $this->assertSame('sirsoft-basic', $failedWithoutNames['failed_items'][0]['identifier'], '이름 없는 실패 결과도 그 항목 이름으로 남긴다');

        $aborted = ExecuteBundledUpdatesCommand::itemOutcome($item, null, 255);
        $this->assertSame([0, 1], [$aborted['success'], $aborted['failed']], '죽은 항목 프로세스는 그 항목 1건만 실패');
        $this->assertSame('sirsoft-basic', $aborted['failed_items'][0]['identifier']);
        $this->assertSame(255, $aborted['failed_items'][0]['exit_code'], '종료 코드를 남긴다');
        $this->assertSame(__('extensions.commands.bundled_update.item_process_aborted', ['code' => 255]), $aborted['failed_items'][0]['reason']);
    }

    /**
     * 새 프로세스를 띄울 수 없는 서버에서는 같은 프로세스에서 돌되 config·라우트 캐시 재굽기를 끝으로 미루고, 실패 항목 이름을 남긴다.
     *
     * @scenario spawn=unavailable, items=many, item_result=mixed
     *
     * @effects bundled_in_process_fallback_defers_cache_rebuilds, bundled_failed_items_are_named_with_retry_command
     *
     * @return void 반환값 없음
     */
    public function test_without_process_spawn_runs_in_process_with_cache_rebuilds_deferred(): void
    {
        $depths = [];
        $this->mock(ModuleManager::class, function ($mock) use (&$depths): void {
            $mock->shouldReceive('updateModule')->andReturnUsing(function (string $id) use (&$depths): array {
                $depths[$id] = [self::deferDepth(ConfigCacheHelper::class), self::deferDepth(RouteCacheHelper::class)];
                if ($id === 'g9-bad-module') {
                    throw new \RuntimeException('boom');
                }

                return [];
            });
        });

        $this->app[Kernel::class]->registerCommand($this->app->make(NoSpawnExecuteBundledUpdatesCommand::class));

        $path = $this->writeManifest([
            'modules' => [
                ['identifier' => 'g9-ok-module', 'strategy' => 'overwrite'],
                ['identifier' => 'g9-bad-module', 'strategy' => 'keep'],
            ],
            'plugins' => [],
            'templates' => [],
            'lang_packs' => [],
        ]);

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
        $output = Artisan::output();

        $this->assertSame(['g9-ok-module', 'g9-bad-module'], array_keys($depths), '같은 프로세스에서 순서대로 처리');
        foreach ($depths as $id => [$config, $route]) {
            $this->assertGreaterThan(0, $config, "{$id}: config 캐시 재굽기가 끝으로 미뤄져야 한다");
            $this->assertGreaterThan(0, $route, "{$id}: 라우트 캐시 재굽기가 끝으로 미뤄져야 한다");
        }
        $this->assertSame(0, self::deferDepth(ConfigCacheHelper::class), '끝나면 미루기를 푼다');

        $this->assertSame(1, $exit);
        $payload = $this->readPayloadLikePreviousParent($output);
        $this->assertSame([1, 1], [$payload['success'], $payload['failed']]);
        $this->assertSame('g9-bad-module', $payload['failed_items'][0]['identifier']);
        $this->assertStringContainsString('module:update g9-bad-module', $output);
        $this->assertStringContainsString('--layout-strategy=keep', $output, '그 항목의 레이아웃 전략으로 다시 실행하게 안내한다');
    }

    /**
     * 다시 실행 명령은 항목 종류마다 번들 소스를 쓰는 단독 업데이트 명령이다.
     *
     * @scenario spawn=available, items=one, item_result=failed
     *
     * @effects bundled_failed_items_are_named_with_retry_command
     *
     * @return void 반환값 없음
     */
    public function test_retry_command_per_item_type(): void
    {
        $this->assertSame(
            'php artisan module:update acme --source=bundled --force --layout-strategy=keep',
            ExecuteBundledUpdatesCommand::retryCommand(['type' => 'module', 'identifier' => 'acme', 'strategy' => 'keep']),
        );
        $this->assertSame(
            'php artisan template:update sirsoft-basic --source=bundled --force --layout-strategy=overwrite',
            ExecuteBundledUpdatesCommand::retryCommand(['type' => 'template', 'identifier' => 'sirsoft-basic']),
        );
        $this->assertSame(
            'php artisan language-pack:update g7-core-ja --source=bundled',
            ExecuteBundledUpdatesCommand::retryCommand(['type' => 'lang_pack', 'identifier' => 'g7-core-ja']),
        );
    }

    /**
     * 항목 하나짜리 매니페스트(항목 프로세스 자신)와 새 프로세스를 띄울 수 없는 경우는 다시 나누지 않는다.
     *
     * @scenario spawn=unavailable, items=one, item_result=failed
     *
     * @effects bundled_single_item_runs_in_process_without_recursion
     *
     * @return void 반환값 없음
     */
    public function test_isolation_decision(): void
    {
        $two = [['type' => 'module', 'identifier' => 'a'], ['type' => 'plugin', 'identifier' => 'b']];
        $one = [['type' => 'module', 'identifier' => 'a']];

        $this->assertTrue(ExecuteBundledUpdatesCommand::shouldRunEachItemInFreshProcess($two, false, true));
        $this->assertFalse(ExecuteBundledUpdatesCommand::shouldRunEachItemInFreshProcess($one, false, true), '항목 하나는 이 프로세스에서');
        $this->assertFalse(ExecuteBundledUpdatesCommand::shouldRunEachItemInFreshProcess($two, true, true), '항목 프로세스는 다시 나누지 않는다');
        $this->assertFalse(ExecuteBundledUpdatesCommand::shouldRunEachItemInFreshProcess($two, false, false), '띄울 수 없으면 같은 프로세스');
    }

    /**
     * 부모(`core:update`)는 결과 줄 없이 끝난 일괄 업데이트 자식을 실패로 세고, 실패 항목이 화면에 없다는 사실과 종료 코드를 알린다.
     *
     * 자식이 매니페스트를 처리하다 예외로 죽도록(모듈 목록이 배열이 아님) 만든 뒤 실제 새 프로세스로 띄운다.
     *
     * @scenario spawn=available, items=many, item_result=aborted
     *
     * @effects bundled_parent_reports_aborted_child_with_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_parent_reports_child_that_ended_without_result(): void
    {
        $command = $this->app->make(CoreUpdateCommand::class);
        $command->setLaravel($this->app);
        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $result = (new ReflectionMethod($command, 'spawnBundledUpdates'))->invoke($command, ['modules' => 'not-a-list', 'plugins' => [], 'templates' => [], 'lang_packs' => []]);
        $output = $buffer->fetch();

        $this->assertSame(['success' => 0, 'failed' => 1], $result);
        $this->assertStringContainsString(__('extensions.commands.bundled_update.child_aborted', ['code' => 1]), $output, '결과 없이 끝난 자식과 종료 코드를 알린다');
    }

    /**
     * 확장 매니저 update 호출을 기록합니다 — 이 프로세스에서 불리면 기록되고 실패로 끝난다.
     *
     * @param  array<int, string>  $calls  호출 기록
     * @return void 반환값 없음
     */
    private function recordInProcessCalls(array &$calls): void
    {
        $record = function (string $label) use (&$calls): \Closure {
            return function (string $id) use (&$calls, $label): never {
                $calls[] = $label.':'.$id;
                throw new \RuntimeException('in-process call');
            };
        };

        // 템플릿 매니저는 명령 해석 단계에서 다른 서비스가 먼저 쓰므로 바꾸지 않는다 — 모듈·플러그인 두 종류로 판정한다
        $this->mock(ModuleManager::class, fn ($mock) => $mock->shouldReceive('updateModule')->andReturnUsing($record('module')));
        $this->mock(PluginManager::class, fn ($mock) => $mock->shouldReceive('updatePlugin')->andReturnUsing($record('plugin')));
    }

    /**
     * 매니페스트 파일을 씁니다.
     *
     * @param  array<string, mixed>  $manifest  매니페스트
     * @return string 파일 경로
     */
    private function writeManifest(array $manifest): string
    {
        $path = storage_path('app/test_bundled_manifest_'.uniqid().'.json');
        File::put($path, json_encode($manifest));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 이전 판 `core:update`(BundledExtensionUpdatePrompt::spawnBundledUpdates)와 같은 방식으로 결과 줄을 읽습니다.
     *
     * @param  string  $output  자식 출력
     * @return array<string, mixed> 결과 페이로드
     */
    private function readPayloadLikePreviousParent(string $output): array
    {
        $payload = null;
        foreach (explode("\n", $output) as $line) {
            $trimmed = rtrim($line);
            if (str_starts_with($trimmed, ExecuteBundledUpdatesCommand::RESULT_PREFIX)) {
                $decoded = json_decode(substr($trimmed, strlen(ExecuteBundledUpdatesCommand::RESULT_PREFIX)), true);
                $payload = is_array($decoded) ? $decoded : $payload;
            }
        }

        $this->assertIsArray($payload, '결과 줄이 있어야 한다');
        $this->assertIsInt($payload['success'] ?? null, '이전 판 부모가 읽는 success');
        $this->assertIsInt($payload['failed'] ?? null, '이전 판 부모가 읽는 failed');

        return $payload;
    }

    /**
     * 캐시 헬퍼의 미루기 깊이를 읽습니다.
     *
     * @param  class-string  $helper  읽을 헬퍼 클래스 (설정 캐시 헬퍼 또는 라우트 캐시 헬퍼)
     * @return int 미루기 중첩 깊이 (0 이면 미루지 않는 중)
     */
    private static function deferDepth(string $helper): int
    {
        return (int) (new ReflectionProperty($helper, 'deferDepth'))->getValue();
    }
}
