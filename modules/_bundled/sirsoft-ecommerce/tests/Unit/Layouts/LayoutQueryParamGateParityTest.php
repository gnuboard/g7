<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsLayoutQueryParamGateParity;

/**
 * 화면 조회 조건 ↔ 서버 게이트 키 회귀 가드 — 이커머스 모듈.
 *
 * 코어 스위트가 아니라 **모듈 스위트**에 두는 이유: 모듈 라우트는 모듈이 활성일 때만 등록된다.
 * 코어 스위트에서는 `/api/modules/sirsoft-ecommerce/...` 엔드포인트가 라우트에 닿지 않아
 * 검사 A 가 성립하지 않는다.
 */
class LayoutQueryParamGateParityTest extends ModuleTestCase
{
    use AssertsLayoutQueryParamGateParity;

    /**
     * 검사 대상 레이아웃 루트를 반환합니다.
     *
     * @return array<int, string> 레이아웃 탐색 루트
     */
    private function roots(): array
    {
        return [dirname(__DIR__, 3).'/resources/layouts'];
    }

    /**
     * 검사 A 예외 — 게이트 밖이지만 버려져도 동작이 같은 키.
     *
     * @return array<string, array<string, string>> `상대경로 => [키 => 사유]`
     */
    private function dataSourceParamAllowlist(): array
    {
        $admin = 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin';
        $brandsAll = '브랜드 목록 API 는 페이지를 나누지 않고 전부 돌려준다(BrandService::getAllBrands) — per_page 는 버려져도 결과가 같다';

        return [
            $admin.'/admin_ecommerce_product_form.json' => ['per_page' => $brandsAll],
            $admin.'/admin_ecommerce_product_list.json' => ['per_page' => $brandsAll],
        ];
    }

    /**
     * 검사 B 예외 — URL 에 싣지만 서버로 보내지 않는 화면 표시 상태 키.
     *
     * @return array<string, array<string, string>> `부모 레이아웃 상대경로 => [키 => 사유]`
     */
    private function navigateQueryAllowlist(): array
    {
        $admin = 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin';
        $preset = '선택한 검색 프리셋 표시용 — 조건 자체는 같은 navigate 의 다른 키로 실린다';

        return [
            $admin.'/admin_ecommerce_claim_list.json' => ['preset_id' => $preset],
            $admin.'/admin_ecommerce_order_list.json' => ['preset_id' => $preset],
            $admin.'/admin_ecommerce_product_list.json' => [
                'preset_id' => $preset,
                'category_id' => '프리셋 적용 시 함께 싣는 중복 키 — data_source 는 category_id1~4 에서 같은 값을 계산한다',
            ],
        ];
    }

    /**
     * 이커머스 레이아웃의 data_source 쿼리 키는 게이트 규칙 키의 부분집합이어야 한다 (검사 A).
     *
     * @scenario check=data_source_params_within_gate
     *
     * @effects dropped_query_key_detected
     *
     * @return void 반환값 없음
     */
    public function test_이커머스_데이터소스_쿼리키는_게이트_규칙키의_부분집합이다(): void
    {
        $this->assertDataSourceParamsWithinGate($this->roots(), $this->dataSourceParamAllowlist());
    }

    /**
     * 이커머스 화면의 자기 경로 navigate query 키는 같은 화면의 data_source 가 읽어야 한다 (검사 B).
     *
     * @scenario check=navigate_query_consumed
     *
     * @effects unconsumed_navigate_key_detected
     *
     * @return void 반환값 없음
     */
    public function test_이커머스_자기경로_navigate_쿼리키는_데이터소스가_읽는다(): void
    {
        $this->assertNavigateQueryKeysConsumed($this->roots(), $this->navigateQueryAllowlist());
    }
}
