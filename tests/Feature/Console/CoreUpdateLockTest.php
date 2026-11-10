<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\SimulatesOperationLockStore;
use Tests\TestCase;

/**
 * `core:update` 동시 실행 차단 (#161 S5·D3)
 *
 * 두 곳에서 동시에 돌리면 먼저 끝난 쪽이 유지보수 모드를 풀어 교체 중인 사이트가 열리고 스텝이 두 번 돌았다. 이제 시작하자마자
 * 코어 잠금을 잡고, 다른 코어 업데이트가 진행 중이거나 잠금 저장소가 오류를 내면 유지보수 모드에 들어가기 전에 실패로 끝낸다.
 */
class CoreUpdateLockTest extends TestCase
{
    use SimulatesOperationLockStore;

    /**
     * 시험 전 업데이트 진행 플래그 값.
     *
     * @var array{env: mixed, server: mixed, getenv: string|false}
     */
    private array $originalFlag;

    /**
     * 진행 플래그를 기억합니다 — core:update 가 이 프로세스의 환경 변수에 플래그를 세운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalFlag = [
            'env' => $_ENV['G7_UPDATE_IN_PROGRESS'] ?? null,
            'server' => $_SERVER['G7_UPDATE_IN_PROGRESS'] ?? null,
            'getenv' => getenv('G7_UPDATE_IN_PROGRESS'),
        ];
    }

    /**
     * 진행 플래그·캐시 스토어를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if ($this->originalFlag[$key] === null) {
                unset($GLOBALS[$global]['G7_UPDATE_IN_PROGRESS']);
            } else {
                $GLOBALS[$global]['G7_UPDATE_IN_PROGRESS'] = $this->originalFlag[$key];
            }
        }
        putenv($this->originalFlag['getenv'] === false ? 'G7_UPDATE_IN_PROGRESS' : 'G7_UPDATE_IN_PROGRESS='.$this->originalFlag['getenv']);
        $this->restoreOperationLockStore();

        parent::tearDown();
    }

    /**
     * 다른 코어 업데이트가 잠금을 잡고 있으면 시작하지 않고 실패로 끝낸다 — 유지보수 모드에 들어가지 않는다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects core_update_refused_while_running
     *
     * @return void 반환값 없음
     */
    public function test_refuses_when_core_lock_held(): void
    {
        $held = Cache::lock(ExtensionOperationLock::CORE_KEY, 60);
        $this->assertTrue($held->get());

        try {
            $exit = Artisan::call('core:update', ['--force' => true, '--no-interaction' => true]);
            $output = Artisan::output();
        } finally {
            $held->release();
        }

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString(__('settings.core_update.console.already_running', ['minutes' => 30]), $output);
        $this->assertFalse(app()->isDownForMaintenance(), '거절된 업데이트가 유지보수 모드에 들어갔다');
    }

    /**
     * 잠금 저장소가 오류를 내면 시작하지 않고 실패로 끝낸다 — 원본 오류는 출력하지 않는다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects core_update_refused_while_running
     *
     * @return void 반환값 없음
     */
    public function test_refuses_when_lock_store_down(): void
    {
        $this->breakLockStore();

        $exit = Artisan::call('core:update', ['--force' => true, '--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString(__('settings.core_update.console.lock_unavailable'), $output);
        $this->assertStringNotContainsString('i161', $output);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    /**
     * 거절된 뒤 잠금이 남지 않아 다음 실행을 막지 않는다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects core_update_refused_while_running
     *
     * @return void 반환값 없음
     */
    public function test_lock_is_free_after_refusal(): void
    {
        $held = Cache::lock(ExtensionOperationLock::CORE_KEY, 60);
        $held->get();
        try {
            Artisan::call('core:update', ['--force' => true, '--no-interaction' => true]);
        } finally {
            $held->release();
        }

        $probe = Cache::lock(ExtensionOperationLock::CORE_KEY, 5);
        $this->assertTrue($probe->get(), '거절된 실행이 남의 잠금을 이어받았다');
        $probe->release();
    }

    /**
     * 자식 프로세스를 띄울 수 없어 같은 프로세스에서 번들 일괄 업데이트를 돌 때도, 잠금 저장소 장애를 만나면 첫 항목에서 멈추고
     * 남은 항목 수를 한 번만 알린다 — 자식 명령 경로(core:execute-bundled-updates)와 같은 규칙(#161 D8).
     *
     * @scenario case=bulk_abort
     *
     * @effects bulk_update_in_process_aborts_at_first_lock_store_error
     *
     * @return void 반환값 없음
     */
    public function test_in_process_bulk_update_aborts_at_first_item_when_lock_store_down(): void
    {
        $command = $this->app->make(CoreUpdateCommand::class);
        $command->setLaravel($this->app);
        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $this->breakLockStore();

        $method = new \ReflectionMethod($command, 'executeBulkUpdateInProcess');
        $result = $method->invoke(
            $command,
            $this->app->make(ModuleManager::class),
            $this->app->make(PluginManager::class),
            $this->app->make(TemplateManager::class),
            [
                'modules' => [['identifier' => 'acme-i161-a'], ['identifier' => 'acme-i161-b']],
                'plugins' => [['identifier' => 'acme-i161-c']],
                'templates' => [['identifier' => 'acme-i161-t']],
                'lang_packs' => [['identifier' => 'acme-i161-l']],
            ],
            [],
        );
        $output = $buffer->fetch();

        $this->assertSame(['success' => 0, 'failed' => 1, 'skipped' => 4, 'has_updates' => true], $result, $output);
        $this->assertSame(1, substr_count($output, __('extensions.commands.bundled_update.lock_unavailable_aborted', ['count' => 4])), '중단 안내가 한 번만 나와야 한다: '.$output);
        foreach (['acme-i161-b', 'acme-i161-c', 'acme-i161-t', 'acme-i161-l'] as $later) {
            $this->assertStringNotContainsString($later, $output, "중단 뒤 다음 항목({$later})을 시도했다");
        }
    }
}
