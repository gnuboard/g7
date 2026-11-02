<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Http\View\Composers\Traits\CollectsActiveExtensionMeta;
use Mockery;
use Tests\TestCase;

/**
 * 활성 확장 목록 수집 실패는 「모름」(null)이다 — 「모두 꺼짐」(빈 배열)이 아니다 (#822)
 *
 * 브라우저는 `G7Config.activeModules` 가 배열일 때만 `_global.activeModules` 를 싣는다. 수집 실패를 빈 배열로 내면
 * `{{_global.activeModules?.includes('id') ?? true}}` 가드가 모든 모듈 화면을 숨기지만, 검색봇 화면(SeoRenderer)은
 * 실패 시 키를 생략해 「모두 켜짐」으로 본다. 두 쪽이 같은 답을 내도록 수집 실패는 null 로 낸다.
 */
class ActiveExtensionMetaUnknownOnFailureTest extends TestCase
{
    /**
     * 정상 수집은 배열, 수집 중 예외는 null 이다.
     *
     * @scenario case=active_meta_unknown_on_failure
     *
     * @effects active_extension_meta_failure_is_unknown
     *
     * @return void 반환값 없음
     */
    public function test_collection_failure_yields_null_not_empty_list(): void
    {
        $failingModules = Mockery::mock(ModuleManager::class);
        $failingModules->shouldReceive('getActiveModules')->andThrow(new \RuntimeException('목록을 읽지 못함'));
        $emptyPlugins = Mockery::mock(PluginManager::class);
        $emptyPlugins->shouldReceive('getActivePlugins')->andReturn([]);

        $harness = new class($failingModules, $emptyPlugins)
        {
            use CollectsActiveExtensionMeta {
                collectActiveModulesMeta as public;
                collectActivePluginsMeta as public;
            }

            /**
             * 수집 trait 이 읽는 두 매니저를 주입받습니다.
             *
             * @param  ModuleManager  $moduleManager  모듈 매니저
             * @param  PluginManager  $pluginManager  플러그인 매니저
             */
            public function __construct(public ModuleManager $moduleManager, public PluginManager $pluginManager) {}
        };

        $this->assertNull($harness->collectActiveModulesMeta(), '수집 실패는 「모름」이어야 합니다');
        $this->assertSame([], $harness->collectActivePluginsMeta(), '켜진 플러그인이 없으면 빈 배열입니다');
    }
}
