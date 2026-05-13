<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test-4: 확장 업데이트 시 클래스 캐싱 회피 메커니즘(`evalFreshModule` / `evalFreshPlugin`,
 * `reloadModule` / `reloadPlugin`)이 존재하고 올바른 시그니처를 유지함을 보장하는 회귀 테스트.
 *
 * 이 메커니즘은 모듈/플러그인 업데이트 시 진입점 클래스를 eval + 이름 교체 트릭으로 **메모리에 재로드**
 * 하여 getPermissions/getMenus 등이 최신 정의를 반환하게 한다. 본 테스트는 메서드가 제거되거나
 * 시그니처가 변경되는 회귀를 조기에 감지한다.
 *
 * 실제 eval 동작 자체는 모듈 파일을 디스크에 두고 호출해야 하므로 여기서는 "존재/시그니처" 계약만
 * 검증한다. 회귀 시 업데이트 경로 A (확장) 가 조용히 파손될 수 있으므로 중요하다.
 */
class ModuleReloadTest extends TestCase
{
    public function test_module_manager_has_reload_and_evalfresh_methods(): void
    {
        $this->assertTrue(
            method_exists(ModuleManager::class, 'reloadModule'),
            'ModuleManager::reloadModule 메서드가 존재해야 한다'
        );
        $this->assertTrue(
            method_exists(ModuleManager::class, 'evalFreshModule'),
            'ModuleManager::evalFreshModule 메서드가 존재해야 한다'
        );

        $reload = new ReflectionMethod(ModuleManager::class, 'reloadModule');
        $this->assertSame(1, $reload->getNumberOfRequiredParameters(), 'reloadModule 은 moduleName 1개 필수 파라미터');
        $params = $reload->getParameters();
        $this->assertSame('moduleName', $params[0]->getName());

        $eval = new ReflectionMethod(ModuleManager::class, 'evalFreshModule');
        $this->assertSame(
            3,
            $eval->getNumberOfRequiredParameters(),
            'evalFreshModule 은 moduleFile, moduleClass, moduleDir 3개 필수 파라미터'
        );
    }

    public function test_plugin_manager_has_reload_and_evalfresh_methods(): void
    {
        $this->assertTrue(
            method_exists(PluginManager::class, 'reloadPlugin'),
            'PluginManager::reloadPlugin 메서드가 존재해야 한다'
        );
        $this->assertTrue(
            method_exists(PluginManager::class, 'evalFreshPlugin'),
            'PluginManager::evalFreshPlugin 메서드가 존재해야 한다'
        );

        $reload = new ReflectionMethod(PluginManager::class, 'reloadPlugin');
        $this->assertSame(1, $reload->getNumberOfRequiredParameters(), 'reloadPlugin 은 pluginName 1개 필수 파라미터');
    }
}
