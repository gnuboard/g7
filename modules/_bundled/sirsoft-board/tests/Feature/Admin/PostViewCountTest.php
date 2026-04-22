<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시글 조회수 기능 테스트
 *
 * 조회수 증가 정책:
 * - 캐시 기반 중복 방지 (view_count_cache_ttl 초 동안 유지, 기본 86400초)
 * - 본인 글 조회 시에도 조회수 증가
 * - Admin 페이지에서도 조회수 증가
 */
class PostViewCountTest extends ModuleTestCase
{
    private User $adminUser;

    private Board $board;

    private PostService $postService;

    private int $postId;

    /**
     * DatabaseTransactions 비활성화 유지 (수동 정리 경로 보존).
     *
     * - createTestPost(): 테스트 전 기존 게시글 DELETE
     * - createTestBoard(): Board/Permission/Role은 firstOrCreate로 중복 방지
     * - createTestUser(): faker로 매번 새 사용자 생성 (이메일 중복 없음)
     */
    public function beginDatabaseTransaction(): void
    {
        // 수동 정리 모드
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        $this->postService = app(PostService::class);

        // 테스트 사용자 및 게시판 생성
        $this->createTestUser();
        $this->createTestBoard();
        $this->createTestPost();
    }

    /**
     * 테스트 사용자 생성
     */
    private function createTestUser(): void
    {
        // 하드코딩 이메일 미사용: DDL 암묵적 커밋으로 인해 이전 테스트 데이터가
        // DatabaseTransactions에 의해 롤백되지 않을 수 있으므로 faker 사용
        $this->adminUser = User::factory()->create();
    }

    /**
     * 테스트 게시판 생성 및 권한 설정
     *
     * DatabaseTransactions 비활성화 상태에서 firstOrCreate로 중복 방지하며
     * 게시판/권한/역할을 재사용합니다.
     */
    private function createTestBoard(): void
    {
        $this->board = Board::firstOrCreate(
            ['slug' => 'viewcount-test'],
            [
                'name' => '조회수 테스트 게시판',
                'type' => 'list',
                'per_page' => 20,
                'per_page_mobile' => 10,
                'order_by' => 'created_at',
                'order_direction' => 'DESC',
                'secret_mode' => 'disabled',
                'use_comment' => true,
                'use_reply' => false,
                'use_file_upload' => false,
                'permissions' => [],
                'notify_admin_on_post' => false,
                'notify_author_on_comment' => false,
            ]
        );


        // 권한 생성: DB::table 직접 삽입으로 Eloquent enum cast 문제 완전 우회
        // Permission Eloquent 모델은 type(PermissionType enum) cast 시
        // getDirty()에서 'type' 컬럼을 누락시키는 문제가 있으므로
        // DB::table로 직접 INSERT하고 Eloquent로 조회만 합니다.
        $permissionIdentifier = 'sirsoft-board.viewcount-test.admin.posts.read';
        if (! DB::table('permissions')->where('identifier', $permissionIdentifier)->exists()) {
            DB::table('permissions')->insert([
                'identifier' => $permissionIdentifier,
                'name' => json_encode(['ko' => '조회', 'en' => 'View']),
                'type' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $viewPermission = Permission::where('identifier', $permissionIdentifier)->first();

        // 역할 생성: DB::table 직접 삽입으로 동일 방식 사용
        $roleIdentifier = 'viewcount-tester';
        if (! DB::table('roles')->where('identifier', $roleIdentifier)->exists()) {
            DB::table('roles')->insert([
                'identifier' => $roleIdentifier,
                'name' => json_encode(['ko' => '조회 테스터', 'en' => 'View Count Tester']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $role = Role::where('identifier', $roleIdentifier)->first();
        $role->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $this->adminUser->roles()->syncWithoutDetaching([$role->id]);
    }

    /**
     * 테스트 게시글 생성
     *
     * 이전 테스트에서 커밋된 잔여 게시글을 먼저 삭제 후 신규 생성합니다.
     * (DDL 암묵적 커밋으로 인해 이전 게시글이 남아있을 수 있음)
     */
    private function createTestPost(): void
    {
        // 잔여 게시글 정리 (이전 테스트의 DDL 커밋으로 남은 데이터)
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();

        $this->postId = DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '조회수 테스트 게시글',
            'content' => '조회수 테스트를 위한 게시글입니다.',
            'user_id' => $this->adminUser->id,
            'author_name' => $this->adminUser->name,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 현재 조회수를 가져옵니다.
     */
    private function getViewCount(): int
    {
        return (int) DB::table('board_posts')->where('id', $this->postId)->value('view_count');
    }

    // ==========================================
    // 캐시 기반 조회수 테스트
    // ==========================================

    /**
     * 최초 조회 시 조회수가 1 증가해야 합니다.
     */
    public function test_it_increments_view_count_on_first_view(): void
    {
        $this->assertEquals(0, $this->getViewCount());

        $result = $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->getViewCount());
    }

    /**
     * 캐시가 있는 동안 중복 조회 시 조회수가 증가하지 않아야 합니다.
     */
    public function test_it_does_not_increment_view_count_on_duplicate_view_within_cache_period(): void
    {
        $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);
        $this->assertEquals(1, $this->getViewCount());

        $result = $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);

        $this->assertFalse($result);
        $this->assertEquals(1, $this->getViewCount());
    }

    /**
     * 캐시 삭제 후 재조회 시 조회수가 증가해야 합니다.
     */
    public function test_it_increments_view_count_after_cache_flush(): void
    {
        // actingAs로 로그인 상태 설정: Auth::id()가 user id를 반환하도록 하여
        // 캐시 키가 IP 대신 user id 기반으로 생성되게 합니다.
        $this->actingAs($this->adminUser);
        $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);
        $this->assertEquals(1, $this->getViewCount());

        // 캐시 삭제 (TTL 만료 시뮬레이션)
        $identifier = $this->adminUser->id;
        // PostService 는 ModuleCacheDriver 사용 → key 에 `g7:module.sirsoft-board:` prefix 포함
        Cache::forget("g7:module.sirsoft-board:post_view_{$this->board->slug}_{$this->postId}_{$identifier}");

        $result = $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);

        $this->assertTrue($result);
        $this->assertEquals(2, $this->getViewCount());
    }

    // ==========================================
    // 공통 테스트
    // ==========================================

    /**
     * 다른 게시글 조회 시에는 조회수가 증가해야 합니다.
     */
    public function test_it_increments_view_count_for_different_posts(): void
    {
        $secondPostId = DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '두 번째 게시글',
            'content' => '두 번째 게시글입니다.',
            'user_id' => $this->adminUser->id,
            'author_name' => $this->adminUser->name,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);
        $result = $this->postService->incrementViewCountOnce($this->board->slug, $secondPostId);

        $this->assertTrue($result);
        $secondViewCount = (int) DB::table('board_posts')->where('id', $secondPostId)->value('view_count');
        $this->assertEquals(1, $secondViewCount);
    }

    /**
     * 본인이 작성한 글 조회 시에도 조회수가 증가해야 합니다.
     */
    public function test_it_increments_view_count_for_own_post(): void
    {
        $this->assertEquals(0, $this->getViewCount());

        $result = $this->postService->incrementViewCountOnce($this->board->slug, $this->postId);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->getViewCount());
    }

    // ==========================================
    // API 통합 테스트
    // ==========================================

    /**
     * 게시글 상세 조회 API 호출 시 조회수가 증가해야 합니다.
     */
    public function test_it_increments_view_count_when_calling_show_api(): void
    {
        $this->assertEquals(0, $this->getViewCount());

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->postId}");

        $response->assertStatus(200);
        $this->assertEquals(1, $this->getViewCount());
    }

    /**
     * 캐시 유효 기간 내 API 여러 번 호출해도 조회수는 1만 증가해야 합니다.
     */
    public function test_it_increments_view_count_only_once_within_cache_period_via_api(): void
    {
        $this->assertEquals(0, $this->getViewCount());

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->adminUser)
                ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->postId}")
                ->assertStatus(200);
        }

        $this->assertEquals(1, $this->getViewCount());
    }

    /**
     * 조회수가 응답 데이터에 포함되어야 합니다.
     */
    public function test_it_includes_view_count_in_api_response(): void
    {
        DB::table('board_posts')
            ->where('id', $this->postId)
            ->update(['view_count' => 10]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->postId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.view_count', 11);
    }
}
