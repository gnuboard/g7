<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Providers;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use Modules\Sirsoft\Ecommerce\Http\Middleware\DetectDevice;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * DetectDevice 미들웨어가 코어 self-gate 선언 방식으로 등록·실행되는지 검증.
 *
 * 이관 전 회귀: SP 가 Router::pushMiddlewareToGroup 으로 등록하면 HTTP Kernel 에 반영되지 않아
 * SSR 셸 요청에서 미실행 → appConfig.isIos 항상 false. 이제는 Module::getMiddleware() 가
 * DetectDevice 를 targets=['/'](무명 User SSR 셸 URI 패턴)로 선언하고 코어 게이트가 실행한다.
 *
 * 본 테스트는 (1) 선언 계약이 올바른지, (2) registry 가 루트 URI(web/after_core)에서 DetectDevice
 * 를 매칭하는지를 검증한다. 미들웨어 자체 동작(isIos 판정)은 DetectDeviceTest 가 커버한다.
 */
class DetectDeviceMiddlewareRegistrationTest extends ModuleTestCase
{
    /**
     * Module::getMiddleware() 가 DetectDevice 를 web 그룹으로 선언하는지 확인합니다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     *
     * @effects server_ua_sets_is_ios, detect_device_declared_and_resolved_for_root_uri_web_only
     *
     * @return void 반환값 없음
     */
    public function test_detect_device_is_declared_via_get_middleware(): void
    {
        $module = new Module;

        $declarations = $module->getMiddleware();

        $detect = collect($declarations)->firstWhere('class', DetectDevice::class);

        $this->assertNotNull($detect, 'Module::getMiddleware() 가 DetectDevice 를 선언해야 합니다.');
        $this->assertSame(['web'], $detect['groups'], 'DetectDevice 는 web 그룹 선언이어야 합니다.');
        $this->assertNotEmpty($detect['targets'], 'DetectDevice 는 타게팅 대상을 선언해야 합니다.');
    }

    /**
     * 코어 게이트가 루트 주소(무명 라우트) web 요청에서 DetectDevice 를 매칭하는지 확인합니다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     *
     * @effects server_ua_sets_is_ios
     *
     * @return void 반환값 없음
     */
    public function test_gate_resolves_detect_device_for_root_uri(): void
    {
        ExtensionMiddlewareRegistry::flush();
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        // 무명 SSR 셸 catch-all: routeName='' + path '/' → web/after_core 에서 DetectDevice 매칭.
        $matched = $registry->resolveForRoute('', '/', 'web', 'after_core');

        $this->assertContains(
            DetectDevice::class,
            $matched,
            '코어 게이트가 루트 URI(무명 라우트) 요청에서 DetectDevice 를 매칭해야 SSR 셸에서 실행된다.'
        );

        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * api 그룹 요청에서는 DetectDevice 를 매칭하지 않는지 확인합니다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     *
     * @effects server_ua_sets_is_ios
     *
     * @return void 반환값 없음
     */
    public function test_gate_does_not_match_detect_device_for_api_group(): void
    {
        ExtensionMiddlewareRegistry::flush();
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        // DetectDevice 는 web 그룹만 선언 → api 그룹 조회에서는 매칭되지 않는다.
        $matched = $registry->resolveForRoute('', '/', 'api', 'after_core');

        $this->assertNotContains(DetectDevice::class, $matched);

        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * 루트가 아닌 주소(주문서·마이페이지)로 바로 들어온 web 요청에서도 DetectDevice 가 돈다 — 종전 targets=['/'] 는
     * `Str::is('/', '/shop/checkout')` 가 거짓이라 루트 주소에서만 iOS 를 판정했다(7.2.1 실측 W3b-003).
     *
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     *
     * @effects server_ua_sets_is_ios
     *
     * @return void 반환값 없음
     */
    public function test_gate_resolves_detect_device_for_non_root_web_paths(): void
    {
        ExtensionMiddlewareRegistry::flush();
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        foreach (['/shop/checkout', '/mypage/orders/1', '/board/notice'] as $path) {
            $this->assertContains(
                DetectDevice::class,
                $registry->resolveForRoute('', $path, 'web', 'after_core'),
                "{$path} 로 바로 들어온 요청에서도 iOS 판정이 돌아야 합니다."
            );
        }

        ExtensionMiddlewareRegistry::flush();
    }
}
