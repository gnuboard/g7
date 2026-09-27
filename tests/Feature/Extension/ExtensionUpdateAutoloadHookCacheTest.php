<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\Extension\UpdateAutoloadCommand;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Mockery;
use Tests\TestCase;

/**
 * 확장 업데이트 직후 훅 캐시 복구 경로 계약.
 *
 * [case:backend-42]
 *
 * 확장을 `--force` 로 반영한 프로세스는 부팅 시점에 그 확장의 **구버전** `module.php`/
 * `plugin.php` 클래스를 이미 로드해 두었고 PHP 는 같은 프로세스에서 클래스를 다시 정의할 수
 * 없다. 그래서 그 프로세스가 훅 캐시를 재생성하면 신규 리스너가 캐시에서 빠지고, 예외도 경고도
 * 남지 않은 채 그 리스너만 웹 요청에서 무반응이 된다. 근본 수정(재생성의 자식 프로세스 위임)이
 * 미착수인 동안 표준 복구 수단은 **별도 프로세스**인 `extension:update-autoload` 다.
 *
 * 그 수단이 성립하려면 이 커맨드가 오토로드 파일 생성 → 런타임 재등록 → 확장 적재 →
 * 훅 캐시 재생성을 **그 순서로** 수행해야 한다. 한 단계라도 빠지면 커맨드는 성공을 보고하면서
 * 복구를 하지 않는다 — 그 상태는 "복구했는데 여전히 무반응" 과 구분되지 않는다.
 *
 * 실 캐시 파일을 건드리지 않도록 협력자를 전부 목으로 대체한다. testing DB 의 활성 확장 구성은
 * 개발 DB 와 다르므로, 실제 생성을 태우면 개발 환경 매핑이 testing 기준으로 교체된다.
 */
class ExtensionUpdateAutoloadHookCacheTest extends TestCase
{
    /**
     * 커맨드가 훅 캐시 재생성까지 수행하고, 그 앞에 오토로드 재등록·확장 적재를 마친다.
     *
     * @scenario cache_state=present_valid, registration_source=core, listener_type=action_sync, regeneration_trigger=core_update
     *
     * @effects update_autoload_command_regenerates_hook_cache_after_reregistering_autoload
     *
     * @return void 반환값 없음
     */
    public function test_update_autoload_regenerates_hook_cache_after_reregistering_autoload(): void
    {
        $calls = [];

        $extensionManager = Mockery::mock(ExtensionManager::class);
        $extensionManager->shouldReceive('generateAutoloadFile')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'generateAutoloadFile';
            });
        $extensionManager->shouldReceive('reregisterRuntimeAutoload')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'reregisterRuntimeAutoload';
            });

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('loadModules')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'loadModules';
            });

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('loadPlugins')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'loadPlugins';
            });

        $hookCacheManager = Mockery::mock(HookCacheManager::class);
        $hookCacheManager->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'generate';

                return [];
            });

        $this->app->instance(ExtensionManager::class, $extensionManager);
        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);
        $this->app->instance(HookCacheManager::class, $hookCacheManager);

        $this->artisan('extension:update-autoload')->assertSuccessful();

        // 훅 캐시 재생성이 목록의 마지막이어야 한다 — 재등록·적재보다 먼저 돌면 그 캐시는
        // 다시 구버전 매핑으로 채워진다(사례 32 의 원인 그 자체).
        $this->assertSame(
            ['generateAutoloadFile', 'reregisterRuntimeAutoload', 'loadModules', 'loadPlugins', 'generate'],
            $calls,
        );
    }

    /**
     * 커맨드 서명이 인수 없이 실행 가능해야 한다 — 사례 32 의 복구 안내가 그 형태다.
     *
     * @scenario cache_state=present_valid, registration_source=core, listener_type=action_sync, regeneration_trigger=core_update
     *
     * @effects update_autoload_command_runs_without_required_arguments
     *
     * @return void 반환값 없음
     */
    public function test_command_signature_takes_no_required_arguments(): void
    {
        $command = new UpdateAutoloadCommand;

        $this->assertSame('extension:update-autoload', $command->getName());
        $this->assertEmpty(
            array_filter(
                $command->getDefinition()->getArguments(),
                fn ($argument) => $argument->isRequired(),
            ),
        );
    }
}
