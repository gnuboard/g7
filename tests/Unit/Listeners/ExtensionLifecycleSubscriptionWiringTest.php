<?php

namespace Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Listeners\Dashboard\DashboardModuleListener;
use App\Listeners\Dashboard\DashboardStatsListener;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use Mockery;
use Tests\TestCase;

/**
 * 확장 생명주기 훅 구독 이름 교정 (#138)
 *
 * 구독한 훅 이름이 실제 발화 이름과 한 글자만 달라도 리스너는 예외 없이 "그냥 안 돈다".
 * 대시보드 리스너 2개는 단수형(core.module.*)을 구독해 한 번도 실행된 적이 없었고,
 * 템플릿 언어팩 동기화는 레코드 수정 훅(after_update)을 구독해 버전 업데이트에 반응하지 않았다.
 */
class ExtensionLifecycleSubscriptionWiringTest extends TestCase
{
    /**
     * 훅 레지스트리를 원복합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * 대시보드 리스너는 실제 발화되는 복수형 훅 이름을 구독한다.
     *
     * @effects dashboard_listeners_subscribe_plural_hook_names
     *
     * @return void 반환값 없음
     */
    public function test_dashboard_listeners_subscribe_plural_hook_names(): void
    {
        $module = DashboardModuleListener::getSubscribedHooks();
        $this->assertArrayHasKey('core.modules.after_activate', $module);
        $this->assertArrayHasKey('core.modules.after_deactivate', $module);

        $stats = DashboardStatsListener::getSubscribedHooks();
        $this->assertArrayHasKey('core.plugins.after_activate', $stats);
        $this->assertArrayHasKey('core.plugins.after_deactivate', $stats);

        foreach ([...array_keys($module), ...array_keys($stats)] as $hook) {
            $this->assertDoesNotMatchRegularExpression('/^core\.(module|plugin|template)\./', $hook, "{$hook} 는 발화처가 없는 단수형이다");
        }
    }

    /**
     * 템플릿 버전 업데이트(after_version_update)에서 언어팩이 실제 버전으로 동기화되고,
     * 레코드 수정 훅(after_update)에는 더 이상 반응하지 않는다.
     *
     * @effects template_language_pack_syncs_on_version_update_with_real_version
     *
     * @return void 반환값 없음
     */
    public function test_template_language_pack_syncs_on_version_update_with_real_version(): void
    {
        $registrar = Mockery::mock(LanguagePackBundledRegistrar::class);
        $registrar->shouldReceive('syncFromExtension')
            ->once()
            ->with('template', 'acme-sample_admin', 'acme', '2.3.4', Mockery::type('string'));
        $this->app->instance(LanguagePackBundledRegistrar::class, $registrar);

        HookManager::resetAll();
        $provider = $this->app->getProvider(LanguagePackServiceProvider::class);
        (new \ReflectionMethod($provider, 'registerBundledRegistrarHooks'))->invoke($provider);

        // 레코드 수정 — 동기화 대상 아님 (종전에는 여기서 version 없는 $data 로 '1.0.0' 폴백 동기화)
        HookManager::doAction('core.templates.after_update', (object) ['identifier' => 'acme-sample_admin'], ['name' => 'x']);

        // 버전 업데이트 — 정보 배열의 실제 버전으로 동기화
        HookManager::doAction(
            'core.templates.after_version_update',
            'acme-sample_admin',
            ['success' => true, 'from_version' => '2.3.3', 'to_version' => '2.3.4'],
            ['identifier' => 'acme-sample_admin', 'vendor' => 'acme', 'version' => '2.3.4'],
        );
    }
}
