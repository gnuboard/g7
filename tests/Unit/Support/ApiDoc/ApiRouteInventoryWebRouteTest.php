<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiRouteInventory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApiRouteInventory 확장 web 라우트 수집 단위 테스트.
 *
 * PG 콜백·웹훅은 외부 시스템(PG사 서버/브라우저 리다이렉트)이 호출하는 machine-facing
 * 엔드포인트라 API 레퍼런스 대상이다. 그러나 이들은 CSRF·세션 특성상 `api.php` 가 아니라
 * `web.php` 에 등록되므로(`/plugins/{id}/...`, name `web.plugins.{id}.*`), `api/` prefix
 * 만 수집하던 기존 규칙에서는 영구히 무문서 상태였다.
 *
 * 확장 소유가 확정되는 web 라우트(`{modules|plugins}/{vendor-id}/...`)를 수집 대상에
 * 포함하는지 검증한다.
 */
class ApiRouteInventoryWebRouteTest extends TestCase
{
    #[Test]
    public function 플러그인_웹훅_web_라우트가_수집된다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('plugin:sirsoft-tosspayments');

        $names = array_column($routes, 'name');

        $this->assertContains('web.plugins.sirsoft-tosspayments.webhook.deposit', $names);
        $this->assertContains('web.plugins.sirsoft-tosspayments.webhook.payment-status', $names);
    }

    #[Test]
    public function 플러그인_결제_콜백_web_라우트가_수집된다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('plugin:sirsoft-tosspayments');

        $names = array_column($routes, 'name');

        $this->assertContains('web.plugins.sirsoft-tosspayments.payment.success', $names);
        $this->assertContains('web.plugins.sirsoft-tosspayments.payment.fail', $names);
    }

    #[Test]
    public function 수집된_web_라우트는_확장_소유와_uri_를_정확히_보존한다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('plugin:sirsoft-tosspayments');

        $deposit = collect($routes)->firstWhere('name', 'web.plugins.sirsoft-tosspayments.webhook.deposit');

        $this->assertNotNull($deposit);
        $this->assertSame('POST', $deposit['method']);
        $this->assertSame('/plugins/sirsoft-tosspayments/webhook/deposit', $deposit['uri']);
        $this->assertSame('plugin', $deposit['owner']['type']);
        $this->assertSame('sirsoft-tosspayments', $deposit['owner']['id']);
        $this->assertSame(
            'Plugins\\Sirsoft\\Tosspayments\\Controllers\\WebhookController',
            $deposit['controller']
        );
    }

    #[Test]
    public function web_라우트도_도메인_그룹으로_분류된다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('plugin:sirsoft-tosspayments');

        $byName = collect($routes)->keyBy('name');

        // web.plugins.{id}. 를 걷어낸 첫 세그먼트가 도메인이다.
        $this->assertSame('webhook', $byName['web.plugins.sirsoft-tosspayments.webhook.deposit']['domain_group']);
        $this->assertSame('payment', $byName['web.plugins.sirsoft-tosspayments.payment.success']['domain_group']);
    }

    #[Test]
    public function 코어_web_라우트는_수집되지_않는다(): void
    {
        // 확장 소유가 확정되지 않는 web 라우트(코어 화면/블레이드 등)는 API 문서 대상이 아니다.
        $routes = app(ApiRouteInventory::class)->collect('core');

        foreach ($routes as $route) {
            $this->assertStringStartsWith(
                '/api/',
                $route['uri'],
                "코어 범위에는 api/ 라우트만 수집되어야 한다: {$route['uri']}"
            );
        }
    }

    #[Test]
    public function 확장의_관리자_화면_web_라우트는_수집되지_않는다(): void
    {
        // hello_module 의 admin CRUD 는 사람이 브라우저로 여는 화면(web.modules.{id}.admin.*)이라
        // machine-facing 엔드포인트가 아니므로 API 레퍼런스 대상이 아니다.
        $routes = app(ApiRouteInventory::class)->collect('module:gnuboard7-hello_module');

        $names = array_column($routes, 'name');

        $this->assertNotContains('web.modules.gnuboard7-hello_module.admin.memos.index', $names);
        $this->assertNotContains('web.modules.gnuboard7-hello_module.admin.memos.store', $names);

        foreach ($routes as $route) {
            $this->assertStringNotContainsString(
                '/admin/',
                $route['uri'],
                "확장 관리자 화면 web 라우트가 수집되면 안 된다: {$route['uri']}"
            );
        }
    }

    #[Test]
    public function 타_플러그인_web_라우트는_범위에_섞이지_않는다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('plugin:sirsoft-tosspayments');

        foreach ($routes as $route) {
            $this->assertSame('sirsoft-tosspayments', $route['owner']['id']);
        }

        // KG 도 web 웹훅을 갖지만 토스 범위에는 포함되지 않는다.
        $names = array_column($routes, 'name');
        $this->assertNotContains('web.plugins.sirsoft-pay_kginicis.payment.vbank-notify', $names);
    }
}
