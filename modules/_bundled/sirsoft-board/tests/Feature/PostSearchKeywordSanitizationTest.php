<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 검색어 정제 회귀 테스트 (#407 재발 방지 / #519 C7)
 *
 * MySQL FULLTEXT 의 BOOLEAN MODE 는 `+ - * " ( ) ~ < > @` 를 연산자로 해석한다.
 * 원문 키워드를 그대로 바인딩하면 사용자가 그 문자를 입력하는 것만으로 파싱 오류가 나고
 * 목록 화면 전체가 500 이 된다. `#407` 이 코어에 정제기를 넣었지만 게시판은 자기 raw 쿼리를
 * 들고 있어 그 수정이 닿지 않았다.
 *
 * 여기서 단언하는 것은 "검색이 무엇을 찾는가" 가 아니라 **"어떤 입력에도 화면이 죽지 않는가"** 다.
 *
 * @scenario board-search-keyword-sanitization
 *
 * @effects boolean_mode_operator_does_not_500,
 *          html_like_keyword_does_not_500,
 *          operator_only_keyword_returns_empty,
 *          normal_keyword_still_matches
 */
class PostSearchKeywordSanitizationTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'search-sanitize-test';
    }

    /**
     * 테스트 사전 준비를 수행합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantDefaultGuestPermissions();
        $this->resetPermissionMiddlewareCache();

        $this->createTestPost([
            'title' => '정상 게시글',
            'content' => '검색으로 찾을 수 있는 본문입니다.',
            'status' => 'published',
        ]);
    }

    /**
     * BOOLEAN MODE 연산자를 포함한 검색어가 500 을 내지 않는지 확인
     *
     * @effects boolean_mode_operator_does_not_500
     */
    #[Test]
    public function boolean_mode_operators_do_not_break_search(): void
    {
        foreach (['+', '-', '*', '"', '(', ')', '~', '<', '>', '@', '+검색 -제외', '"닫히지 않은'] as $keyword) {
            $response = $this->searchPosts($keyword);

            $response->assertStatus(200, "검색어 [{$keyword}] 가 500 을 냈다 — BOOLEAN MODE 정제가 적용되지 않았다");
        }
    }

    /**
     * HTML 형태 검색어가 500 을 내지 않는지 확인
     *
     * `<script>` 는 `<` `>` 가 BOOLEAN MODE 연산자라 정제 없이는 파싱 오류가 난다.
     *
     * @effects html_like_keyword_does_not_500
     */
    #[Test]
    public function html_like_keyword_does_not_break_search(): void
    {
        $response = $this->searchPosts('<script>alert(1)</script>');

        $response->assertStatus(200);
    }

    /**
     * 연산자만 입력하면 오류 대신 빈 결과가 나오는지 확인
     *
     * 정제 후 남는 토큰이 없으면 "찾을 것이 없다" 이지 "오류" 가 아니다.
     *
     * @effects operator_only_keyword_returns_empty
     */
    #[Test]
    public function operator_only_keyword_returns_empty_result(): void
    {
        $response = $this->searchPosts('+++');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.data') ?? [], '연산자만 입력하면 매칭이 없어야 한다');
    }

    /**
     * 정제가 정상 검색어의 토큰을 보존하는지 확인
     *
     * FULLTEXT 인덱스는 커밋된 행만 색인하므로, 트랜잭션 안에서 만든 게시글은 MATCH 로
     * 찾을 수 없다. 그래서 "정제가 정상 검색어를 뭉개지 않는다" 는 명제는 정제기 자체의
     * 출력으로 확인하고, 실제 매칭은 아래 LIKE 경로 테스트가 담당한다.
     *
     * @effects normal_keyword_still_matches
     */
    #[Test]
    public function sanitizer_preserves_normal_keyword_tokens(): void
    {
        $this->assertSame(
            '"정상"',
            DatabaseFulltextEngine::sanitizeBooleanModeKeyword('정상'),
            '정제가 정상 검색어까지 걸러 내면 안 된다'
        );

        $this->assertSame(
            '"정상" "게시글"',
            DatabaseFulltextEngine::sanitizeBooleanModeKeyword('정상 게시글'),
            '여러 토큰은 각각 보존되어야 한다'
        );

        $this->assertSame(
            '"검색" "제외"',
            DatabaseFulltextEngine::sanitizeBooleanModeKeyword('+검색 -제외'),
            '연산자만 제거하고 토큰은 남긴다'
        );
    }

    /**
     * FULLTEXT 를 타지 않는 검색 경로(작성자 LIKE)가 정상 동작하는지 확인
     *
     * 정제 변경이 목록 검색 전체를 망가뜨리지 않았음을 실제 매칭으로 확인한다.
     *
     * @effects normal_keyword_still_matches
     */
    #[Test]
    public function like_path_search_still_matches(): void
    {
        $this->createTestPost([
            'title' => '작성자 검색 대상',
            'content' => '본문',
            'author_name' => '홍길동',
            'status' => 'published',
        ]);

        $this->resetPermissionMiddlewareCache();

        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?"
            .http_build_query(['search' => '홍길동', 'search_field' => 'author']);

        $response = $this->getJson($url);
        $response->assertStatus(200);

        $titles = collect($response->json('data.data') ?? [])->pluck('title')->all();

        $this->assertContains('작성자 검색 대상', $titles, 'LIKE 경로 검색이 매칭되어야 한다');
    }

    /**
     * 게시판 목록에 검색어를 얹어 조회합니다.
     *
     * @param  string  $keyword  검색어
     * @return TestResponse 응답
     */
    private function searchPosts(string $keyword)
    {
        $this->resetPermissionMiddlewareCache();

        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?"
            .http_build_query(['search' => $keyword, 'search_field' => 'title_content']);

        return $this->getJson($url);
    }
}
