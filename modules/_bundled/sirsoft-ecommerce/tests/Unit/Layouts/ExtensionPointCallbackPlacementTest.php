<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsExtensionPointCallbackPlacement;

/**
 * `extension_point` 의 콜백 액션은 `props` 가 아니라 `callbacks` 에 있어야 한다 — 모듈 레이아웃.
 *
 * 코어 스위트(`Tests\Unit\Layouts\ExtensionPointCallbackPlacementTest`)는 `resources/layouts`
 * ·`templates/_bundled`·`plugins/_bundled` 만 훑는다. 모듈 레이아웃은 그 모집단 밖이라 같은
 * 트레이트로 여기서 본다.
 *
 * 엔진은 확장점의 두 자리를 다르게 다룬다 (`DynamicRenderer.tsx:1882-1897`).
 * `props` 는 표현식을 평가해 `extensionPointProps` 로, `callbacks` 는 평가 없이
 * `extensionPointCallbacks` 로 전달한다. 확장 컴포넌트가 읽는 자리는 후자다.
 *
 * 액션이 `props` 에 있으면 증상이 조용하다 — 확장점 버튼은 정상으로 보이고 자기 일까지 하는데
 * (우편번호 레이어가 열리고 주소도 고를 수 있다) 고른 값만 폼에 돌아오지 않는다.
 *
 * 2026-09-15 #599 세션3 실측에서 관리자 주문상세 배송지 변경의
 * `order_detail_address_search_slot` 이 이 형태였다.
 */
class ExtensionPointCallbackPlacementTest extends ModuleTestCase
{
    use AssertsExtensionPointCallbackPlacement;

    /**
     * 이커머스 모듈 레이아웃의 확장점 props 에는 액션 객체가 없어야 한다.
     *
     * @scenario scope=ecommerce_module_layouts
     *
     * @effects extension_point_props_carry_no_action_objects
     */
    public function test_확장점_콜백_액션은_props_가_아니라_callbacks_에_있다(): void
    {
        $this->assertExtensionPointCallbacksNotInProps([
            dirname(__DIR__, 3).'/resources/layouts',
        ]);
    }
}
