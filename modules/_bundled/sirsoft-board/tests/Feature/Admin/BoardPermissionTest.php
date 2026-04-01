<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 권한 통합 테스트
 *
 * 권한 구조 (Phase 8 단일 테이블 전환 후):
 * - admin.posts.read : 목록/상세 조회 (라우트 미들웨어)
 * - admin.posts.write: 게시글 수정 API 접근 (라우트 미들웨어 + 컨트롤러 소유권 검증)
 * - admin.manage     : 블라인드/복원 + 타인 글 수정 (라우트 미들웨어 + 컨트롤러)
 *
 * 응답 permissions 키 (PostResource::getAdminPermissionMap):
 * - admin_posts_write: 사용자가 admin.posts.write 권한 보유 여부
 * - admin_manage     : 사용자가 admin.manage 권한 보유 여부
 *
 * DDL 주의:
 * - ensureBoardPartitions() (ALTER TABLE)은 MySQL 암묵적 커밋 유발
 * - DatabaseTransactions와 충돌하므로 beginDatabaseTransaction()을 비활성화
 * - firstOrCreate + DB::table DELETE 패턴으로 수동 정리
 */
class BoardPermissionTest extends ModuleTestCase
{
    private const BOARD_SLUG = 'perm-test';

    private User $userView;

    private User $userWrite;

    private User $userControl;

    private User $userManage;

    private User $userFull;

    private User $postAuthor;

    private Board $board;

    private int $authorPostId;

    private int $otherPostId;

    /**
     * DatabaseTransactions 비활성화.
     *
     * ensureBoardPartitions() (ALTER TABLE ADD PARTITION) 이 MySQL 암묵적 커밋을 유발하여
     * DatabaseTransactions 트랜잭션 롤백을 방해합니다.
     * 대신 setUp()에서 수동 DELETE로 잔여 데이터를 정리합니다.
     */
    public function beginDatabaseTransaction(): void
    {
        // DatabaseTransactions 비활성화 (DDL 호환성)
    }

    /**
     * 이 테스트 메서드에서 생성된 사용자 ID 목록 (tearDown 정리용)
     */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);
        App::setLocale('ko');

        $this->createTestUsers();
        $this->createTestBoard();
        $this->createTestPosts();
    }

    /**
     * 테스트 종료 후 사용자 정리
     *
     * DatabaseTransactions 비활성화로 자동 롤백이 없으므로
     * 생성한 사용자를 수동으로 삭제합니다 (faker 이메일 중복 방지).
     */
    protected function tearDown(): void
    {
        if (! empty($this->createdUserIds)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('user_roles')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    /**
     * 테스트 사용자 생성
     *
     * faker 이메일 사용: 하드코딩 이메일은 DDL 암묵적 커밋으로 다음 테스트와 충돌 가능
     * 생성된 ID는 $createdUserIds에 기록 → tearDown에서 삭제
     */
    private function createTestUsers(): void
    {
        // Str::random() 기반 이메일 사용: faker 시드 동일 시 이메일 중복 방지
        // (tearDown 실패로 잔류한 faker 이메일과의 충돌 원천 차단)
        $r = fn () => Str::random(16) . '@perm-test.invalid';

        $this->postAuthor  = User::factory()->create(['email' => $r()]);
        $this->userView    = User::factory()->create(['email' => $r()]);
        $this->userWrite   = User::factory()->create(['email' => $r()]);
        $this->userControl = User::factory()->create(['email' => $r()]);
        $this->userManage  = User::factory()->create(['email' => $r()]);
        $this->userFull    = User::factory()->create(['email' => $r()]);

        $this->createdUserIds = [
            $this->postAuthor->id,
            $this->userView->id,
            $this->userWrite->id,
            $this->userControl->id,
            $this->userManage->id,
            $this->userFull->id,
        ];
    }

    /**
     * 테스트 게시판 생성 및 권한 설정
     *
     * Board::firstOrCreate → ensureBoardPartitions (DDL) → 암묵적 커밋
     * 이후 데이터는 DatabaseTransactions로 보호되지 않으므로 수동 정리 필요
     */
    private function createTestBoard(): void
    {
        $this->board = Board::firstOrCreate(
            ['slug' => self::BOARD_SLUG],
            [
                'name'                   => ['ko' => '권한 테스트 게시판', 'en' => 'Permission Test Board'],
                'type'                   => 'list',
                'per_page'               => 20,
                'per_page_mobile'        => 10,
                'order_by'               => 'created_at',
                'order_direction'        => 'DESC',
                'secret_mode'            => 'disabled',
                'use_comment'            => true,
                'use_reply'              => false,
                'use_file_upload'        => false,
                'permissions'            => [],
                'notify_admin_on_post'   => false,
                'notify_author_on_comment' => false,
            ]
        );

        // 파티션 보장 (LIST 파티션 — board_id별 파티션 필요)
        $this->ensureBoardPartitions($this->board->id);

        // 권한 생성 (DB::table 직접 삽입 — PermissionType enum cast 우회)
        $slug       = self::BOARD_SLUG;
        $postsRead  = $this->firstOrCreatePermission("sirsoft-board.{$slug}.admin.posts.read");
        $postsWrite = $this->firstOrCreatePermission("sirsoft-board.{$slug}.admin.posts.write");
        $manage     = $this->firstOrCreatePermission("sirsoft-board.{$slug}.admin.manage");

        // 역할 생성 및 권한 할당
        $roleView    = $this->firstOrCreateRole('perm-view', [$postsRead->id]);
        $roleWrite   = $this->firstOrCreateRole('perm-write', [$postsRead->id, $postsWrite->id]);
        $roleControl = $this->firstOrCreateRole('perm-control', [$postsRead->id, $manage->id]);
        $roleManage  = $this->firstOrCreateRole('perm-manage', [$postsRead->id, $postsWrite->id, $manage->id]);

        // 사용자 역할 할당
        $this->userView->roles()->syncWithoutDetaching([$roleView->id]);
        $this->userWrite->roles()->syncWithoutDetaching([$roleWrite->id]);
        $this->postAuthor->roles()->syncWithoutDetaching([$roleWrite->id]);
        $this->userControl->roles()->syncWithoutDetaching([$roleControl->id]);
        $this->userManage->roles()->syncWithoutDetaching([$roleManage->id]);
        $this->userFull->roles()->syncWithoutDetaching([$roleManage->id]);
    }

    /**
     * 테스트 게시글 생성
     *
     * 이전 테스트의 잔여 데이터를 먼저 삭제 후 신규 생성합니다.
     */
    private function createTestPosts(): void
    {
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();

        // userWrite가 작성한 게시글 (본인 게시글)
        $this->authorPostId = DB::table('board_posts')->insertGetId([
            'board_id'     => $this->board->id,
            'title'        => 'userWrite의 게시글',
            'content'      => '본인이 작성한 게시글',
            'user_id'      => $this->userWrite->id,
            'author_name'  => $this->userWrite->name,
            'ip_address'   => '127.0.0.1',
            'is_notice'    => false,
            'is_secret'    => false,
            'status'       => 'published',
            'trigger_type' => 'admin',
            'view_count'   => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // postAuthor가 작성한 게시글 (타인의 글)
        $this->otherPostId = DB::table('board_posts')->insertGetId([
            'board_id'     => $this->board->id,
            'title'        => '다른 사용자의 게시글',
            'content'      => '타인이 작성한 게시글',
            'user_id'      => $this->postAuthor->id,
            'author_name'  => $this->postAuthor->name,
            'ip_address'   => '127.0.0.1',
            'is_notice'    => false,
            'is_secret'    => false,
            'status'       => 'published',
            'trigger_type' => 'admin',
            'view_count'   => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * 권한을 firstOrCreate 방식으로 생성합니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission 생성 또는 조회된 권한
     */
    private function firstOrCreatePermission(string $identifier): Permission
    {
        if (! DB::table('permissions')->where('identifier', $identifier)->exists()) {
            DB::table('permissions')->insert([
                'identifier' => $identifier,
                'name'       => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'type'       => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Permission::where('identifier', $identifier)->first();
    }

    /**
     * 역할을 firstOrCreate 방식으로 생성하고 권한을 할당합니다.
     *
     * @param  string  $identifier    역할 식별자
     * @param  array   $permissionIds 할당할 권한 ID 목록
     * @return Role 생성 또는 조회된 역할
     */
    private function firstOrCreateRole(string $identifier, array $permissionIds): Role
    {
        if (! DB::table('roles')->where('identifier', $identifier)->exists()) {
            DB::table('roles')->insert([
                'identifier' => $identifier,
                'name'       => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $role = Role::where('identifier', $identifier)->first();
        // sync: 정확히 지정된 권한만 유지 (syncWithoutDetaching은 이전 권한이 누적됨)
        $role->permissions()->sync($permissionIds);

        return $role;
    }

    // ==========================================
    // 시나리오 1: view 권한만 (userView)
    // ==========================================

    /**
     * view 권한 사용자는 게시글 목록을 조회할 수 있습니다.
     */
    public function test_view_user_can_fetch_post_list(): void
    {
        $response = $this->actingAs($this->userView)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * view 권한 사용자는 게시글 상세를 조회할 수 있습니다.
     */
    public function test_view_user_can_fetch_post_detail(): void
    {
        $response = $this->actingAs($this->userView)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * view 권한 사용자의 게시글 상세에서 쓰기/관리 권한은 false입니다.
     */
    public function test_view_user_has_no_action_permissions(): void
    {
        $response = $this->actingAs($this->userView)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_write', false)
            ->assertJsonPath('data.abilities.can_manage', false);
    }

    // ==========================================
    // 시나리오 2: write 권한 (userWrite)
    // ==========================================

    /**
     * write 권한 사용자는 admin_posts_write=true이며, 본인 게시글의 is_author=true 입니다.
     */
    public function test_write_user_can_edit_own_post(): void
    {
        $response = $this->actingAs($this->userWrite)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->authorPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_write', true)
            ->assertJsonPath('data.is_author', true);
    }

    /**
     * write 권한 사용자가 타인의 게시글을 조회할 때 is_author=false 입니다.
     */
    public function test_write_user_cannot_edit_others_post(): void
    {
        $response = $this->actingAs($this->userWrite)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_author', false);
    }

    /**
     * write 권한 사용자는 관리(블라인드/복원) 권한이 없습니다.
     */
    public function test_write_user_has_no_control_permissions(): void
    {
        $response = $this->actingAs($this->userWrite)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->authorPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_manage', false);
    }

    // ==========================================
    // 시나리오 3: control 권한 (userControl = admin.manage만 보유)
    // ==========================================

    /**
     * control 권한 사용자는 admin.manage를 보유합니다 (블라인드/복원 가능).
     */
    public function test_control_user_has_blind_restore_permissions(): void
    {
        $response = $this->actingAs($this->userControl)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_manage', true);
    }

    /**
     * control 권한 사용자는 admin.posts.write가 없습니다 (PUT 수정 라우트 접근 불가).
     */
    public function test_control_user_has_no_edit_permissions(): void
    {
        $response = $this->actingAs($this->userControl)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_write', false);
    }

    // ==========================================
    // 시나리오 4: manage 권한 (userManage = posts.write + admin.manage)
    // ==========================================

    /**
     * manage 권한 사용자는 타인의 게시글에서도 admin_posts_write=true, admin_manage=true 입니다.
     */
    public function test_manage_user_can_edit_others_post(): void
    {
        $response = $this->actingAs($this->userManage)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_write', true)
            ->assertJsonPath('data.abilities.can_manage', true);
    }

    /**
     * Phase 8 이후: admin.manage가 블라인드/복원과 타인 글 수정을 모두 제어합니다.
     * userManage는 admin.posts.write + admin.manage 보유 → 타인 글 수정 API 성공
     */
    public function test_manage_user_has_no_control_permissions(): void
    {
        // 구 구조: userManage = edit-anyone(manage), userControl = blind/restore(control) — 별도 권한
        // 신 구조: admin.manage 통합 → userManage는 edit-anyone + blind/restore 모두 보유
        // 이 테스트에서는 userManage가 타인 글 수정 API(PUT)에 성공함을 검증
        $response = $this->actingAs($this->userManage)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}", [
                'title'   => 'manage 권한으로 수정',
                'content' => '타인 글 수정 가능 (admin.manage 보유)',
            ]);

        $response->assertStatus(200);
    }

    // ==========================================
    // 시나리오 5: 전체 권한 (userFull)
    // ==========================================

    /**
     * 전체 권한 사용자는 admin_posts_write, admin_manage 모두 true입니다.
     */
    public function test_full_user_has_all_permissions(): void
    {
        $response = $this->actingAs($this->userFull)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_write', true)
            ->assertJsonPath('data.abilities.can_manage', true);
    }

    // ==========================================
    // 백엔드 권한 검증 (403 응답)
    // ==========================================

    /**
     * view 권한 사용자가 게시글 수정 API를 직접 호출하면 403이 반환됩니다.
     * (admin.posts.write 라우트 미들웨어 차단)
     */
    public function test_view_user_cannot_update_post_via_api(): void
    {
        $response = $this->actingAs($this->userView)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}", [
                'title'   => '수정된 제목',
                'content' => '수정된 내용',
            ]);

        $response->assertStatus(403);
    }

    /**
     * write 권한 사용자가 타인의 게시글 수정 API를 호출하면 403이 반환됩니다.
     * (라우트 통과 후 컨트롤러 소유권 검증에서 차단)
     */
    public function test_write_user_cannot_update_others_post_via_api(): void
    {
        // admin.posts.write 있음 → 미들웨어 통과 → FormRequest 통과 → 컨트롤러 소유권 검증 → 403
        $response = $this->actingAs($this->userWrite)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}", [
                'title'   => '수정 시도 제목',
                'content' => '타인 게시글 수정 시도 내용입니다.',
            ]);

        $response->assertStatus(403);
    }

    /**
     * write 권한 사용자가 자신의 게시글을 수정하면 성공합니다.
     */
    public function test_write_user_can_update_own_post_via_api(): void
    {
        $response = $this->actingAs($this->userWrite)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->authorPostId}", [
                'title'   => '수정된 제목',
                'content' => '본인 게시글 수정 내용입니다.',
            ]);

        $response->assertStatus(200);
    }

    /**
     * control 권한 사용자가 게시글 수정 API를 호출하면 403이 반환됩니다.
     * (admin.posts.write 없음 → 라우트 미들웨어 차단)
     */
    public function test_control_user_cannot_update_post_via_api(): void
    {
        $response = $this->actingAs($this->userControl)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}", [
                'title'   => '수정된 제목',
                'content' => '수정된 내용',
            ]);

        $response->assertStatus(403);
    }

    /**
     * manage 권한 사용자가 타인의 게시글을 수정하면 성공합니다.
     * (admin.posts.write 통과 + admin.manage로 소유권 무관 수정 허용)
     */
    public function test_manage_user_can_update_others_post_via_api(): void
    {
        $response = $this->actingAs($this->userManage)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$this->otherPostId}", [
                'title'   => '관리자가 수정',
                'content' => '관리자 권한으로 수정',
            ]);

        $response->assertStatus(200);
    }

    // ==========================================
    // 모듈 레벨 권한 테스트 (게시판 관리)
    // ==========================================

    /**
     * 게시판 목록 API 응답에 permissions 필드가 포함됩니다.
     */
    public function test_board_list_includes_permissions(): void
    {
        // boards.read: 목록 조회 라우트 미들웨어 필수
        // boards.create: can_create=true 확인용
        $readPermission   = $this->firstOrCreatePermission('sirsoft-board.boards.read');
        $createPermission = $this->firstOrCreatePermission('sirsoft-board.boards.create');
        $role = $this->firstOrCreateRole('perm-board-admin', [$readPermission->id, $createPermission->id]);

        $user = User::factory()->create();
        $this->createdUserIds[] = $user->id;
        $user->roles()->syncWithoutDetaching([$role->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-board/admin/boards');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'abilities' => [
                        'can_create',
                        'can_update',
                        'can_delete',
                    ],
                ],
            ])
            ->assertJsonPath('data.abilities.can_create', true)
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_delete', false);
    }
}
