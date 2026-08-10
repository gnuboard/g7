<?php

namespace Tests\Unit\Layouts;

use Tests\Support\Concerns\AssertsLayoutSortOptionGateParity;
use Tests\TestCase;

/**
 * 화면 정렬 옵션 ↔ 게이트 허용 집합 회귀 가드 — 코어/템플릿/플러그인 (#492 D-19).
 *
 * 정렬 제한은 세 계층으로 겹쳐 있다.
 *
 *   화면 정렬 옵션 ⊆ FormRequest 게이트 ⊆ Repository 화이트리스트
 *
 * 아래 두 계층은 `SortWhitelistGateParityTest` 가 본다. 이 테스트는 **맨 위 계층**을 본다.
 *
 * 레이아웃의 정렬 셀렉트가 게이트에 없는 컬럼을 제공하면, 그 옵션을 고르는 순간 422 가 나고
 * 목록은 직전 결과 그대로 남는다. 셀렉트 라벨만 새 값으로 바뀌므로 운영자는 정렬이 적용된
 * 것으로 읽는다 — 토스트도 뜨지 않는다.
 *
 * 모듈 레이아웃은 이 스위트에서 검사하지 않는다. 모듈 라우트는 활성 모듈일 때만 등록되므로
 * 코어 스위트에서는 엔드포인트가 라우트에 닿지 않는다. 각 모듈은 자기 스위트에서 같은 트레이트로
 * 검사한다 (예: `Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts\LayoutSortOptionGateParityTest`).
 */
class LayoutSortOptionGateParityTest extends TestCase
{
    use AssertsLayoutSortOptionGateParity;

    /**
     * 코어·템플릿·플러그인 레이아웃의 정렬 옵션은 게이트 허용 집합의 부분집합이어야 한다.
     */
    public function test_화면_정렬옵션은_게이트_허용집합의_부분집합이다(): void
    {
        $this->assertLayoutSortOptionsWithinGate([
            base_path('resources/layouts'),
            base_path('templates/_bundled'),
            base_path('plugins/_bundled'),
        ]);
    }
}
