<?php

namespace Tests\Feature\Search;

use App\Enums\TotalRelation;
use App\Extension\HookManager;
use Tests\TestCase;

/**
 * 검색 탭 배지의 정확도 전달 계약 테스트 (#519)
 *
 * 배지는 숫자 하나만 그리므로, 상한에 걸려 잘린 값이 정확한 것처럼 나가도 오류로 드러나지
 * 않고 그냥 틀린 숫자로만 보인다. 카테고리마다 정확도가 응답에 실리는지 고정한다.
 *
 * 모듈이 각자 자기 정확도 키를 내보내던 시절에는 어떤 배지는 받고 어떤 배지는 못 받았다.
 * 코어가 일괄로 붙인다는 성질을 여기서 잠근다.
 *
 * @scenario case=search_badge_accuracy
 *
 * @effects badge_accuracy_is_emitted_per_category,
 *          badge_accuracy_defaults_to_exact
 */
class SearchBadgeAccuracyTest extends TestCase
{
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.search.results');

        parent::tearDown();
    }

    /**
     * 카테고리마다 정확도가 응답에 실리는지 확인
     *
     * @effects badge_accuracy_is_emitted_per_category
     */
    public function test_accuracy_is_emitted_for_every_category(): void
    {
        // Given: 한 카테고리는 정확하고 다른 하나는 상한에 걸린 검색 결과
        HookManager::addFilter('core.search.results', function (array $results): array {
            $results['alpha'] = [
                'total' => 3,
                'total_relation' => TotalRelation::Exact->value,
                'total_is_exact' => true,
                'items' => [],
            ];
            $results['beta'] = [
                'total' => 10000,
                'total_relation' => TotalRelation::AtLeast->value,
                'total_is_exact' => false,
                'items' => [],
            ];

            return $results;
        }, 5);

        // When: 통합 검색을 호출
        $response = $this->getJson('/api/search?q=테스트');

        // Then: 카테고리별 정확도가 각각 실린다
        $response->assertOk();
        $response->assertJsonPath('data.counts_are_exact.alpha', true);
        $response->assertJsonPath('data.counts_are_exact.beta', false);
    }

    /**
     * 정확도를 싣지 않은 카테고리는 정확한 것으로 보되 키는 존재하는지 확인
     *
     * 키 자체가 없으면 화면이 "정확도 정보 없음" 과 "정확함" 을 구분하지 못한다.
     *
     * @effects badge_accuracy_defaults_to_exact
     */
    public function test_category_without_accuracy_defaults_to_exact(): void
    {
        // Given: 정확도를 싣지 않은 카테고리
        HookManager::addFilter('core.search.results', function (array $results): array {
            $results['legacy'] = ['total' => 7, 'items' => []];

            return $results;
        }, 5);

        // When: 통합 검색을 호출
        $response = $this->getJson('/api/search?q=테스트');

        // Then: 키는 존재하고 값은 정확으로 해석된다
        $response->assertOk();
        $response->assertJsonPath('data.counts_are_exact.legacy', true);
    }
}
