<?php

namespace Tests\Feature\Api\Public;

use App\Enums\TotalRelation;
use App\Extension\HookManager;
use App\Search\SearchCategoryPayload;
use Tests\TestCase;

/**
 * 통합 검색 실패 표면화 계약 테스트 (공개 이슈 #103)
 *
 * 카테고리 검색이 예외로 실패하면 화면은 "검색 결과 없음" 과 구분되는 오류 안내를
 * 그려야 한다. 그 판정 근거인 `categories_failed` / `search_failed` 를 코어가
 * `counts_are_exact` 와 동형으로 **일괄 조립**하는 계약을 고정한다 — 모듈별 복사에
 * 맡기면 빠지는 카테고리가 생긴다.
 *
 * @scenario case=search_failure_flags
 *
 * @effects failed_flag_in_response,
 *          failure_flags_default_to_false
 */
class PublicSearchControllerFailureFlagTest extends TestCase
{
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.search.results');

        parent::tearDown();
    }

    /**
     * 실패한 카테고리가 categories_failed / search_failed 로 표면화되는지 확인
     *
     * @effects failed_flag_in_response
     */
    public function test_failed_category_surfaces_in_response_flags(): void
    {
        // Given: 한 카테고리는 실패, 다른 하나는 정상인 검색 결과
        HookManager::addFilter('core.search.results', function (array $results): array {
            $results['alpha'] = SearchCategoryPayload::failed();
            $results['beta'] = [
                'total' => 3,
                'total_relation' => TotalRelation::Exact->value,
                'total_is_exact' => true,
                'items' => [],
            ];

            return $results;
        }, 5);

        // When: 통합 검색을 호출
        $response = $this->getJson('/api/search?q=테스트');

        // Then: HTTP 200 이되 실패가 응답 키로 구분된다
        $response->assertOk();
        $response->assertJsonPath('data.categories_failed.alpha', true);
        $response->assertJsonPath('data.categories_failed.beta', false);
        $response->assertJsonPath('data.search_failed', true);
        // 실패한 0건은 "정확한 0건" 이 아니다
        $response->assertJsonPath('data.counts_are_exact.alpha', false);
    }

    /**
     * 실패가 없으면 두 키 모두 false 로 존재하는지 확인
     *
     * 키 자체가 없으면 화면이 "실패 정보 없음(구버전)" 과 "실패 없음" 을 구분하지 못한다.
     *
     * @effects failure_flags_default_to_false
     */
    public function test_flags_default_to_false_when_no_failure(): void
    {
        // Given: 정상 카테고리만 있는 검색 결과
        HookManager::addFilter('core.search.results', function (array $results): array {
            $results['alpha'] = [
                'total' => 7,
                'total_relation' => TotalRelation::Exact->value,
                'total_is_exact' => true,
                'items' => [],
            ];

            return $results;
        }, 5);

        // When: 통합 검색을 호출
        $response = $this->getJson('/api/search?q=테스트');

        // Then: 실패 키는 존재하되 전부 false
        $response->assertOk();
        $response->assertJsonPath('data.categories_failed.alpha', false);
        $response->assertJsonPath('data.search_failed', false);
    }
}
