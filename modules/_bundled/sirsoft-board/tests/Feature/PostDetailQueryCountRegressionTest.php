<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 게시글 상세 조회의 중복 읽기 회귀 테스트 (#519 F3)
 *
 * 상세 화면은 사이트에서 가장 자주 열리는 경로다. 종전에는 권한 판정용으로 글 행을 한 번
 * 읽고, 관계를 붙여 응답에 쓸 글 행을 한 번 더 읽어 **같은 행을 두 번** 가져왔다. 게시판
 * 행도 컨트롤러·서비스·저장소가 각각 slug 로 다시 조회해 세 번 읽혔다.
 *
 * 여기서는 "같은 행을 몇 번 읽는가" 만 단언한다. 관계 로딩(첨부·댓글·답글)은 별개 쿼리이고
 * 게시판 설정에 따라 수가 달라지므로 총 쿼리 수를 고정하지 않는다 — 고정하면 정상적인
 * 기능 추가마다 깨져 결국 숫자만 올리는 테스트가 된다.
 *
 * @scenario case=post_detail_duplicate_read
 *
 * @effects post_detail_reads_post_row_once,
 *          post_detail_reads_board_row_once
 */
class PostDetailQueryCountRegressionTest extends BoardTestCase
{
    use CountsQueries;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::create([
            'board_id' => $this->board->id,
            'title' => '상세 중복 조회 점검',
            'content' => '본문',
            'author_name' => '작성자',
            'status' => 'published',
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 상세 조회 중 실행된 SQL 을 수집합니다.
     *
     * @return array<int, string> 실행된 SQL 목록
     */
    private function captureDetailQueries(): array
    {
        return $this->captureQueries(function () {
            $response = $this->getJson(
                '/api/modules/sirsoft-board/boards/'.$this->board->slug.'/posts/'.$this->post->id
            );

            $response->assertOk();
        });
    }

    /**
     * 특정 테이블의 전체 컬럼(`select *`) 조회만 셉니다.
     *
     * 한 컬럼만 읽는 조회(조회수 갱신 후의 `select view_count` 등)는 목적이 다른 경량
     * 질의이므로 중복 읽기 판정에서 제외한다. 여기서 잡고 싶은 것은 같은 행 전체를
     * 두 번 가져오는 낭비다.
     *
     * @param  array<int, string>  $queries  수집된 SQL 목록
     * @param  string  $table  테이블명 (프리픽스 제외)
     * @return int 해당 테이블 전체 컬럼 SELECT 수
     */
    private function countFullRowSelectsOn(array $queries, string $table): int
    {
        $prefixed = DB::getTablePrefix().$table;

        return count(array_filter(
            $queries,
            fn (string $sql) => str_starts_with(strtolower(trim($sql)), 'select *')
                && str_contains($sql, '`'.$prefixed.'`')
        ));
    }

    /**
     * 글 행을 한 번만 읽는지 확인
     *
     * 권한 판정과 응답 조립이 같은 인스턴스를 공유해야 한다.
     *
     * @effects post_detail_reads_post_row_once
     */
    public function test_post_row_is_read_once(): void
    {
        $queries = $this->captureDetailQueries();

        $postSelects = $this->countFullRowSelectsOn($queries, 'board_posts');

        // 본문 1회 + 답글 트리 탐색 1회. 권한 판정용으로 같은 행을 한 번 더 읽던
        // 종전 동작(3회)을 차단한다.
        $this->assertLessThanOrEqual(
            2,
            $postSelects,
            '게시글 상세에서 글 전체 조회가 '.$postSelects.'회 — 같은 행을 중복으로 읽고 있다.'
        );
    }

    /**
     * 게시판 행을 한 번만 읽는지 확인
     *
     * 컨트롤러가 이미 조회한 게시판을 서비스·저장소가 slug 로 다시 찾지 않아야 한다.
     *
     * @effects post_detail_reads_board_row_once
     */
    public function test_board_row_is_read_once(): void
    {
        $queries = $this->captureDetailQueries();

        $boardSelects = $this->countFullRowSelectsOn($queries, 'boards');

        $this->assertLessThanOrEqual(
            1,
            $boardSelects,
            '게시글 상세에서 게시판 테이블 SELECT 가 '.$boardSelects.'회 — 이미 조회한 게시판을 다시 찾고 있다.'
        );
    }

    /**
     * 딸린 데이터가 늘어도 상세 화면의 쿼리 수가 늘지 않는지 확인
     *
     * 앞의 두 단언은 "같은 행을 두 번 읽지 않는다" 만 본다. 그것만으로는 댓글처럼
     * 개수가 변하는 자식 데이터에 항목별 쿼리가 붙는 형태를 잡지 못한다 — 그 형태는
     * 글 행을 한 번만 읽으면서도 댓글 수만큼 쿼리를 낸다.
     *
     * 그래서 댓글을 3배로 늘려 놓고 같은 화면을 다시 재, 쿼리 수가 그대로인지 본다.
     *
     * @effects post_detail_query_count_does_not_grow_with_comment_count
     */
    public function test_query_count_does_not_grow_with_comment_count(): void
    {
        $seedComments = function (int $count, string $prefix): void {
            for ($i = 0; $i < $count; $i++) {
                Comment::create([
                    'board_id' => $this->board->id,
                    'post_id' => $this->post->id,
                    'content' => $prefix.' '.$i,
                    'author_name' => '댓글쓴이',
                    'ip_address' => '127.0.0.1',
                ]);
            }
        };

        $seedComments(3, '초기');

        // 모집단 확인 — 상세 응답이 댓글을 싣지 않게 되면 아래 단언은 "댓글을 늘려도
        // 쿼리가 안 는다" 를 아무 근거 없이 통과시킨다. 댓글을 실제로 읽는지 먼저 고정한다.
        $commentReads = array_filter(
            $this->captureDetailQueries(),
            fn (string $sql) => str_contains($sql, '`'.DB::getTablePrefix().'board_comments`')
        );
        $this->assertNotEmpty($commentReads, '상세 응답이 댓글을 조회하지 않는다 — 측정 전제가 무너졌다');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $this->captureDetailQueries(),
            grow: fn () => $seedComments(9, '추가'),
            context: '게시글 상세(댓글 3건 → 12건)'
        );
    }
}
