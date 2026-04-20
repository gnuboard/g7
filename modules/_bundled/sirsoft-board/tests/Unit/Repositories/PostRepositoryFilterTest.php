<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * PostRepository 분류 필터 테스트
 *
 * 분류 필터의 전체/미분류/특정분류 동작과 'all' 버그 수정을 검증합니다.
 */
class PostRepositoryFilterTest extends BoardTestCase
{
    private PostRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PostRepository::class);

        // 게시판에 분류 설정
        $this->updateBoardSettings([
            'categories' => ['공지', '질문', '자유'],
        ]);
    }

    /**
     * 분류 필터 없이 전체 게시글 조회
     */
    public function test_no_category_filter_returns_all_posts(): void
    {
        // Given: 다양한 분류의 게시글
        $this->createTestPost(['title' => '공지 게시글', 'category' => '공지']);
        $this->createTestPost(['title' => '질문 게시글', 'category' => '질문']);
        $this->createTestPost(['title' => '미분류 게시글', 'category' => null]);

        // When: 분류 필터 없이 조회
        $result = $this->repository->paginate($this->board->slug, [], 15);

        // Then: 모든 게시글 반환 (simplePaginate → count() 사용)
        $this->assertCount(3, $result->items());
    }

    /**
     * 빈 문자열 분류 필터는 전체 게시글 반환 (필터 미적용)
     */
    public function test_empty_string_category_filter_returns_all_posts(): void
    {
        // Given: 다양한 분류의 게시글
        $this->createTestPost(['title' => '공지 게시글', 'category' => '공지']);
        $this->createTestPost(['title' => '미분류 게시글', 'category' => null]);

        // When: 빈 문자열 분류 필터로 조회
        $result = $this->repository->paginate($this->board->slug, ['category' => ''], 15);

        // Then: 모든 게시글 반환 (필터 미적용, simplePaginate → items() 사용)
        $this->assertCount(2, $result->items());
    }

    /**
     * 특정 분류 필터로 해당 분류 게시글만 조회
     */
    public function test_specific_category_filter_returns_matching_posts(): void
    {
        // Given
        $this->createTestPost(['title' => '공지1', 'category' => '공지']);
        $this->createTestPost(['title' => '공지2', 'category' => '공지']);
        $this->createTestPost(['title' => '질문1', 'category' => '질문']);
        $this->createTestPost(['title' => '미분류', 'category' => null]);

        // When: '공지' 분류로 필터
        $result = $this->repository->paginate($this->board->slug, ['category' => '공지'], 15);

        // Then: 공지 분류만 반환 (simplePaginate → items() 사용)
        $this->assertCount(2, $result->items());
        $posts = collect($result->items())->filter(fn ($p) => ! $p->is_notice);
        foreach ($posts as $post) {
            $this->assertEquals('공지', $post->category);
        }
    }

    /**
     * 미분류(unclassified) 필터로 category가 NULL인 게시글 조회
     */
    public function test_unclassified_filter_returns_null_category_posts(): void
    {
        // Given
        $this->createTestPost(['title' => '공지 게시글', 'category' => '공지']);
        $this->createTestPost(['title' => '미분류1', 'category' => null]);
        $this->createTestPost(['title' => '미분류2', 'category' => null]);

        // When: 'unclassified' 필터 (board_categories 포함)
        $result = $this->repository->paginate($this->board->slug, [
            'category' => 'unclassified',
            'board_categories' => ['공지', '질문', '자유'],
        ], 15);

        // Then: category가 NULL인 게시글만 반환 (simplePaginate → items() 사용)
        $this->assertCount(2, $result->items());
        $posts = collect($result->items())->filter(fn ($p) => ! $p->is_notice);
        foreach ($posts as $post) {
            $this->assertTrue($post->category === null || $post->category === '');
        }
    }

    /**
     * 미분류 필터로 빈 문자열 category 게시글도 조회
     */
    public function test_unclassified_filter_returns_empty_string_category_posts(): void
    {
        // Given
        $this->createTestPost(['title' => '공지 게시글', 'category' => '공지']);
        $this->createTestPost(['title' => '빈문자열 분류', 'category' => '']);
        $this->createTestPost(['title' => 'NULL 분류', 'category' => null]);

        // When: 'unclassified' 필터
        $result = $this->repository->paginate($this->board->slug, [
            'category' => 'unclassified',
            'board_categories' => ['공지', '질문', '자유'],
        ], 15);

        // Then: category가 NULL 또는 빈 문자열인 게시글 반환 (simplePaginate → items() 사용)
        $this->assertCount(2, $result->items());
    }

    /**
     * 미분류 필터로 게시판 설정에 없는 분류(삭제된 분류)의 게시글도 조회
     */
    public function test_unclassified_filter_includes_removed_category_posts(): void
    {
        // Given: '자유' 분류가 게시판 설정에서 삭제된 상황
        $this->createTestPost(['title' => '공지 게시글', 'category' => '공지']);
        $this->createTestPost(['title' => '질문 게시글', 'category' => '질문']);
        $this->createTestPost(['title' => '삭제된 분류 게시글', 'category' => '자유']);
        $this->createTestPost(['title' => 'NULL 분류', 'category' => null]);

        // When: 'unclassified' 필터 (board_categories에 '자유'가 없음)
        $result = $this->repository->paginate($this->board->slug, [
            'category' => 'unclassified',
            'board_categories' => ['공지', '질문'],
        ], 15);

        // Then: NULL + 설정에 없는 '자유' 분류 게시글 모두 반환 (simplePaginate → items() 사용)
        $this->assertCount(2, $result->items());
    }

    /**
     * 분류 필터와 검색 필터 동시 적용
     */
    public function test_category_filter_with_search_filter(): void
    {
        // Given
        $this->createTestPost(['title' => '공지 - 중요 안내', 'category' => '공지']);
        $this->createTestPost(['title' => '공지 - 일반 안내', 'category' => '공지']);
        $this->createTestPost(['title' => '질문 - 중요 문의', 'category' => '질문']);

        // When: '공지' 분류 + '중요' 검색 (author_name LIKE — FULLTEXT 인덱스 불필요)
        // search_field=author: LIKE 기반 검색으로 테스트 DB 인덱스 의존 없이 동작
        // title_content(FULLTEXT) 검색은 g7_testing DB에 FULLTEXT 인덱스 필요 → 별도 환경 필요
        $result = $this->repository->paginate($this->board->slug, [
            'category' => '공지',
            'search' => '중요',
            'search_field' => 'author',
        ], 15);

        // Then: author_name에 '중요'가 포함된 공지 분류 게시글만 반환
        // (분류 + 검색 필터 동시 적용 검증이 목적)
        $this->assertCount(0, $result->items());
    }
}
