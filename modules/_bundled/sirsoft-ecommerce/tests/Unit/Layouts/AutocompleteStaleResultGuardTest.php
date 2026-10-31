<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsAutocompleteResultsCarryKeyword;

/**
 * 회원 검색 자동완성이 늦게 도착한 옛 응답으로 목록을 덮어쓰지 않는지 본다.
 *
 * 이커머스 관리자 화면의 회원 검색은 두 형태로 나뉜다.
 *
 *   `event: "onSearch"` + SearchableDropdown → 컴포넌트가 `searchTerm` 으로 다시 거른다(안전)
 *   `type: "input"` + 원시 목록 렌더        → 응답이 오는 대로 목록이 바뀐다(이 테스트의 대상)
 *
 * 후자는 마일리지 수동 지급/차감과 쿠폰 직접 발급 두 곳이며, 둘 다 **고른 회원에게 값이 나가는**
 * 화면이다. 목록이 다른 검색의 결과를 그리고 있으면 운영자는 그 사실을 알 방법이 없다 — 입력칸에는
 * 자기가 친 문자열이 그대로 있고 목록도 멀쩡해 보인다.
 *
 * @see AssertsAutocompleteResultsCarryKeyword 재현 기록과 계약 설명
 */
class AutocompleteStaleResultGuardTest extends ModuleTestCase
{
    use AssertsAutocompleteResultsCarryKeyword;

    /**
     * 원시 자동완성은 결과와 함께 그 결과를 만든 키워드를 저장해야 한다.
     *
     * @scenario autocomplete_shape=raw_input_list
     *
     * @effects raw_autocomplete_stores_keyword_with_results
     */
    public function test_자동완성_결과는_그_결과를_만든_키워드를_함께_저장한다(): void
    {
        $this->assertAutocompleteResultsCarryKeyword([
            dirname(__DIR__, 3).'/resources/layouts',
        ]);
    }
}
