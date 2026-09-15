<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 목록 검색 대상 구분 테스트 (사용자)
 *
 * 검증 목적 (이슈 #524 §4.2 — 사용자 화면에 검색 구분이 어디에도 없던 결함):
 * - search_field=title → 제목만, content → 내용만, author → 작성자만
 * - 기본값(title_content)과 미지정(all)의 결과가 종전과 같다 (회귀)
 * - 허용 목록 밖의 값은 422 (모르는 값이 조용히 all 로 떨어지면 결과만 틀린다)
 *
 * URL: GET /api/modules/sirsoft-board/boards/{slug}/posts
 *
 * @scenario surface=board_list,search_field=title
 *
 * @effects board_list_search_field_title_only, board_list_search_field_content_only
 *
 * @group board
 * @group user
 * @group search
 */
class BoardListSearchFieldTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'board-list-search-field';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '검색 구분 테스트 게시판', 'en' => 'Search Field Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read']);
    }

    /**
     * search_field=title → 본문에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=board_list,search_field=title
     *
     * @effects board_list_search_field_title_only
     */
    public function test_user_list_search_field_title_only(): void
    {
        $titleHit = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $ids = $this->searchIds('알파', 'title');

        $this->assertSame([$titleHit], $ids);
    }

    /**
     * search_field=content → 제목에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=board_list,search_field=content
     *
     * @effects board_list_search_field_content_only
     */
    public function test_user_list_search_field_content_only(): void
    {
        $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $ids = $this->searchIds('알파', 'content');

        $this->assertSame([$contentHit], $ids);
    }

    /**
     * search_field=author → 작성자명만 매칭한다.
     *
     * @scenario surface=board_list,search_field=author
     *
     * @effects board_list_search_field_author_only
     */
    public function test_user_list_search_field_author_only(): void
    {
        $authorHit = $this->createTestPost(['title' => '무관', 'content' => '무관', 'author_name' => '유니크작성자']);
        $this->createTestPost(['title' => '유니크작성자 제목', 'content' => '무관', 'author_name' => '다른사람']);

        $ids = $this->searchIds('유니크작성자', 'author');

        $this->assertSame([$authorHit], $ids);
    }

    /**
     * search_field=title_content → 제목+내용 복합 FULLTEXT 경로를 탄다.
     *
     * 행 매칭이 아니라 쿼리 형태로 검증한다. InnoDB FULLTEXT 는 같은 트랜잭션 안에서
     * 아직 커밋되지 않은 행을 보지 못하는데, 이 스위트는 DatabaseTransactions 로 돌아
     * 테스트가 만든 글이 MATCH 대상에 들어가지 않는다(기존 PostSearchKeywordSanitizationTest
     * 도 FULLTEXT 경로는 상태코드만, 행 매칭은 LIKE 경로에서만 검증한다).
     * 여기서 지켜야 하는 계약은 "title_content 가 단일 컬럼 LIKE 로 떨어지지 않는다" 이므로
     * 그 계약을 직접 본다 — 떨어지면 인덱스를 잃고도 결과가 맞아 보여 아무도 눈치채지 못한다.
     *
     * @scenario surface=board_list,search_field=title_content
     *
     * @effects board_list_search_field_title_content_uses_fulltext
     */
    public function test_user_list_search_field_title_content_default(): void
    {
        $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);

        $sql = $this->captureListSql(['search' => '알파', 'search_field' => 'title_content']);

        $this->assertStringContainsStringIgnoringCase(
            'match',
            $sql,
            'title_content 는 복합 FULLTEXT(MATCH … AGAINST) 경로를 타야 한다'
        );
        $this->assertStringNotContainsString(
            '`title` like',
            strtolower($sql),
            'title_content 가 단일 컬럼 LIKE 로 떨어지면 복합 인덱스를 쓰지 못한다'
        );
    }

    /**
     * 허용 목록 밖의 search_field → 422.
     *
     * @scenario surface=board_list
     *
     * @effects board_list_rejects_invalid_search_field
     */
    public function test_user_list_rejects_invalid_search_field(): void
    {
        $this->getJson($this->listUrl(['search' => 'x', 'search_field' => 'email']))
            ->assertStatus(422);
    }

    /**
     * search_field 를 아예 보내지 않으면 종전(all) 동작 — 제목·내용·작성자 어디든 매칭 (회귀).
     *
     * @scenario surface=board_list
     *
     * @effects board_list_without_search_field_keeps_all_behaviour
     */
    public function test_user_list_without_search_field_keeps_all_behaviour(): void
    {
        $authorHit = $this->createTestPost(['title' => '무관', 'content' => '무관', 'author_name' => '알파작성자']);

        // 작성자 분기는 LIKE 라 트랜잭션 안에서도 보인다 — 행으로 직접 검증한다.
        $ids = $this->searchIds('알파작성자', null);
        $this->assertSame([$authorHit], $ids);

        // 제목+내용 분기가 함께 살아 있는지는 쿼리 형태로 본다 (FULLTEXT 가시성 제약).
        $sql = $this->captureListSql(['search' => '알파작성자']);

        $this->assertStringContainsStringIgnoringCase('match', $sql, 'all 은 제목+내용 FULLTEXT 분기를 유지해야 한다');
        $this->assertStringContainsString('author_name', $sql, 'all 은 작성자 분기를 유지해야 한다');
    }

    /**
     * 목록 URL 을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 엔드포인트 URL
     */
    private function listUrl(array $query = []): string
    {
        $slug = $this->board->slug;
        $base = "/api/modules/sirsoft-board/boards/{$slug}/posts";

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * 목록 조회 중 실행된 게시글 SELECT 문을 모아 돌려줍니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 실행된 SQL 을 이어붙인 문자열
     */
    private function captureListSql(array $query): string
    {
        $statements = [];

        DB::listen(function ($event) use (&$statements) {
            if (str_contains($event->sql, 'board_posts')) {
                $statements[] = $event->sql;
            }
        });

        $this->getJson($this->listUrl($query))->assertStatus(200);

        return implode("\n", $statements);
    }

    /**
     * 검색 결과의 게시글 ID 목록을 반환합니다.
     *
     * @param  string  $search  검색어
     * @param  string|null  $searchField  검색 대상 (null 이면 파라미터 미전송)
     * @return array<int, int> 게시글 ID 목록
     */
    private function searchIds(string $search, ?string $searchField): array
    {
        $query = ['search' => $search];

        if ($searchField !== null) {
            $query['search_field'] = $searchField;
        }

        $response = $this->getJson($this->listUrl($query));
        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data.data') ?? [], 'id'));
    }
}
