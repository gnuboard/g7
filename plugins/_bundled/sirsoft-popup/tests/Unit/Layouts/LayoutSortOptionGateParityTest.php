<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Layouts;

use Plugins\Sirsoft\Popup\Tests\PluginTestCase;
use Tests\Support\Concerns\AssertsLayoutSortOptionGateParity;

/**
 * 화면 정렬 옵션 ↔ 게이트 허용 집합 회귀 가드 — 팝업 플러그인.
 *
 * 화면이 내놓는 정렬 옵션을 서버 게이트가 거절하면 그 옵션을 고를 때 422 가 나고 목록은 직전 결과 그대로 남아
 * 정렬이 적용된 것처럼 보인다.
 *
 * 코어 스위트가 아니라 **플러그인 스위트**에 두는 이유: 이 플러그인의 라우트는 플러그인 시험 기반이 등록한다.
 * 코어 스위트에서는 `/api/plugins/sirsoft-popup/...` 엔드포인트가 라우트에 닿지 않아 검사가 성립하지 않는다.
 */
class LayoutSortOptionGateParityTest extends PluginTestCase
{
    use AssertsLayoutSortOptionGateParity;

    /**
     * 팝업 레이아웃의 정렬 옵션은 게이트 허용 집합의 부분집합이어야 한다.
     *
     * @scenario surface=popup_list
     *
     * @effects sort_options_within_gate
     *
     * @return void 반환값 없음
     */
    public function test_팝업_화면_정렬옵션은_게이트_허용집합의_부분집합이다(): void
    {
        $this->assertLayoutSortOptionsWithinGate([
            dirname(__DIR__, 3).'/resources/layouts',
        ]);
    }
}
