<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 모듈 서비스 레이어 권한 스코프 테스트
 *
 * Service 메서드에서 scope_type 기반 접근 제어가 올바르게 동작하는지 검증합니다.
 * - PostService: 목록 필터링 + 상세 접근 체크
 * - CommentService: 목록 필터링
 * - AttachmentService: 다운로드 접근 체크
 * - 비인증 사용자: 스코프 체크 스킵
 * - getBoardBySlug User 컨텍스트: 500 에러 방지
 */
class BoardPermissionScopeServiceTest extends ModuleTestCase
{
    private PostService $postService;

    private CommentService $commentService;

    private BoardService $boardService;

    private Role $testRole;

    private User $scopeUser;

    private User $otherUser;

    private User $sameRoleUser;

    private Board $board;

    /** @var int[] 생성된 사용자 ID 목록 (수동 정리용) */
    private array $createdUserIds = [];

    /** @var string[] 생성된 권한 식별자 패턴 (수동 정리용) */
    private array $createdPermissionPatterns = [];

    /**
     * DatabaseTransactions 비활성화 유지 (수동 정리 경로 보존).
     */
    public function beginDatabaseTransaction(): void
    {
        // 수동 정리 모드
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();

        // 이전 실행 잔여 데이터 정리
        Permission::where('identifier', 'like', 'sirsoft-board.test-scope%.%')->forceDelete();
        Role::where('identifier', 'test_scope_service_role')->forceDelete();

        // 서비스 인스턴스 획득
        $this->postService = app(PostService::class);
        $this->commentService = app(CommentService::class);
        $this->boardService = app(BoardService::class);

        // 사용자 생성
        $this->scopeUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sameRoleUser = User::factory()->create();
        $this->createdUserIds = [$this->scopeUser->id, $this->otherUser->id, $this->sameRoleUser->id];

        // 역할 생성
        $this->testRole = Role::create([
            'identifier' => 'test_scope_service_role',
            'name' => ['ko' => '스코프 서비스 역할', 'en' => 'Scope Service Role'],
            'is_active' => true,
        ]);

        // 역할 할당 (scopeUser, sameRoleUser)
        $this->scopeUser->roles()->attach($this->testRole->id);
        $this->sameRoleUser->roles()->attach($this->testRole->id);

        // 게시판 생성
        $this->board = Board::forceCreate([
            'name' => ['ko' => '스코프 테스트', 'en' => 'Scope Test'],
            'slug' => 'test-scope-' . uniqid(),
            'created_by' => $this->scopeUser->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 게시판 관련 데이터 정리
        if (isset($this->board)) {
            $boardId = $this->board->id;
            DB::table('board_attachments')->where('board_id', $boardId)->delete();
            DB::table('board_comments')->where('board_id', $boardId)->delete();
            DB::table('board_posts')->where('board_id', $boardId)->delete();
            DB::table('boards')->where('id', $boardId)->delete();
        }

        // 사용자 정리
        if (! empty($this->createdUserIds)) {
            DB::table('user_roles')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        // 역할 정리
        if (isset($this->testRole)) {
            DB::table('role_permissions')->where('role_id', $this->testRole->id)->delete();
            DB::table('roles')->where('id', $this->testRole->id)->delete();
        }

        // 권한 정리
        Permission::where('identifier', 'like', 'sirsoft-board.test-scope%.%')->forceDelete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    // ========================================================================
    // PostService — 목록 스코프 필터링
    // ========================================================================

    /**
     * PostService — scope=self, User 컨텍스트에서 본인 게시글만 반환되어야 합니다.
     */
    public function test_post_list_scope_self_returns_own_posts_only(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', ScopeType::Self);

        // 게시글 생성
        $this->createPost($this->scopeUser->id, '내 게시글');
        $this->createPost($this->otherUser->id, '타인 게시글');

        Auth::login($this->scopeUser);

        $result = $this->postService->getPosts($this->board->slug, [], 15, false, 'user');

        $this->assertSame(1, $result->count());
        $this->assertSame('내 게시글', $result->items()[0]->title);
    }

    /**
     * PostService — scope=role, User 컨텍스트에서 동일 역할 사용자의 게시글만 반환되어야 합니다.
     */
    public function test_post_list_scope_role_returns_same_role_posts(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', ScopeType::Role);

        // 게시글 생성
        $this->createPost($this->scopeUser->id, '내 게시글');
        $this->createPost($this->sameRoleUser->id, '동일역할 게시글');
        $this->createPost($this->otherUser->id, '타인 게시글');

        Auth::login($this->scopeUser);

        $result = $this->postService->getPosts($this->board->slug, [], 15, false, 'user');

        $this->assertSame(2, $result->count());
    }

    /**
     * PostService — scope=null, User 컨텍스트에서 전체 게시글이 반환되어야 합니다.
     */
    public function test_post_list_scope_null_returns_all_posts(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', null);

        $this->createPost($this->scopeUser->id, '내 게시글');
        $this->createPost($this->otherUser->id, '타인 게시글');

        Auth::login($this->scopeUser);

        $result = $this->postService->getPosts($this->board->slug, [], 15, false, 'user');

        $this->assertSame(2, $result->count());
    }

    // ========================================================================
    // PostService — 상세 스코프 체크
    // ========================================================================

    /**
     * PostService — scope=self, User 컨텍스트에서 타인 게시글 상세 조회 시 403이어야 합니다.
     */
    public function test_post_detail_scope_self_denies_other_post(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', ScopeType::Self);

        $post = $this->createPost($this->otherUser->id, '타인 게시글');

        Auth::login($this->scopeUser);

        $this->expectException(AccessDeniedHttpException::class);
        $this->postService->getPost($this->board->slug, $post->id, 'user');
    }

    /**
     * PostService — scope=self, User 컨텍스트에서 본인 게시글 상세 조회는 허용되어야 합니다.
     */
    public function test_post_detail_scope_self_allows_own_post(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', ScopeType::Self);

        $post = $this->createPost($this->scopeUser->id, '내 게시글');

        Auth::login($this->scopeUser);

        $result = $this->postService->getPost($this->board->slug, $post->id, 'user');

        $this->assertSame($post->id, $result->id);
    }

    // ========================================================================
    // CommentService — 목록 스코프 필터링
    // ========================================================================

    /**
     * CommentService — scope=self, User 컨텍스트에서 본인 댓글만 반환되어야 합니다.
     */
    public function test_comment_list_scope_self_returns_own_comments_only(): void
    {
        $this->createScopedPermission('comments.read', 'comment', 'user_id', ScopeType::Self);

        $post = $this->createPost($this->scopeUser->id, '게시글');

        // 댓글 생성
        $this->createComment($post->id, $this->scopeUser->id, '내 댓글');
        $this->createComment($post->id, $this->otherUser->id, '타인 댓글');

        Auth::login($this->scopeUser);

        $result = $this->commentService->getCommentsByPostId($this->board->slug, $post->id, 'user');

        $this->assertSame(1, $result->count());
        $this->assertSame('내 댓글', $result->first()->content);
    }

    // ========================================================================
    // 비인증 사용자 — 스코프 체크 스킵
    // ========================================================================

    /**
     * 비인증 사용자는 스코프 체크가 스킵되어 정상 접근이 가능해야 합니다.
     */
    public function test_guest_user_skips_scope_and_accesses_normally(): void
    {
        $this->createScopedPermission('posts.read', 'post', 'user_id', ScopeType::Self);

        $this->createPost($this->scopeUser->id, '게시글 1');
        $this->createPost($this->otherUser->id, '게시글 2');

        // 비인증 상태
        Auth::logout();

        $result = $this->postService->getPosts($this->board->slug, [], 15, false, 'user');

        // 비인증 사용자는 스코프 필터 미적용 → 전체 반환
        $this->assertSame(2, $result->count());
    }

    // ========================================================================
    // getBoardBySlug User 컨텍스트 — 500 에러 방지
    // ========================================================================

    /**
     * getBoardBySlug에 checkScope:false를 전달하면 스코프 체크를 하지 않아야 합니다.
     */
    public function test_get_board_by_slug_with_check_scope_false_returns_board(): void
    {
        Auth::login($this->scopeUser);

        $result = $this->boardService->getBoardBySlug($this->board->slug, checkScope: false);

        $this->assertNotNull($result);
        $this->assertSame($this->board->id, $result->id);
    }

    /**
     * User 컨트롤러에서 게시판 목록 조회 시 500 에러가 발생하지 않아야 합니다.
     */
    public function test_user_board_index_does_not_500(): void
    {
        Auth::login($this->scopeUser);

        $response = $this->actingAs($this->scopeUser)
            ->getJson('/api/modules/sirsoft-board/boards');

        $response->assertStatus(200);
    }

    // ========================================================================
    // Admin 컨텍스트 — 기본 scope 동작
    // ========================================================================

    /**
     * PostService — Admin 컨텍스트에서 scope=self일 때 본인 게시글만 반환되어야 합니다.
     */
    public function test_post_list_admin_context_scope_self(): void
    {
        $this->createScopedPermission('admin.posts.read', 'post', 'user_id', ScopeType::Self);

        $this->createPost($this->scopeUser->id, '내 게시글');
        $this->createPost($this->otherUser->id, '타인 게시글');

        Auth::login($this->scopeUser);

        $result = $this->postService->getPosts($this->board->slug, [], 15, false, 'admin');

        $this->assertSame(1, $result->count());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 스코프가 적용된 동적 권한을 생성하고 역할에 할당합니다.
     *
     * @param  string  $action  권한 액션 (예: 'posts.read', 'admin.posts.read')
     * @param  string  $routeKey  resource_route_key
     * @param  string  $ownerKey  owner_key
     * @param  ScopeType|null  $scopeType  scope_type
     * @return Permission 생성된 권한
     */
    private function createScopedPermission(string $action, string $routeKey, string $ownerKey, ?ScopeType $scopeType): Permission
    {
        $identifier = "sirsoft-board.{$this->board->slug}.{$action}";

        $permission = Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'type' => str_contains($action, 'admin.') ? PermissionType::Admin : PermissionType::User,
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->attach($permission->id, ['scope_type' => $scopeType]);
        $this->clearPermissionCache();

        return $permission;
    }

    /**
     * 테스트용 게시글을 생성합니다.
     *
     * @param  int  $userId  작성자 ID
     * @param  string  $title  제목
     * @return Post 생성된 게시글
     */
    private function createPost(int $userId, string $title): Post
    {
        return Post::create([
            'board_id' => $this->board->id,
            'title' => $title,
            'content' => '테스트 내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $userId,
        ]);
    }

    /**
     * 테스트용 댓글을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $userId  작성자 ID
     * @param  string  $content  내용
     * @return \Modules\Sirsoft\Board\Models\Comment 생성된 댓글
     */
    private function createComment(int $postId, int $userId, string $content): \Modules\Sirsoft\Board\Models\Comment
    {
        return \Modules\Sirsoft\Board\Models\Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => $content,
            'depth' => 0,
            'user_id' => $userId,
        ]);
    }

    /**
     * PermissionHelper static 캐시 초기화
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
