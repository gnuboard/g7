<?php

namespace Tests\Feature\Api\Public;

use Tests\TestCase;

/**
 * 통합 검색 API 테스트
 *
 * Note: RefreshDatabase를 사용하지 않음 - 검색 API는 DB 없이도 기본 동작 검증 가능
 */
class SearchControllerTest extends TestCase
{
    /**
     * 검색어 없이 요청 시 빈 결과 반환 테스트
     */
    public function test_search_returns_empty_results_when_no_keyword(): void
    {
        // Act: 검색어 없이 요청
        $response = $this->getJson('/api/search');

        // Assert: 성공 응답 및 빈 결과
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'q',
                    'total',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'q' => '',
                    'total' => 0,
                ],
            ]);
    }

    /**
     * 검색어로 요청 시 결과 구조 반환 테스트
     */
    public function test_search_returns_results_structure_with_keyword(): void
    {
        // Act: 검색어와 함께 요청 (q 파라미터 사용)
        $response = $this->getJson('/api/search?q=테스트');

        // Assert: 성공 응답 및 결과 구조 확인
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'q',
                    'total',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'q' => '테스트',
                ],
            ]);
    }

    /**
     * 타입 필터 적용 테스트
     */
    public function test_search_accepts_type_filter(): void
    {
        // Act: 타입 필터와 함께 요청
        $response = $this->getJson('/api/search?q=테스트&type=posts');

        // Assert: 성공 응답
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 정렬 옵션 적용 테스트
     */
    public function test_search_accepts_sort_option(): void
    {
        // Act: 정렬 옵션과 함께 요청
        $response = $this->getJson('/api/search?q=테스트&sort=latest');

        // Assert: 성공 응답
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 페이지네이션 파라미터 적용 테스트
     */
    public function test_search_accepts_pagination_parameters(): void
    {
        // Act: 페이지네이션 파라미터와 함께 요청
        $response = $this->getJson('/api/search?q=테스트&page=2&per_page=20');

        // Assert: 성공 응답
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 게시판 필터 적용 테스트
     */
    public function test_search_accepts_board_filter(): void
    {
        // Act: 게시판 필터와 함께 요청
        $response = $this->getJson('/api/search?q=테스트&board_slug=notice');

        // Assert: 성공 응답
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 검색어 최대 길이 초과 시 검증 오류 테스트
     */
    public function test_search_fails_validation_when_keyword_exceeds_max_length(): void
    {
        // Arrange: 200자를 초과하는 검색어 생성
        $longKeyword = str_repeat('가', 201);

        // Act: 긴 검색어로 요청
        $response = $this->getJson('/api/search?q=' . $longKeyword);

        // Assert: 검증 오류 응답
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    /**
     * 검색 sort 파라미터는 자유 문자열 허용 (Rule::in 없음 — 현재 Public\SearchRequest)
     *
     * 예전 구현은 Rule::in 화이트리스트였으나 현재는 nullable|string 이므로
     * 임의 문자열도 422 없이 수용된다. 이 테스트는 회귀 방지용으로
     * "임의 sort 파라미터가 422 를 유발하지 않음" 을 고정한다.
     */
    public function test_search_sort_accepts_arbitrary_string(): void
    {
        // Act: 잘못된 정렬 옵션으로 요청 — 현재는 수용됨
        $response = $this->getJson('/api/search?q=테스트&sort=invalid_sort');

        $response->assertStatus(200);
    }

    /**
     * 잘못된 page 값 시 검증 오류 테스트
     */
    public function test_search_fails_validation_with_invalid_page(): void
    {
        // Act: 0 이하의 page 값으로 요청
        $response = $this->getJson('/api/search?q=테스트&page=0');

        // Assert: 검증 오류 응답
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['page']);
    }

    /**
     * 잘못된 per_page 값 시 검증 오류 테스트 (최대값 초과)
     */
    public function test_search_fails_validation_with_per_page_exceeding_max(): void
    {
        // Act: 최대값 100을 초과하는 per_page로 요청
        $response = $this->getJson('/api/search?q=테스트&per_page=101');

        // Assert: 검증 오류 응답
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * 유효한 정렬 옵션들이 모두 허용되는지 테스트
     */
    public function test_search_accepts_all_valid_sort_options(): void
    {
        $validSortOptions = ['relevance', 'latest', 'oldest', 'views', 'popular'];

        foreach ($validSortOptions as $sort) {
            $response = $this->getJson("/api/search?q=테스트&sort={$sort}");

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                ]);
        }
    }
}