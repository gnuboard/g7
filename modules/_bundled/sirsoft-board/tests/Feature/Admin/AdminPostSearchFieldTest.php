<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 게시글 목록 검색 대상 구분 테스트
 *
 * 검증 목적 (이슈 #524 §4.2 — 관리자 목록에 all/title_content/author 는 있는데
 * 「제목만」·「내용만」이 없던 결함):
 * - filters[0][field]=title → 제목만, content → 내용만
 * - all 은 종전과 같이 제목+내용 FULLTEXT + 작성자 분기를 함께 유지한다 (회귀)
 *
 * URL: GET /api/modules/sirsoft-board/admin/board/{slug}/posts
 *
 * @scenario surface=admin_post_list,search_field=title
 *
 * @effects admin_post_list_search_field_title_only, admin_post_list_search_field_content_only
 */
#[Group('board')]
#[Group('admin')]
#[Group('search')]
class AdminPostSearchFieldTest extends BoardTestCase
{
    private User $admin;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-post-search-field';
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
            'name' => ['ko' => '관리자 검색 구분 테스트 게시판', 'en' => 'Admin Search Field Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
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
     * filters[0][field]=title → 본문에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=admin_post_list,search_field=title
     *
     * @effects admin_post_list_search_field_title_only
     *
     * @return void 반환값 없음
     */
    public function test_admin_posts_search_field_title_only(): void
    {
        $titleHit = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $this->assertSame([$titleHit], $this->searchIds('알파', 'title'));
    }

    /**
     * filters[0][field]=content → 제목에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=admin_post_list,search_field=content
     *
     * @effects admin_post_list_search_field_content_only
     *
     * @return void 반환값 없음
     */
    public function test_admin_posts_search_field_content_only(): void
    {
        $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $this->assertSame([$contentHit], $this->searchIds('알파', 'content'));
    }

    /**
     * all 은 제목+내용 FULLTEXT 와 작성자 분기를 모두 유지한다 (회귀).
     *
     * 행 매칭이 아니라 쿼리 형태로 본다 — InnoDB FULLTEXT 는 커밋되지 않은 행을
     * 보지 못하므로 DatabaseTransactions 스위트에서 MATCH 결과가 비어 있다.
     *
     * @scenario surface=admin_post_list
     *
     * @effects admin_post_list_search_field_all_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_admin_posts_search_field_all_unchanged(): void
    {
        $this->createTestPost(['title' => '알파 제목', 'content' => '무관', 'author_name' => '알파작성자']);

        $statements = [];
        DB::listen(function ($event) use (&$statements) {
            if (str_contains($event->sql, 'board_posts')) {
                $statements[] = $event->sql;
            }
        });

        $this->actingAs($this->admin)
            ->getJson($this->listUrl($this->searchQuery('all', '알파')))
            ->assertStatus(200);

        $sql = implode("\n", $statements);

        $this->assertStringContainsStringIgnoringCase('match', $sql, 'all 은 제목+내용 FULLTEXT 분기를 유지해야 한다');
        $this->assertStringContainsString('author_name', $sql, 'all 은 작성자 분기를 유지해야 한다');
    }

    /**
     * 관리자 목록 URL 을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 엔드포인트 URL
     */
    private function listUrl(array $query = []): string
    {
        $slug = $this->board->slug;
        $base = "/api/modules/sirsoft-board/admin/board/{$slug}/posts";

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * filters[0] 규약의 검색 쿼리를 만듭니다.
     *
     * @param  string  $field  검색 대상
     * @param  string  $value  검색어
     * @return array<string, mixed> 쿼리 배열
     */
    private function searchQuery(string $field, string $value): array
    {
        return ['filters' => [['field' => $field, 'value' => $value, 'operator' => 'like']]];
    }

    /**
     * 검색 결과의 게시글 ID 목록을 반환합니다.
     *
     * @param  string  $search  검색어
     * @param  string  $searchField  검색 대상
     * @return array<int, int> 게시글 ID 목록
     */
    private function searchIds(string $search, string $searchField): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson($this->listUrl($this->searchQuery($searchField, $search)));

        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data.data') ?? [], 'id'));
    }
}
