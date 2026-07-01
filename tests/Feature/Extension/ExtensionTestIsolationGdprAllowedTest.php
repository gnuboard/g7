<?php

namespace Tests\Feature\Extension;

use App\Extension\Testing\ExtensionTestAllowlist;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Providers\GdprServiceProvider;
use Tests\TestCase;

/**
 * 테스트 환경 확장 격리 회귀 테스트 — allowlist 포함 시나리오
 *
 * requiredExtensions 에 GDPR 플러그인을 명시하면 해당 플러그인의
 * ServiceProvider 와 미들웨어가 정상적으로 로드되어야 한다.
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
    public function test_allowlisted_plugin_middleware_is_registered_in_web_group(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);

        $groups = $kernel->getMiddlewareGroups();

        $this->assertContains(
            CookieConsentMiddleware::class,
            $groups['web'] ?? [],
            'allowlist 에 GDPR 을 명시했으나 web 그룹에 CookieConsentMiddleware 가 없습니다'
        );
    }
}
