<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\PluginManager;
use App\Extension\Testing\ExtensionTestAllowlist;
use App\Http\Middleware\ExtensionMiddlewareGate;
use App\Models\Plugin;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Providers\GdprServiceProvider;
use Tests\TestCase;

/**
 * 테스트 환경 확장 격리 회귀 테스트 — allowlist 포함 시나리오
 *
 * requiredExtensions 에 GDPR 플러그인을 명시하면 해당 플러그인의 ServiceProvider 가 로드되고,
 * 코어 self-gate 게이트가 GDPR 이 getMiddleware() 로 선언한 CookieConsentMiddleware 를 실행한다.
 * (이관 전: SP boot 이 web/api 그룹에 직접 prepend. 이관 후: getMiddleware() 선언 + 게이트 실행)
 */
class ExtensionTestIsolationGdprAllowedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * GDPR 플러그인을 allowlist 에 명시.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * @effects allowlisted_plugin_service_provider_is_registered
     */
    public function test_allowlisted_plugin_service_provider_is_registered(): void
    {
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));

        $this->assertTrue(
            $this->app->providerIsLoaded(GdprServiceProvider::class),
            'allowlist 에 명시된 GdprServiceProvider 가 등록되지 않았습니다'
        );
    }

    /**
     * @effects allowlisted_plugin_middleware_is_registered_in_web_group
     */
    public function test_self_gate_wrapper_is_registered_in_web_and_api_groups(): void
    {
        // 코어 게이트 래퍼는 항상 web/api 그룹에 등록된다 (확장 미들웨어를 요청 시점에 실행).
        $kernel = $this->app->make(HttpKernelContract::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);

        $groups = $kernel->getMiddlewareGroups();

        foreach (['web', 'api'] as $group) {
            $hasGate = collect($groups[$group] ?? [])
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, ExtensionMiddlewareGate::class));
            $this->assertTrue($hasGate, "{$group} 그룹에 ExtensionMiddlewareGate 래퍼가 등록되어야 합니다.");
        }
    }

    /**
     * @effects allowlisted_plugin_middleware_is_registered_in_web_group
     */
    public function test_gate_resolves_gdpr_cookie_consent_when_active(): void
    {
        // GDPR 을 활성 상태로 등록하면, 게이트가 CookieConsentMiddleware 를
        // 모든 요청(everything/before_core)에서 매칭한다 (EDPB §16 광역 타게팅).
        $this->activateGdprForGate();

        ExtensionMiddlewareRegistry::flush();
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        foreach (['web', 'api'] as $group) {
            $matched = $registry->resolveForRoute('any.route.name', 'any/path', $group, 'before_core');
            $this->assertContains(
                CookieConsentMiddleware::class,
                $matched,
                "GDPR 활성 시 {$group}/before_core 에서 CookieConsentMiddleware 가 매칭되어야 합니다.",
            );
        }

        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * GDPR 플러그인을 활성 상태로 등록해 게이트가 그 미들웨어를 수집하도록 합니다.
     */
    private function activateGdprForGate(): void
    {
        Plugin::query()->updateOrCreate(
            ['identifier' => 'sirsoft-gdpr'],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => 'GDPR', 'en' => 'GDPR']),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ]
        );
        PluginManager::invalidatePluginStatusCache();

        $pluginManager = $this->app->make(PluginManager::class);
        $property = new \ReflectionProperty($pluginManager, 'plugins');
        $property->setAccessible(true);
        $plugins = $property->getValue($pluginManager);
        $plugins['sirsoft-gdpr'] = new \Plugins\Sirsoft\Gdpr\Plugin;
        $property->setValue($pluginManager, $plugins);
    }
}
