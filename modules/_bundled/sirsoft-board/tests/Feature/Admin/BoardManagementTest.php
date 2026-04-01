<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 관리자 게시판 관리 테스트
 *
 * 게시판 CRUD 및 is_active 필드 테스트
 *
 * DDL 주의:
 * - BoardService::createBoard()가 내부적으로 ALTER TABLE ADD PARTITION (DDL)을 실행
 * - DDL은 MySQL 암묵적 커밋을 유발하여 DatabaseTransactions 롤백을 방해
 * - beginDatabaseTransaction()을 비활성화하고 tearDown에서 수동 정리
 */
class BoardManagementTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * DatabaseTransactions 비활성화.
     *
     * BoardService::createBoard() 내부의 ALTER TABLE ADD PARTITION (DDL)이
     * MySQL 암묵적 커밋을 유발하여 DatabaseTransactions 롤백을 방해합니다.
     * 대신 tearDown()에서 수동 DELETE로 잔여 데이터를 정리합니다.
     */
    public function beginDatabaseTransaction(): void
    {
        // DatabaseTransactions 비활성화 (DDL 호환성)
    }

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // board_types 테이블이 없으면 마이그레이션 실행
        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath() . '/database/migrations',
                '--realpath' => true,
            ]);
        }

        // 기본 게시판 유형 생성 (board 생성 시 type 검증에 필요)
        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );

        // 관리자 사용자 생성 (게시판 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.create',
            'sirsoft-board.boards.update',
            'sirsoft-board.boards.delete',
        ]);
    }

    /**
     * 테스트 정리
     *
     * DatabaseTransactions 비활성화로 자동 롤백이 없으므로
     * 각 테스트에서 생성된 데이터를 FK 의존성 역순으로 수동 삭제합니다.
     */
    protected function tearDown(): void
    {
        // 1. 게시판 slug 패턴으로 생성된 board-scoped 역할/권한 정리
        $boardSlugs = Board::where('slug', 'like', 'test-%')
            ->orWhere('slug', 'like', 'inact-%')
            ->orWhere('slug', 'like', 'role-%')
            ->orWhere('slug', 'like', 'perm-%')
            ->orWhere('slug', 'like', 'custom-%')
            ->orWhere('slug', 'original-slug')
            ->pluck('slug');

        foreach ($boardSlugs as $slug) {
            // board-scoped 권한/역할 정리 (sirsoft-board.{slug}.* 패턴)
            $boardPermIds = \App\Models\Permission::where('identifier', 'like', "sirsoft-board.{$slug}.%")
                ->pluck('id');
            if ($boardPermIds->isNotEmpty()) {
                DB::table('role_permissions')->whereIn('permission_id', $boardPermIds)->delete();
                \App\Models\Permission::whereIn('id', $boardPermIds)->delete();
            }

            $boardRoleIds = \App\Models\Role::where('identifier', 'like', "sirsoft-board.{$slug}.%")
                ->pluck('id');
            if ($boardRoleIds->isNotEmpty()) {
                DB::table('user_roles')->whereIn('role_id', $boardRoleIds)->delete();
                \App\Models\Role::whereIn('id', $boardRoleIds)->delete();
            }
        }

        // 2. 게시판 삭제
        Board::where('slug', 'like', 'test-%')
            ->orWhere('slug', 'like', 'inact-%')
            ->orWhere('slug', 'like', 'role-%')
            ->orWhere('slug', 'like', 'perm-%')
            ->orWhere('slug', 'like', 'custom-%')
            ->orWhere('slug', 'original-slug')
            ->delete();

        // 3. 이 테스트에서 생성한 adminUser 정리
        if (isset($this->adminUser) && $this->adminUser->exists) {
            $userId = $this->adminUser->id;
            DB::table('role_permissions')->where('granted_by', $userId)->delete();
            DB::table('user_roles')->where('user_id', $userId)->delete();
            $this->adminUser->delete();
        }

        parent::tearDown();
    }

    /**
     * 게시판 생성 시 is_active 기본값 true 테스트
     */
    public function test_board_created_with_default_is_active_true(): void
    {
        // Given: is_active를 지정하지 않은 게시판 데이터
        $data = [
            'name' => ['ko' => '테스트 게시판', 'en' => 'Test Board'],
            'slug' => 'test-' . substr(md5(microtime()), 0, 8),
            'type' => 'basic',
            'description' => ['ko' => '테스트 설명', 'en' => 'Test Description'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
            // is_active 미지정 (기본값 true 기대)
        ];

        // When: 게시판 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);

        // Then: 생성 성공, is_active가 true로 설정됨
        $response->assertStatus(201);
        $this->assertTrue($response->json('data.is_active'));
    }

    /**
     * is_active false로 게시판 생성 테스트
     */
    public function test_board_created_with_is_active_false(): void
    {
        // Given: is_active를 false로 지정한 게시판 데이터
        $data = [
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
            'slug' => 'inact-' . substr(md5(microtime() . 'inactive'), 0, 8),
            'type' => 'basic',
            'description' => ['ko' => '비활성 설명', 'en' => 'Inactive Description'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
            'is_active' => false,
        ];

        // When: 게시판 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);

        // Then: 생성 성공, is_active가 false로 설정됨
        $response->assertStatus(201);
        $this->assertFalse($response->json('data.is_active'));
    }

    /**
     * is_active 수정 테스트 (true → false)
     */
    public function test_board_is_active_can_be_updated_to_false(): void
    {
        // Given: 활성화된 게시판 생성
        $board = Board::factory()->create(['is_active' => true]);

        // When: is_active를 false로 변경
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->id}", [
                'is_active' => false,
            ]);

        // Then: 수정 성공, is_active가 false로 변경됨
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_active'));

        // DB에서도 변경되었는지 확인
        $this->assertFalse($board->fresh()->is_active);
    }

    /**
     * is_active 수정 테스트 (false → true)
     */
    public function test_board_is_active_can_be_updated_to_true(): void
    {
        // Given: 비활성화된 게시판 생성
        $board = Board::factory()->create(['is_active' => false]);

        // When: is_active를 true로 변경
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->id}", [
                'is_active' => true,
            ]);

        // Then: 수정 성공, is_active가 true로 변경됨
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_active'));

        // DB에서도 변경되었는지 확인
        $this->assertTrue($board->fresh()->is_active);
    }

    /**
     * 게시판 목록 조회 시 is_active 필드 포함 테스트
     */
    public function test_board_list_includes_is_active_field(): void
    {
        // Given: 활성화/비활성화 게시판 각 1개씩 생성
        Board::factory()->create(['is_active' => true]);
        Board::factory()->create(['is_active' => false]);

        // When: 게시판 목록 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards');

        // Then: 모든 게시판에 is_active 필드 포함
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'is_active',
                    ],
                ],
            ],
        ]);
    }

    /**
     * getFormData에 is_active 기본값 포함 테스트 (생성 모드)
     */
    public function test_get_form_data_includes_is_active_default_true(): void
    {
        // When: 게시판 생성 폼 데이터 조회 (id 없이)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        // Then: is_active가 true로 포함됨
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'is_active' => true,
            ],
        ]);
    }

    /**
     * getFormData에 _meta (limits, notification_channels) 포함 테스트
     */
    public function test_get_form_data_includes_meta_with_limits_and_depth_fields(): void
    {
        // When: 게시판 생성 폼 데이터 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        // Then: _meta에 limits가 포함되고 depth 설정이 있음
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '_meta' => [
                    'limits',
                ],
                'max_reply_depth',
                'max_comment_depth',
                'notify_author_channels',
                'notify_admin_on_post_channels',
            ],
        ]);

        $meta = $response->json('data._meta');
        $this->assertIsArray($meta['limits']);
        $this->assertArrayHasKey('max_reply_depth_min', $meta['limits']);
        $this->assertArrayHasKey('max_comment_depth_max', $meta['limits']);
    }

    /**
     * getFormData 생성 모드에서 알림 채널 기본값이 환경설정에서 로드되는지 테스트
     */
    public function test_get_form_data_create_mode_has_notification_channels_from_settings(): void
    {
        // When: 게시판 생성 폼 데이터 조회 (id 없이)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        // Then: 각 알림별 채널이 배열로 포함됨 (환경설정 기본값 반영)
        $response->assertStatus(200);
        $this->assertIsArray($response->json('data.notify_author_channels'));
        $this->assertIsArray($response->json('data.notify_admin_on_post_channels'));
    }

    /**
     * 게시판 상세 조회 시 is_active 필드 포함 테스트
     */
    public function test_board_detail_includes_is_active_field(): void
    {
        // Given: 게시판 생성
        $board = Board::factory()->create(['is_active' => true]);

        // When: 게시판 상세 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/boards/{$board->id}");

        // Then: is_active 필드 포함
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'slug',
                'is_active',
            ],
        ]);
        $this->assertTrue($response->json('data.is_active'));
    }

    /**
     * 부분 업데이트 시 is_active만 변경 가능 테스트
     */
    public function test_can_update_only_is_active_field(): void
    {
        // Given: 게시판 생성
        $board = Board::factory()->create([
            'name' => ['ko' => '원본 이름', 'en' => 'Original Name'],
            'slug' => 'original-slug',
            'is_active' => true,
        ]);

        // When: is_active만 변경
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->id}", [
                'is_active' => false,
            ]);

        // Then: is_active만 변경되고 다른 필드는 유지
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_active'));
        $this->assertEquals('original-slug', $response->json('data.slug'));
    }

    /**
     * 게시판 생성 시 관리자/스텝 사용자가 역할에 연결되는지 테스트
     */
    public function test_board_creation_assigns_users_to_manager_and_step_roles(): void
    {
        // Given: 관리자와 스텝 사용자
        $managerUser = User::factory()->create();
        $stepUser = User::factory()->create();

        $slug = 'role-' . substr(md5(time()), 0, 8);
        $data = [
            'name' => ['ko' => '역할 테스트 게시판', 'en' => 'Role Test Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$managerUser->uuid],
            'board_step_ids' => [$stepUser->uuid],
        ];

        // When: 게시판 생성
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);

        // Then: 생성 성공
        $response->assertStatus(201);

        // manager 역할에 사용자가 연결되었는지 확인
        $managerRole = \App\Models\Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
        $this->assertNotNull($managerRole, 'Manager 역할이 생성되어야 합니다.');
        $this->assertTrue(
            $managerRole->users()->where('users.id', $managerUser->id)->exists(),
            'Manager 사용자가 역할에 연결되어야 합니다.'
        );

        // step 역할에 사용자가 연결되었는지 확인
        $stepRole = \App\Models\Role::where('identifier', "sirsoft-board.{$slug}.step")->first();
        $this->assertNotNull($stepRole, 'Step 역할이 생성되어야 합니다.');
        $this->assertTrue(
            $stepRole->users()->where('users.id', $stepUser->id)->exists(),
            'Step 사용자가 역할에 연결되어야 합니다.'
        );
    }

    /**
     * 게시판 생성 시 권한에 manager/step 역할이 config 기본값으로 포함되는지 테스트
     */
    public function test_board_creation_auto_includes_manager_step_in_permissions(): void
    {
        // Given: 게시판 생성 데이터 (권한 설정 없음 = config 기본값 사용)
        $slug = 'perm-' . substr(md5(time()), 0, 8);
        $data = [
            'name' => ['ko' => '권한 테스트 게시판', 'en' => 'Perm Test Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
        ];

        // When: 게시판 생성
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);

        // Then: 생성 성공
        $response->assertStatus(201);

        // posts.read 권한에 manager/step 역할이 기본값으로 포함되었는지 확인
        $postsReadPerm = \App\Models\Permission::where('identifier', "sirsoft-board.{$slug}.posts.read")->first();
        $this->assertNotNull($postsReadPerm);
        $roleIdentifiers = $postsReadPerm->roles()->pluck('identifier')->toArray();

        $this->assertContains("sirsoft-board.{$slug}.manager", $roleIdentifiers, 'Manager 역할이 config 기본값으로 포함되어야 합니다.');
        $this->assertContains("sirsoft-board.{$slug}.step", $roleIdentifiers, 'Step 역할이 config 기본값으로 포함되어야 합니다.');

        // admin.manage 권한에는 manager만 포함 (step 제외)
        $adminManagePerm = \App\Models\Permission::where('identifier', "sirsoft-board.{$slug}.admin.manage")->first();
        $this->assertNotNull($adminManagePerm);
        $adminManageRoles = $adminManagePerm->roles()->pluck('identifier')->toArray();
        $this->assertContains("sirsoft-board.{$slug}.manager", $adminManageRoles, 'Manager 역할이 config 기본값으로 포함되어야 합니다.');
        $this->assertNotContains("sirsoft-board.{$slug}.step", $adminManageRoles, 'Step 역할은 admin.manage에서 제외되어야 합니다.');
    }

    /**
     * 사용자가 커스텀 권한을 설정해도 Manager/Step이 자동 포함되는지 테스트
     */
    public function test_custom_permissions_still_include_manager_step(): void
    {
        // Given: 게시판 생성 데이터 + 커스텀 권한 설정
        $slug = 'custom-' . substr(md5(time() . 'custom'), 0, 8);
        $data = [
            'name' => ['ko' => '커스텀 권한 게시판', 'en' => 'Custom Perm Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
            'permissions' => [
                'posts_read' => ['roles' => ['admin', 'user', 'guest']], // manager/step 의도적으로 제외
            ],
        ];

        // When: 게시판 생성
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);

        // Then: 생성 성공
        $response->assertStatus(201);

        // posts.read 권한에 사용자가 설정한 역할 + Manager/Step 자동 포함
        $postsReadPerm = \App\Models\Permission::where('identifier', "sirsoft-board.{$slug}.posts.read")->first();
        $this->assertNotNull($postsReadPerm);
        $roleIdentifiers = $postsReadPerm->roles()->pluck('identifier')->toArray();

        // 사용자가 설정한 역할 포함
        $this->assertContains('admin', $roleIdentifiers);
        $this->assertContains('user', $roleIdentifiers);
        $this->assertContains('guest', $roleIdentifiers);

        // Manager/Step 역할도 자동 포함
        $this->assertContains("sirsoft-board.{$slug}.manager", $roleIdentifiers, 'Manager 역할이 자동 추가되어야 합니다.');
        $this->assertContains("sirsoft-board.{$slug}.step", $roleIdentifiers, 'Step 역할이 자동 추가되어야 합니다.');
    }

    /**
     * getFormData 생성 모드에서 로그인 관리자가 기본 관리자로 지정되는지 테스트
     */
    public function test_get_form_data_includes_current_user_as_default_manager(): void
    {
        // When: 게시판 생성 폼 데이터 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        // Then: 로그인한 관리자가 board_manager_ids에 포함됨
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertContains($this->adminUser->uuid, $data['board_manager_ids']);
        $this->assertNotEmpty($data['board_managers']);
        $this->assertEquals($this->adminUser->uuid, $data['board_managers'][0]['uuid']);
        $this->assertEquals($this->adminUser->name, $data['board_managers'][0]['name']);
        $this->assertEquals($this->adminUser->email, $data['board_managers'][0]['email']);
    }

    /**
     * 게시판명 변경 시 연관 역할(manager/step)의 이름이 동기화되는지 테스트
     */
    public function test_board_name_update_syncs_role_names(): void
    {
        // Given: 게시판 생성 (manager/step 역할 자동 생성됨)
        $slug = 'role-' . substr(md5(microtime()), 0, 8);
        $data = [
            'name' => ['ko' => '원본 게시판', 'en' => 'Original Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);
        $response->assertStatus(201);
        $boardId = $response->json('data.id');

        // 역할이 원본 이름으로 생성되었는지 확인
        $managerRole = \App\Models\Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
        $this->assertNotNull($managerRole);
        $this->assertEquals('원본 게시판 게시판 관리자', $managerRole->name['ko']);
        $this->assertEquals('Original Board Board Manager', $managerRole->name['en']);

        // When: 게시판명 변경
        $updateResponse = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$boardId}", [
                'name' => ['ko' => '변경된 게시판', 'en' => 'Changed Board'],
            ]);
        $updateResponse->assertStatus(200);

        // Then: 역할명이 동기화되었는지 확인
        $managerRole->refresh();
        $this->assertEquals('변경된 게시판 게시판 관리자', $managerRole->name['ko']);
        $this->assertEquals('Changed Board Board Manager', $managerRole->name['en']);

        $stepRole = \App\Models\Role::where('identifier', "sirsoft-board.{$slug}.step")->first();
        $this->assertNotNull($stepRole);
        $this->assertEquals('변경된 게시판 게시판 스텝', $stepRole->name['ko']);
        $this->assertEquals('Changed Board Board Step', $stepRole->name['en']);
    }

    /**
     * 게시판명 미변경(이름 외 필드만 수정) 시 역할명이 변경되지 않는지 테스트
     */
    public function test_board_update_without_name_does_not_touch_roles(): void
    {
        // Given: 게시판 생성
        $slug = 'role-' . substr(md5(microtime() . 'noname'), 0, 8);
        $data = [
            'name' => ['ko' => '원본 게시판', 'en' => 'Original Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => [$this->adminUser->uuid],
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $data);
        $response->assertStatus(201);
        $boardId = $response->json('data.id');

        $managerRole = \App\Models\Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
        $this->assertNotNull($managerRole);
        $originalName = $managerRole->name;

        // When: 이름 외 다른 필드만 변경
        $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$boardId}", [
                'is_active' => false,
            ]);

        // Then: 역할명이 변경되지 않음
        $managerRole->refresh();
        $this->assertEquals($originalName, $managerRole->name);
    }
}
