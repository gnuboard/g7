<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * Board 모듈 권한 스코프 테스트
 *
 * 미들웨어 scope_type 기반 상세 접근 체크 및 applyPermissionScope 목록 필터링을 검증합니다.
 */
class PermissionScopeTest extends ModuleTestCase
{
    private Permission $permission;

    private Role $testRole;

    private User $scopeUser;

    private User $otherUser;

    private User $sameRoleUser;

    /** @var int[] 생성된 사용자 ID 목록 (수동 정리용) */
    private array $createdUserIds = [];

    /**
     * DatabaseTransactions 비활성화 (DDL implicit commit 호환성)
     *
     * @return void
     */
    public function beginDatabaseTransaction(): void
    {
        // Board 모듈은 ALTER TABLE (파티션) 등 DDL이 트랜잭션을 자동 커밋하므로 비활성화
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();

        // 이전 실행 잔여 데이터 정리
        Permission::where('identifier', 'like', 'test.board.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.posts.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.comments.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.reports.%')->forceDelete();
        Role::where('identifier', 'test_board_scope_role')->forceDelete();

        // 테스트용 권한 생성
        $this->permission = Permission::create([
            'identifier' => 'test.board.scope',
            'name' => ['ko' => '게시판 스코프 테스트', 'en' => 'Board Scope Test'],
            'type' => PermissionType::Admin,
            'resource_route_key' => 'board',
            'owner_key' => 'created_by',
        ]);

        // 사용자 생성
        $this->scopeUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sameRoleUser = User::factory()->create();
        $this->createdUserIds = [$this->scopeUser->id, $this->otherUser->id, $this->sameRoleUser->id];

        // 역할 생성 및 할당
        $this->testRole = Role::create([
            'identifier' => 'test_board_scope_role',
            'name' => ['ko' => '보드 스코프 역할', 'en' => 'Board Scope Role'],
            'is_active' => true,
        ]);
        $this->testRole->permissions()->attach($this->permission->id, ['scope_type' => ScopeType::Self]);
        $this->scopeUser->roles()->attach($this->testRole->id);
        $this->sameRoleUser->roles()->attach($this->testRole->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();

        // 수동 데이터 정리 (DDL implicit commit으로 인해 트랜잭션 롤백 불가)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        if (! empty($this->createdUserIds)) {
            DB::table('user_roles')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        if (isset($this->testRole)) {
            DB::table('role_permissions')->where('role_id', $this->testRole->id)->delete();
            DB::table('roles')->where('id', $this->testRole->id)->delete();
        }

        Permission::where('identifier', 'like', 'test.board.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.posts.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.comments.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.reports.%')->forceDelete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Board (owner_key='created_by')
    // ========================================================================

    /**
     * Board — scope=self, 자기가 만든 게시판 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_board(): void
    {
        $this->setupScopePermission('board', 'created_by', ScopeType::Self);
        $board = Board::forceCreate([
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'slug' => 'board-'.uniqid(),
            'created_by' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('board', 'self-own-board', Board::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$board->id}");

        $response->assertStatus(200);
    }

    /**
     * Board — scope=self, 타인이 만든 게시판 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_board(): void
    {
        $this->setupScopePermission('board', 'created_by', ScopeType::Self);
        $board = Board::forceCreate([
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'slug' => 'board-'.uniqid(),
            'created_by' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('board', 'self-deny-board', Board::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$board->id}");

        $response->assertStatus(403);
    }

    /**
     * Board — scope=role, 동일 역할 사용자가 만든 게시판 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_board(): void
    {
        $this->setupScopePermission('board', 'created_by', ScopeType::Role);
        $board = Board::forceCreate([
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'slug' => 'board-'.uniqid(),
            'created_by' => $this->sameRoleUser->id,
        ]);
        $path = $this->registerScopeRoute('board', 'role-board', Board::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$board->id}");

        $response->assertStatus(200);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Post (owner_key='user_id')
    // ========================================================================

    /**
     * Post — scope=self, 자기 게시글 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_post(): void
    {
        $this->setupScopePermission('post', 'user_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $post = Post::create([
            'board_id' => $board->id,
            'title' => '테스트 게시글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('post', 'self-own-post', Post::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$post->id}");

        $response->assertStatus(200);
    }

    /**
     * Post — scope=self, 타인 게시글 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_post(): void
    {
        $this->setupScopePermission('post', 'user_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $post = Post::create([
            'board_id' => $board->id,
            'title' => '타인 게시글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('post', 'self-deny-post', Post::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$post->id}");

        $response->assertStatus(403);
    }

    /**
     * Post — scope=role, 동일 역할 사용자의 게시글 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_post(): void
    {
        $this->setupScopePermission('post', 'user_id', ScopeType::Role);
        $board = $this->createBoard($this->sameRoleUser->id);
        $post = Post::create([
            'board_id' => $board->id,
            'title' => '동일역할 게시글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->sameRoleUser->id,
        ]);
        $path = $this->registerScopeRoute('post', 'role-post', Post::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$post->id}");

        $response->assertStatus(200);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Comment (owner_key='user_id')
    // ========================================================================

    /**
     * Comment — scope=self, 자기 댓글 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_comment(): void
    {
        $this->setupScopePermission('comment', 'user_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $post = Post::create([
            'board_id' => $board->id,
            'title' => '게시글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->scopeUser->id,
        ]);
        $comment = Comment::create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'content' => '댓글',
            'depth' => 0,
            'user_id' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('comment', 'self-own-comment', Comment::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$comment->id}");

        $response->assertStatus(200);
    }

    /**
     * Comment — scope=self, 타인 댓글 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_comment(): void
    {
        $this->setupScopePermission('comment', 'user_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $post = Post::create([
            'board_id' => $board->id,
            'title' => '게시글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->scopeUser->id,
        ]);
        $comment = Comment::create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'content' => '타인 댓글',
            'depth' => 0,
            'user_id' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('comment', 'self-deny-comment', Comment::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$comment->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Report (owner_key='author_id')
    // ========================================================================

    /**
     * Report — scope=self, 자기 콘텐츠에 대한 신고 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_report(): void
    {
        $this->setupScopePermission('report', 'author_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $report = Report::create([
            'target_type' => 'post',
            'target_id' => 1,
            'reporter_id' => $this->otherUser->id,
            'reason_type' => 'spam',
            'reason_detail' => '',
            'snapshot' => [],
            'board_id' => $board->id,
            'author_id' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('report', 'self-own-report', Report::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$report->id}");

        $response->assertStatus(200);
    }

    /**
     * Report — scope=self, 타인 콘텐츠에 대한 신고 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_report(): void
    {
        $this->setupScopePermission('report', 'author_id', ScopeType::Self);
        $board = $this->createBoard($this->scopeUser->id);
        $report = Report::create([
            'target_type' => 'post',
            'target_id' => 1,
            'reporter_id' => $this->scopeUser->id,
            'reason_type' => 'spam',
            'reason_detail' => '',
            'snapshot' => [],
            'board_id' => $board->id,
            'author_id' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('report', 'self-deny-report', Report::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$report->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Board
    // ========================================================================

    /**
     * Board — scope=null → 전체 게시판 조회
     */
    public function test_apply_scope_null_returns_all_boards(): void
    {
        $this->setScopeType(null);
        $this->permission->update(['resource_route_key' => 'board', 'owner_key' => 'created_by']);
        $this->clearPermissionCache();

        $initialCount = Board::count();
        Board::forceCreate(['name' => ['ko' => '게시판1', 'en' => 'B1'], 'slug' => 'b-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Board::forceCreate(['name' => ['ko' => '게시판2', 'en' => 'B2'], 'slug' => 'b-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Board::query();
        PermissionHelper::applyPermissionScope($query, 'test.board.scope', $this->scopeUser);

        // scope=null이면 쿼리 변경 없음 → 전체 조회
        $this->assertSame($initialCount + 2, $query->count());
    }

    /**
     * Board — scope=self → 자기가 만든 게시판만 조회
     */
    public function test_apply_scope_self_filters_own_boards(): void
    {
        $this->permission->update(['resource_route_key' => 'board', 'owner_key' => 'created_by']);
        $this->clearPermissionCache();

        Board::forceCreate(['name' => ['ko' => '게시판1', 'en' => 'B1'], 'slug' => 'b-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Board::forceCreate(['name' => ['ko' => '게시판2', 'en' => 'B2'], 'slug' => 'b-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Board::query();
        PermissionHelper::applyPermissionScope($query, 'test.board.scope', $this->scopeUser);

        // scope=self → scopeUser가 만든 게시판만 (최소 1개)
        $this->assertTrue($query->count() >= 1);
        $this->assertTrue($query->where('created_by', $this->scopeUser->id)->count() === $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Post
    // ========================================================================

    /**
     * Post — scope=self → 자기 게시글만 조회
     */
    public function test_apply_scope_self_filters_own_posts(): void
    {
        $this->createPermissionWithScope('test.posts.read', 'post', 'user_id', ScopeType::Self);

        $board = $this->createBoard($this->scopeUser->id);
        Post::create(['board_id' => $board->id, 'title' => '내 글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->scopeUser->id]);
        Post::create(['board_id' => $board->id, 'title' => '타인 글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->otherUser->id]);

        $query = Post::query()->where('board_id', $board->id);
        PermissionHelper::applyPermissionScope($query, 'test.posts.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * Post — scope=role → 동일 역할 사용자의 게시글만 조회
     */
    public function test_apply_scope_role_filters_same_role_posts(): void
    {
        $this->createPermissionWithScope('test.posts.read', 'post', 'user_id', ScopeType::Role);

        $board = $this->createBoard($this->scopeUser->id);
        Post::create(['board_id' => $board->id, 'title' => '내 글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->scopeUser->id]);
        Post::create(['board_id' => $board->id, 'title' => '동일역할 글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->sameRoleUser->id]);
        Post::create(['board_id' => $board->id, 'title' => '타인 글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->otherUser->id]);

        $query = Post::query()->where('board_id', $board->id);
        PermissionHelper::applyPermissionScope($query, 'test.posts.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Comment
    // ========================================================================

    /**
     * Comment — scope=self → 자기 댓글만 조회
     */
    public function test_apply_scope_self_filters_own_comments(): void
    {
        $this->createPermissionWithScope('test.comments.read', 'comment', 'user_id', ScopeType::Self);

        $board = $this->createBoard($this->scopeUser->id);
        $post = Post::create(['board_id' => $board->id, 'title' => '글', 'content' => '', 'ip_address' => '127.0.0.1', 'user_id' => $this->scopeUser->id]);

        Comment::create(['board_id' => $board->id, 'post_id' => $post->id, 'content' => '내 댓글', 'depth' => 0, 'user_id' => $this->scopeUser->id]);
        Comment::create(['board_id' => $board->id, 'post_id' => $post->id, 'content' => '타인 댓글', 'depth' => 0, 'user_id' => $this->otherUser->id]);

        $query = Comment::query()->where('board_id', $board->id);
        PermissionHelper::applyPermissionScope($query, 'test.comments.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Report
    // ========================================================================

    /**
     * Report — scope=self → 자기 콘텐츠에 대한 신고만 조회
     */
    public function test_apply_scope_self_filters_own_reports(): void
    {
        $this->createPermissionWithScope('test.reports.read', 'report', 'author_id', ScopeType::Self);

        $board = $this->createBoard($this->scopeUser->id);

        Report::create([
            'target_type' => 'post', 'target_id' => 1, 'reporter_id' => $this->otherUser->id,
            'reason_type' => 'spam', 'reason_detail' => '', 'snapshot' => [],
            'board_id' => $board->id, 'author_id' => $this->scopeUser->id,
        ]);
        Report::create([
            'target_type' => 'post', 'target_id' => 2, 'reporter_id' => $this->scopeUser->id,
            'reason_type' => 'spam', 'reason_detail' => '', 'snapshot' => [],
            'board_id' => $board->id, 'author_id' => $this->otherUser->id,
        ]);

        $query = Report::query()->where('board_id', $board->id);
        PermissionHelper::applyPermissionScope($query, 'test.reports.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 테스트용 게시판 생성 헬퍼
     *
     * @param  int  $createdBy  생성자 ID
     * @return Board 생성된 게시판
     */
    private function createBoard(int $createdBy): Board
    {
        $board = Board::forceCreate([
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'slug' => 'board-'.uniqid(),
            'created_by' => $createdBy,
        ]);

        // Post/Comment INSERT를 위해 LIST 파티션 생성 필수
        $this->ensureBoardPartitions($board->id);

        return $board;
    }

    /**
     * 테스트용 권한에 scope 설정 헬퍼
     *
     * @param  string  $routeKey  resource_route_key 값
     * @param  string  $ownerKey  owner_key 값
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setupScopePermission(string $routeKey, string $ownerKey, ?ScopeType $scopeType): void
    {
        $this->permission->update([
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->syncWithoutDetaching([
            $this->permission->id => ['scope_type' => $scopeType],
        ]);

        $this->clearPermissionCache();
    }

    /**
     * 기본 permission의 scope_type 변경
     *
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setScopeType(?ScopeType $scopeType): void
    {
        $this->testRole->permissions()->syncWithoutDetaching([
            $this->permission->id => ['scope_type' => $scopeType],
        ]);
        $this->clearPermissionCache();
    }

    /**
     * 별도 권한 생성 및 역할에 할당하는 헬퍼
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $routeKey  resource_route_key
     * @param  string|null  $ownerKey  owner_key
     * @param  ScopeType|null  $scopeType  scope_type
     * @return Permission 생성된 권한
     */
    private function createPermissionWithScope(string $identifier, ?string $routeKey, ?string $ownerKey, ?ScopeType $scopeType): Permission
    {
        $perm = Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'type' => PermissionType::Admin,
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->attach($perm->id, ['scope_type' => $scopeType]);
        $this->clearPermissionCache();

        return $perm;
    }

    /**
     * 모델 바인딩 포함 테스트 라우트 등록 헬퍼
     *
     * @param  string  $routeKey  라우트 파라미터명
     * @param  string  $suffix  라우트 경로 구분용 접미사
     * @param  string  $modelClass  바인딩할 모델 FQCN
     * @return string 등록된 라우트 경로 (파라미터 제외)
     */
    private function registerScopeRoute(string $routeKey, string $suffix, string $modelClass): string
    {
        Route::bind($routeKey, fn ($value) => $modelClass::findOrFail($value));

        $path = "/api/test-board-scope-{$suffix}";
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.board.scope'])
            ->get("{$path}/{{$routeKey}}", fn (Model $model) => response()->json(['message' => 'OK']));

        return $path;
    }

    /**
     * PermissionHelper static 캐시 초기화
     *
     * @return void
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
