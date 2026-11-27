<?php

namespace Tests\Unit\Layouts;

use Tests\Support\Concerns\AssertsLayoutQueryParamGateParity;
use Tests\TestCase;

/**
 * 화면 조회 조건 ↔ 서버 게이트 키 회귀 가드 — 코어/템플릿/플러그인.
 *
 * 목록 화면의 검색·필터 키가 navigate query → data_source params → FormRequest rules() 중
 * 한 단계에서 어긋나면 그 조건은 조용히 버려진다. 요청은 200 으로 끝나고 목록은 조건 없이
 * 그대로 나오므로 운영자는 검색이 된 것으로 읽는다.
 *
 * 모듈 레이아웃은 이 스위트에서 검사하지 않는다. 모듈 라우트는 활성 모듈일 때만 등록되므로
 * 코어 스위트에서는 엔드포인트가 라우트에 닿지 않는다. 각 모듈은 자기 스위트에서 같은 트레이트로
 * 검사한다 (예: `Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts\LayoutQueryParamGateParityTest`).
 */
class LayoutQueryParamGateParityTest extends TestCase
{
    use AssertsLayoutQueryParamGateParity;

    /**
     * 검사 대상 레이아웃 루트를 반환합니다.
     *
     * @return array<int, string> 레이아웃 탐색 루트
     */
    private function roots(): array
    {
        return [
            base_path('resources/layouts'),
            base_path('templates/_bundled'),
            base_path('plugins/_bundled'),
        ];
    }

    /**
     * 검사 A 예외 — 게이트 밖이지만 서버가 실제로 읽거나, 버려져도 동작이 같은 키.
     *
     * @return array<string, array<string, string>> `상대경로 => [키 => 사유]`
     */
    private function dataSourceParamAllowlist(): array
    {
        $admin = 'templates/_bundled/sirsoft-admin_basic/layouts';
        $seriesLabel = '추이(series) 엔드포인트 전용 — ReportController 가 input(label) 으로 직접 읽는다';
        $deadMenuParams = '서버(MenuController@index)가 항상 계층 구조로 응답한다 — 버려져도 결과가 같은 죽은 param';

        return [
            $admin.'/admin_analytics_actions.json' => ['label' => $seriesLabel],
            $admin.'/admin_analytics_bots.json' => ['label' => $seriesLabel],
            $admin.'/admin_analytics_environment.json' => ['label' => $seriesLabel],
            $admin.'/admin_analytics_pages.json' => ['label' => $seriesLabel],
            $admin.'/admin_analytics_referrers.json' => ['label' => $seriesLabel],
            $admin.'/admin_analytics_regions.json' => ['label' => $seriesLabel],
            $admin.'/admin_menu_list.json' => ['with_children' => $deadMenuParams, 'hierarchical' => $deadMenuParams],
            $admin.'/admin_role_form.json' => ['with_children' => $deadMenuParams, 'hierarchical' => $deadMenuParams],
            'templates/_bundled/sirsoft-basic/layouts/search/index.json' => [
                'board_slug' => '게시판 모듈이 core.search 검증 규칙 훅으로 더한다 — 코어 스위트에서는 모듈 비활성이라 보이지 않는다',
                'exclude_sold_out' => '쇼핑몰 모듈이 core.search 검증 규칙 훅으로 더한다(SearchProductsListener) — 코어 스위트에서는 모듈 비활성이라 보이지 않는다',
            ],
        ];
    }

    /**
     * 검사 B 예외 — URL 에 싣지만 서버로 보내지 않는 화면 표시 상태 키.
     *
     * @return array<string, array<string, string>> `부모 레이아웃 상대경로 => [키 => 사유]`
     */
    private function navigateQueryAllowlist(): array
    {
        $admin = 'templates/_bundled/sirsoft-admin_basic/layouts';
        $chartUnit = '차트 집계 단위(일/주/월) 토글 — 응답 데이터를 화면에서만 다시 묶는다(서버 요청 불필요)';
        $legacySingle = '구 단수 키 정리용 — navigate 가 undefined 로 지우고, 초기 필터 값 호환 읽기에만 쓴다(서버는 복수 키를 받는다)';

        return [
            $admin.'/admin_analytics_actions.json' => ['unit' => $chartUnit],
            $admin.'/admin_analytics_environment.json' => ['unit' => $chartUnit],
            $admin.'/admin_analytics_pages.json' => ['unit' => $chartUnit],
            $admin.'/admin_analytics_referrers.json' => ['unit' => $chartUnit],
            $admin.'/admin_analytics_regions.json' => ['unit' => $chartUnit],
            $admin.'/admin_analytics_patterns.json' => ['metric' => '차트 지표 토글 — 응답의 지표 중 무엇을 그릴지 화면에서만 고른다'],
            $admin.'/admin_identity_logs.json' => ['status' => $legacySingle, 'purpose' => $legacySingle],
            $admin.'/admin_settings.json' => ['sub_tab' => '본인인증 탭의 하위 섹션 스크롤 위치 — 화면 표시 상태'],
        ];
    }

    /**
     * data_source 가 보내는 쿼리 키는 엔드포인트 FormRequest 규칙의 최상위 키여야 한다 (검사 A).
     *
     * @scenario check=data_source_params_within_gate
     *
     * @effects dropped_query_key_detected
     *
     * @return void 반환값 없음
     */
    public function test_데이터소스_쿼리키는_게이트_규칙키의_부분집합이다(): void
    {
        $this->assertDataSourceParamsWithinGate($this->roots(), $this->dataSourceParamAllowlist());
    }

    /**
     * 자기 경로 navigate 의 query 키는 같은 화면의 data_source 가 읽어야 한다 (검사 B).
     *
     * @scenario check=navigate_query_consumed
     *
     * @effects unconsumed_navigate_key_detected
     *
     * @return void 반환값 없음
     */
    public function test_자기경로_navigate_쿼리키는_데이터소스가_읽는다(): void
    {
        $this->assertNavigateQueryKeysConsumed($this->roots(), $this->navigateQueryAllowlist());
    }
}
