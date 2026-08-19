<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Http\Resources\CommentResource;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비밀글 댓글 목록 게이팅 테스트 (KVE-2026-1914 A-4)
 *
 * 댓글 목록은 부모 게시글 비밀 상태를 검사하지 않아, 비밀글의 댓글이 비열람자에게
 * 그대로 노출되던 결함을 검증합니다.
 *
 * 정책: 부모 게시글이 비밀글이면 열람 권한(작성자/manager/posts.read-secret) 없는
 *       요청에는 댓글 목록을 노출하지 않는다(빈 목록).
 *
 * 실제 공격자 프로필은 미인증(게스트)이다. 사용자 댓글 목록 라우트는 comments.read
 * permission 미들웨어가 걸려 있어 그 권한이 없는 게스트를 401 로 선차단한다(부모가
 * 비밀글이든 아니든). 게스트가 permission 을 갖는 공개 게시판이라도 비밀 게이트가 빈
 * 목록을 돌려주는 것은 regular(비밀 권한 없는 회원) 경로와 동일하다.
 *
 * 시나리오 축(viewer)·효과는 매니페스트 tests/scenarios/board-secret-content-gate.yaml 참조.
 * 각 test 메서드의 `@scenario viewer=…` 마커가 축 조합을 커버한다(메서드당 단일 값).
 *
 * 효과 목록을 클래스 레벨에 몰아 적지 않는다 — 커버리지 룰은 마커 레벨을 구분하지 않으므로,
 * 클래스 레벨 목록이 있으면 그 메서드를 지워도 효과가 "언급됨" 으로 집계돼 삭제가 무증상
 * green 이 된다. 마커는 메서드에만 둔다.
 */
class SecretPostCommentAccessTest extends BoardTestCase
{
    private User $regularUser;

    private User $ownerUser;

    private User $managerUser;

    protected function getTestBoardSlug(): string
    {
        return 'secret-comment';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀 댓글 테스트 게시판', 'en' => 'Secret Comment Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->regularUser = User::factory()->create();
        $this->ownerUser = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach (['posts.read', 'comments.read'] as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $userRole->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $this->regularUser->roles()->attach($userRole->id);
            $this->ownerUser->roles()->attach($userRole->id);
        }

        $this->managerUser = User::factory()->create();
        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        foreach (['posts.read', 'comments.read', 'manager'] as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $managerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->managerUser->roles()->attach($managerRole->id);
    }

    private function commentsUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }

    private function secretPostWithComment(): int
    {
        $postId = $this->createTestPost([
            'title' => '비밀글',
            'status' => 'published',
            'is_secret' => true,
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createTestComment($postId, [
            'content' => '비밀 댓글 내용',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);

        return $postId;
    }

    /**
     * @scenario viewer=regular
     *
     * @effects regular_user_gets_empty_comment_list_on_secret_post
     */
    public function test_regular_user_gets_empty_comment_list_on_secret_post(): void
    {
        $postId = $this->secretPostWithComment();

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson($this->commentsUrl($postId));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'), '비열람자에게는 비밀글 댓글이 노출되면 안 됩니다');
    }

    /**
     * @scenario viewer=owner
     *
     * @effects owner_sees_secret_post_comments
     */
    public function test_owner_sees_secret_post_comments(): void
    {
        $postId = $this->secretPostWithComment();

        $response = $this->actingAs($this->ownerUser, 'sanctum')
            ->getJson($this->commentsUrl($postId));

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')), '작성자 본인은 비밀글 댓글을 볼 수 있어야 합니다');
    }

    /**
     * @scenario viewer=manager
     *
     * @effects manager_sees_secret_post_comments
     */
    public function test_manager_sees_secret_post_comments(): void
    {
        $postId = $this->secretPostWithComment();

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson($this->commentsUrl($postId));

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')), 'manager 는 비밀글 댓글을 볼 수 있어야 합니다');
    }

    /**
     * 미인증(게스트)은 비밀글 댓글 목록에 접근할 수 없다 (401).
     *
     * 사용자 댓글 목록 라우트는 comments.read permission 미들웨어가 걸려 있어, 그 권한이
     * 없는 게스트는 컨트롤러(비밀 게이트) 도달 전에 401 로 차단된다 — 해시/ID 로 비밀글
     * 댓글을 훑는 미인증 공격자를 막는다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_blocked_from_secret_post_comments
     */
    public function test_guest_blocked_from_secret_post_comments(): void
    {
        $postId = $this->secretPostWithComment();

        // actingAs 없음 = 미인증 게스트
        $response = $this->getJson($this->commentsUrl($postId));

        $response->assertStatus(401);
    }

    /**
     * @scenario viewer=regular
     *
     * @effects normal_post_comments_still_visible
     */
    public function test_normal_post_comments_still_visible(): void
    {
        $postId = $this->createTestPost([
            'title' => '정상글',
            'status' => 'published',
            'is_secret' => false,
        ]);
        $this->createTestComment($postId, ['content' => '정상 댓글']);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson($this->commentsUrl($postId));

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')), '정상글 댓글은 노출되어야 합니다');
    }

    /**
     * 2차 방어(이중 방어): 컨트롤러가 넘긴 부모 post 를 CommentResource 가 요청 속성으로 받아
     * 비밀글 댓글 원문을 마스킹한다 — 1차 방어(index 빈 컬렉션)가 회귀해 비뷰어가 목록에
     * 도달하더라도 Resource 층에서 원문이 새지 않음을 고정한다(KVE-2026-1914 A-4b).
     *
     * 이 테스트는 수정 전(2차 방어가 relationLoaded('post') 만 검사, 목록에서 post 미로드라 항상
     * no-op) 에는 content 가 노출되어 실패한다.
     *
     * @scenario viewer=guest
     *
     * @effects second_defense_masks_secret_comment_content
     */
    public function test_second_defense_masks_secret_comment_content_via_request_attribute(): void
    {
        $postId = $this->secretPostWithComment();
        $post = Post::find($postId);
        $comment = Comment::where('post_id', $postId)->first();
        $this->assertNotNull($comment, '비밀글에 댓글이 존재해야 합니다');

        // 게스트(비뷰어) 요청 + 컨트롤러 index 가 넘기는 부모 post 속성
        $request = Request::create($this->commentsUrl($postId), 'GET');
        $request->attributes->set('sirsoft_board_parent_post', $post);
        app()->instance('request', $request);

        $arr = (new CommentResource($comment))->toArray($request);

        $this->assertArrayHasKey('content', $arr);
        $this->assertNull($arr['content'], '부모가 비밀글인 댓글은 2차 방어로 원문이 마스킹되어야 합니다');
    }

    /**
     * 성능 실측: 댓글 목록 Resource 해석 시 부모 post 를 댓글당 lazy-load 하지 않아야 한다.
     * CommentResource::toArray 34행 `$this->post?->board?->slug` 가 content 계산 전 post 를
     * lazy-load 하면 댓글 N건에 post 쿼리 N건(N+1)이 발생한다 — 컨트롤러가 부모 post 를
     * 요청 속성으로 전달하면 0건이어야 한다.
     *
     * @scenario viewer=regular
     *
     * @effects comment_list_no_per_comment_post_query
     */
    public function test_comment_list_does_not_lazy_load_post_per_comment(): void
    {
        $postId = $this->createTestPost([
            'title' => '정상글(쿼리 실측)',
            'status' => 'published',
            'is_secret' => false,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->createTestComment($postId, ['content' => "댓글 {$i}"]);
        }
        $post = Post::find($postId);
        $comments = app(CommentService::class)
            ->getCommentsByPostId($this->board->slug, $postId, 'user');

        // 컨트롤러 index 가 넘기는 부모 post 속성
        $request = Request::create($this->commentsUrl($postId), 'GET');
        $request->attributes->set('sirsoft_board_parent_post', $post);
        app()->instance('request', $request);

        DB::enableQueryLog();
        CommentResource::collection($comments)->toArray($request);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $postQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'board_posts'));
        fwrite(STDERR, "\n[PERF] board_posts 쿼리 ".count($postQueries).'건 / 댓글 '.$comments->count()."건\n");

        // 컨트롤러가 부모 post 를 요청 속성으로 넘기므로 Resource 해석은 board_posts 를 한 번도
        // 조회하지 않아야 한다(정확히 0건). ≤1 로 두면 단일 post 재조회 회귀를 놓친다.
        $this->assertSame(
            0,
            count($postQueries),
            '댓글 목록 Resource 해석에서 부모 post 를 조회하면 안 됩니다(N+1). 컨트롤러가 넘긴 post 재사용 → 0건'
        );
    }

    /**
     * 2차 방어가 부모 post 컨텍스트 없이는(상세/생성 경로 하위호환) relationLoaded('post') 로
     * 폴백함을 고정 — 정상 뷰어(작성자)는 마스킹되지 않는다.
     *
     * @scenario viewer=owner
     *
     * @effects owner_second_defense_not_masked
     */
    public function test_second_defense_does_not_mask_for_owner(): void
    {
        $postId = $this->secretPostWithComment();
        $post = Post::find($postId);
        $comment = Comment::where('post_id', $postId)->first();

        $this->actingAs($this->ownerUser, 'sanctum');
        $request = Request::create($this->commentsUrl($postId), 'GET');
        $request->setUserResolver(fn () => $this->ownerUser);
        $request->attributes->set('sirsoft_board_parent_post', $post);
        app()->instance('request', $request);

        $arr = (new CommentResource($comment))->toArray($request);

        $this->assertNotNull($arr['content'], '작성자 본인에게는 비밀글 댓글 원문이 노출되어야 합니다');
    }
}
