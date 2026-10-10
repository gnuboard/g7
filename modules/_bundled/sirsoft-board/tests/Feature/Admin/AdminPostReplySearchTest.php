<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 게시글 목록 검색이 답글까지 찾는지 (7.1.0 실측 O-K3-13, 결정 2026-10-08)
 *
 * 관리자 목록 검색은 원글만 훑어, 답글 제목·내용·답글 작성자로는 아무것도 찾지 못했다. 목록은 원글 한 줄 아래에
 * 그 답글 줄을 이어 그리는 구조이고 페이지·총 건수도 원글 단위로 센다. 그래서 답글이 걸리면 그 답글이 달린 원글을
 * 결과로 세고, 원글 아래 답글 줄(걸린 답글 포함)을 종전처럼 함께 보인다. 총 건수와 페이지는 같은 조건으로 센다.
 *
 * FULLTEXT 는 커밋되지 않은 행을 보지 못하므로(DatabaseTransactions) 행 매칭은 LIKE 분기(title·content·author)로 잰다.
 *
 * 시나리오 SSoT: modules/_bundled/sirsoft-board/tests/scenarios/admin-post-search-field.yaml
 */
#[Group('board')]
#[Group('admin')]
#[Group('search')]
class AdminPostReplySearchTest extends BoardTestCase
{
    private User $admin;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-post-reply-search';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '관리자 답글 검색 테스트 게시판', 'en' => 'Admin Reply Search Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'per_page' => 10,
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.read",
            "sirsoft-board.{$slug}.admin.manage",
        ]);
    }

    /**
     * 제목으로 찾을 때 답글 제목만 걸려도 그 원글이 결과가 되고, 원글 아래 답글 줄이 함께 온다.
     *
     * @scenario search_field=title
     *
     * @effects admin_post_list_search_matches_replies
     *
     * @return void 반환값 없음
     */
    public function test_title_search_finds_parent_of_matching_reply(): void
    {
        $parent = $this->createTestPost(['title' => '원글 제목', 'content' => '원글 본문']);
        $reply = $this->createTestPost(['title' => '감마 답글', 'content' => '답글 본문', 'parent_id' => $parent, 'depth' => 1]);
        $this->createTestPost(['title' => '상관없는 글', 'content' => '상관없음']);

        $response = $this->search('감마', 'title');

        $this->assertSame([$parent, $reply], $this->ids($response));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * 내용으로 찾을 때 둘째 단계 답글(답글의 답글)만 걸려도 맨 위 원글이 결과가 된다.
     *
     * @scenario search_field=content
     *
     * @effects admin_post_list_search_matches_replies
     *
     * @return void 반환값 없음
     */
    public function test_content_search_finds_root_of_nested_reply(): void
    {
        $root = $this->createTestPost(['title' => '맨 위 원글', 'content' => '본문']);
        $reply = $this->createTestPost(['title' => '답글', 'content' => '본문', 'parent_id' => $root, 'depth' => 1]);
        $nested = $this->createTestPost(['title' => '답글의 답글', 'content' => '델타 키워드', 'parent_id' => $reply, 'depth' => 2]);

        $response = $this->search('델타', 'content');

        $this->assertSame([$root, $reply, $nested], $this->ids($response));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * 작성자로 찾을 때 답글만 쓴 사람의 이름으로도 그 원글이 걸린다(K3 실측: 답글만 쓴 회원 검색 0건).
     *
     * @scenario search_field=author
     *
     * @effects admin_post_list_search_matches_replies
     *
     * @return void 반환값 없음
     */
    public function test_author_search_finds_parent_of_reply_author(): void
    {
        $parent = $this->createTestPost(['title' => '원글', 'author_name' => '원글작성자']);
        $reply = $this->createTestPost(['title' => '답글', 'author_name' => '답글만쓴이', 'parent_id' => $parent, 'depth' => 1]);

        $response = $this->search('답글만쓴이', 'author');

        $this->assertSame([$parent, $reply], $this->ids($response));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * 원글과 답글이 함께 걸려도 원글은 한 번만 세고, 걸리지 않은 원글은 오지 않는다. 페이지 크기를 넘으면
     * 다음 쪽으로 넘어가고 총 건수는 원글 수다.
     *
     * @scenario search_field=title
     *
     * @effects admin_post_list_search_reply_match_counts_parents_once
     *
     * @return void 반환값 없음
     */
    public function test_reply_matches_count_parent_once_and_paginate(): void
    {
        $matchedParents = [];
        for ($i = 1; $i <= 11; $i++) {
            $parent = $this->createTestPost(['title' => "엡실론 원글 {$i}"]);
            $this->createTestPost(['title' => "엡실론 답글 {$i}", 'parent_id' => $parent, 'depth' => 1]);
            $matchedParents[] = $parent;
        }
        $this->createTestPost(['title' => '걸리지 않는 원글']);

        $first = $this->search('엡실론', 'title');
        $this->assertSame(11, $first->json('data.pagination.total'));
        $firstParents = array_values(array_filter($first->json('data.data'), fn ($row) => empty($row['parent_id'])));
        $this->assertCount(10, $firstParents);

        $second = $this->search('엡실론', 'title', 2);
        $secondParents = array_values(array_filter($second->json('data.data'), fn ($row) => empty($row['parent_id'])));
        $this->assertCount(1, $secondParents);

        $allParentIds = array_merge(array_column($firstParents, 'id'), array_column($secondParents, 'id'));
        sort($allParentIds);
        sort($matchedParents);
        $this->assertSame($matchedParents, array_map('intval', $allParentIds));
    }

    /**
     * 관리자 목록을 검색합니다.
     *
     * @param  string  $value  검색어
     * @param  string  $field  검색 대상
     * @param  int  $page  쪽 번호
     * @return TestResponse 응답
     */
    private function search(string $value, string $field, int $page = 1)
    {
        $slug = $this->board->slug;
        $query = http_build_query([
            'filters' => [['field' => $field, 'value' => $value, 'operator' => 'like']],
            'page' => $page,
            'per_page' => 10,
        ]);

        return $this->actingAs($this->admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts?{$query}")
            ->assertStatus(200);
    }

    /**
     * 응답 목록의 게시글 ID (화면 순서).
     *
     * @param  TestResponse  $response  응답
     * @return array<int, int> 게시글 ID
     */
    private function ids($response): array
    {
        return array_map('intval', array_column($response->json('data.data') ?? [], 'id'));
    }
}
