<?php

namespace Tests\Unit\Layouts;

use Tests\Support\Concerns\AssertsExtensionDataSourcesAreDeclared;
use Tests\TestCase;

/**
 * 확장 정의는 **자기가 선언한 데이터소스만** 참조해야 한다.
 *
 * 확장은 독립적으로 설치·제거된다. 남의 플러그인이 선언한 데이터소스 id 를 자기 표현식에
 * 적으면 그 플러그인이 없는 사이트에서 미정의 참조가 되는데, 표현식 평가는 조용히
 * undefined 를 내므로 콘솔 오류도 500 도 없다. 조건이 늘 거짓이 되어 노드가 나타나지 않거나,
 * 반대로 의도치 않게 나타난다.
 *
 * 2026-09-17 #599 실측에서 `sirsoft-pay_kginicis` 의 테스트결제 배지가 이 형태였다.
 * 자기는 `kginicis_test_map` 하나만 선언하면서 `nicepay_test_map` · `nhnkcp_test_map`
 * 까지 OR 로 묶어 봤다. KG이니시스만 설치한 사이트에서는 두 id 가 미정의였고, 셋 다
 * 설치한 사이트에서는 **남의 PG 로 결제한 테스트 주문에도 「KG이니시스」 배지 문구**가 붙었다
 * (배지 text 는 `$t:sirsoft-pay_kginicis.admin.test_mode_badge` 로 고정이다).
 */
class ExtensionDataSourceDeclarationTest extends TestCase
{
    use AssertsExtensionDataSourcesAreDeclared;

    /**
     * 번들 모듈·플러그인·템플릿의 확장 정의가 미선언 데이터소스를 참조하지 않는다.
     *
     * @scenario scope=bundled_extension_definitions
     *
     * @effects extension_definitions_reference_only_their_own_data_sources
     */
    public function test_확장_정의는_자기가_선언한_데이터소스만_참조한다(): void
    {
        $this->assertExtensionDataSourcesAreDeclared([
            base_path('modules/_bundled'),
            base_path('plugins/_bundled'),
            base_path('templates/_bundled'),
        ]);
    }
}
