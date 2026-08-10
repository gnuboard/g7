<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsLayoutSortOptionGateParity;

/**
 * 화면 정렬 옵션 ↔ 게이트 허용 집합 회귀 가드 — 이커머스 모듈 (#492 D-19).
 *
 * 실제로 이 모듈의 두 화면이 이 형태로 깨져 있었다.
 *
 *   - 쿠폰 목록 "유효기간 임박순/여유순" (`valid_to`)
 *   - 주문 목록 "최근 발송순/오래된 발송순" (`shipped_at`)
 *
 * 옵션을 고르면 422 가 나고 목록은 직전 결과 그대로 남아, 정렬이 적용된 것처럼 보였다.
 *
 * 코어 스위트가 아니라 **모듈 스위트**에 두는 이유: 모듈 라우트는 모듈이 활성일 때만 등록된다.
 * 코어 스위트에서는 `/api/modules/sirsoft-ecommerce/...` 엔드포인트가 라우트에 닿지 않아
 * 검사가 성립하지 않는다(그 상태로 두면 초록인 채 아무것도 보지 않는다).
 */
class LayoutSortOptionGateParityTest extends ModuleTestCase
{
    use AssertsLayoutSortOptionGateParity;

    /**
     * 이커머스 레이아웃의 정렬 옵션은 게이트 허용 집합의 부분집합이어야 한다.
     */
    public function test_이커머스_화면_정렬옵션은_게이트_허용집합의_부분집합이다(): void
    {
        $this->assertLayoutSortOptionsWithinGate([
            dirname(__DIR__, 3).'/resources/layouts',
        ]);
    }
}
