<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 게시판 목록 조회의 쿼리 수 회귀 테스트 (#519)
 *
 * 행 수를 늘려도 쿼리 수가 늘지 않는지 단언한다 — 그것이 N+1 의 정의이고,
 * 정상적인 구조 변경(관계 추가 등)에는 반응하지 않는다.
 *
 * @scenario case=list_query_count
 *
 * @effects post_list_query_count_stable,
 *          comment_tree_query_count_stable
 */
class ListQueryCountRegressionTest extends BoardTestCase
{
    use CountsQueries;

    /**
     * 게시글을 원하는 수만큼 만듭니다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  제목 접두
     * @return void 반환값 없음
     */
    private function seedPosts(int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            Post::create([
                'board_id' => $this->board->id,
                'title' => $prefix.' '.$i,
                'content' => '본문',
                'author_name' => '작성자',
                'status' => 'published',
                'ip_address' => '127.0.0.1',
            ]);
        }
    }

    /**
     * 게시글 목록: 글 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects post_list_query_count_stable
     *
     * @return void 반환값 없음
     */
    public function test_post_list_query_count_is_stable(): void
    {
        $repository = app(PostRepositoryInterface::class);
        $this->seedPosts(5, '초기');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->paginate($this->board->slug, [], 50, false, $this->board),
            grow: fn () => $this->seedPosts(10, '추가'),
            context: '게시글 목록',
        );
    }

    /**
     * 목록 쿼리 확장 훅(#147)에 리스너가 없으면 서비스 목록·건수 경로의 쿼리 수가 저장소 직접 호출과 같다.
     *
     * 서비스는 리스너가 있을 때만 확장 조건을 모으고 게시판을 조회한다 — 없으면 종전과 같은 쿼리만 나간다.
     *
     * @scenario case=no_listener
     *
     * @effects no_extra_queries_without_listener
     *
     * @return void 반환값 없음
     */
    public function test_list_query_extensions_add_no_queries_without_listener(): void
    {
        $repository = app(PostRepositoryInterface::class);
        $service = app(PostService::class);
        $this->seedPosts(5, '확장없음');
        $slug = $this->board->slug;
        $scope = ['scope_permission' => "sirsoft-board.{$slug}.posts.read"];

        $viaRepository = $this->countQueries(fn () => $repository->paginate($slug, $scope, 50, false, $this->board));
        $viaService = $this->countQueries(fn () => $service->getPosts($slug, [], 50, false, 'user', $this->board));
        $this->assertSame($viaRepository, $viaService, '리스너가 없으면 목록 경로에 쿼리가 더해지지 않는다');

        $countViaRepository = $this->countQueries(fn () => $repository->countNormalPosts($slug, $scope, false));
        $countViaService = $this->countQueries(fn () => $service->getTotalNormalPosts($slug, [], false, 'user'));
        // 서비스 건수 경로는 종전부터 게시판 존재 확인(validateBoardExists) 1건이 앞선다
        $this->assertSame($countViaRepository + 1, $countViaService, '리스너가 없으면 건수 경로에 쿼리가 더해지지 않는다');
    }

    /**
     * 댓글 트리: 댓글 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 종전에는 부모 댓글을 관계로 다시 불러 같은 집합을 한 번 더 가져왔다.
     *
     * @effects comment_tree_query_count_stable
     *
     * @return void 반환값 없음
     */
    public function test_comment_tree_query_count_is_stable(): void
    {
        $repository = app(CommentRepositoryInterface::class);

        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '댓글 트리',
            'content' => '본문',
            'author_name' => '작성자',
            'status' => 'published',
            'ip_address' => '127.0.0.1',
        ]);

        $seedComments = function (int $count, string $prefix) use ($post) {
            for ($i = 0; $i < $count; $i++) {
                Comment::create([
                    'board_id' => $this->board->id,
                    'post_id' => $post->id,
                    'content' => $prefix.' '.$i,
                    'author_name' => '댓글쓴이',
                    'ip_address' => '127.0.0.1',
                ]);
            }
        };

        $seedComments(5, '초기');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getByPostId($this->board->slug, $post->id, boardId: $this->board->id),
            grow: fn () => $seedComments(10, '추가'),
            context: '댓글 트리',
        );
    }

    /**
     * 비밀글 목록: 비밀글 수가 늘어도 경로 판정 쿼리 수가 늘지 않는다 — 답변을 쓴 회원 기준 (#789 §4.2 prime).
     *
     * 목록 직렬화 전에 SecretThreadAccess::prime() 이 레벨당 1쿼리로 노드를 채우므로 페이지 크기와
     * 무관한 상수 상한이어야 한다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects secret_list_thread_query_count_stable
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_list_thread_query_count_is_stable(): void
    {
        $member = User::factory()->create();
        $member->roles()->attach(Role::where('identifier', 'user')->value('id'));
        $this->grantUserRolePermissions(['posts.read']);
        $root = Post::create([
            'board_id' => $this->board->id, 'title' => '원글', 'content' => '본문', 'author_name' => 'a',
            'status' => 'published', 'ip_address' => '127.0.0.1', 'is_secret' => true,
        ]);
        // 회원이 답변을 하나 써 두어 자손 탐색 경로까지 타게 한다
        Post::create([
            'board_id' => $this->board->id, 'parent_id' => $root->id, 'depth' => 1, 'title' => '내 답변', 'content' => '본문',
            'author_name' => 'm', 'user_id' => $member->id, 'status' => 'published', 'ip_address' => '127.0.0.1', 'is_secret' => true,
        ]);
        $seedSecret = function (int $count, string $prefix) {
            for ($i = 0; $i < $count; $i++) {
                Post::create([
                    'board_id' => $this->board->id, 'title' => $prefix.' '.$i, 'content' => '본문', 'author_name' => '작성자',
                    'status' => 'published', 'ip_address' => '127.0.0.1', 'is_secret' => true,
                ]);
            }
        };
        $seedSecret(5, '초기');
        $this->actingAs($member);

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $this->getJson('/api/modules/sirsoft-board/boards/'.$this->board->slug.'/posts?per_page=50')->assertOk(),
            grow: fn () => $seedSecret(10, '추가'),
            context: '비밀글 목록(경로 판정 prime)',
        );
    }
}
