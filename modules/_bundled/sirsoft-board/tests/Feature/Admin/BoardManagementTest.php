<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
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
 * Note: 과거 파티션 DDL 호환성을 위해 DatabaseTransactions를 비활성화하고
 * tearDown에서 수동 정리 방식을 사용합니다. 파티션 폐지(beta.3) 후에도
 * tearDown 정리 경로를 유지해 기존 테스트 호환성을 보존합니다.
 * 테스트 인프라 정비는 후속 작업에서 진행합니다.
 */
class BoardManagementTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * DatabaseTransactions 비활성화 유지 (tearDown 수동 정리 경로 보존).
     */
    public function beginDatabaseTransaction(): void
    {
        // 수동 정리 모드
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
                '--path' => $this->getModuleBasePath().'/database/migrations',
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
            $boardPermIds = Permission::where('identifier', 'like', "sirsoft-board.{$slug}.%")
                ->pluck('id');
            if ($boardPermIds->isNotEmpty()) {
                DB::table('role_permissions')->whereIn('permission_id', $boardPermIds)->delete();
                Permission::whereIn('id', $boardPermIds)->delete();
            }

            $boardRoleIds = Role::where('identifier', 'like', "sirsoft-board.{$slug}.%")
                ->pluck('id');
            if ($boardRoleIds->isNotEmpty()) {
                DB::table('user_roles')->whereIn('role_id', $boardRoleIds)->delete();
                Role::whereIn('id', $boardRoleIds)->delete();
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
            'slug' => 'test-'.substr(md5(microtime()), 0, 8),
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
            'slug' => 'inact-'.substr(md5(microtime().'inactive'), 0, 8),
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
     * 관리자 게시판 목록에는 역할 소속 사용자 정보가 실리지 않는다 (#518 / 공개 #76).
     *
     * 종전에는 행마다 매니저/스텝 역할을 조회해 그 역할에 속한 **사용자 전원의 uuid·이름·이메일**을
     * 목록에 실었다. 목록 화면은 그 값을 쓰지 않으면서 행 수만큼 추가 쿼리가 나가고 개인정보가
     * 노출됐다. 역할 편집은 게시판 상세/설정 화면이 공급한다.
     *
     * @scenario endpoint=admin_list,role_assignment=assigned
     *
     * @effects admin_list_omits_role_member_payload
     */
    public function test_board_list_omits_role_member_payload(): void
    {
        Board::factory()->count(2)->create(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards');

        $response->assertStatus(200);

        foreach ($response->json('data.data') as $row) {
            foreach (['board_managers', 'board_steps', 'board_manager_ids', 'board_step_ids'] as $heavy) {
                $this->assertArrayNotHasKey($heavy, $row, "게시판 목록에 {$heavy} 가 실리면 안 된다");
            }
        }
    }

    /**
     * 관리자 목록 화면이 쓰는 표시 필드는 그대로 남는다 (기능 축소 아님).
     *
     * @scenario endpoint=admin_list,role_assignment=unassigned
     *
     * @effects admin_list_keeps_display_fields
     */
    public function test_board_list_keeps_admin_display_fields(): void
    {
        Board::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards');

        $response->assertStatus(200);

        $row = $response->json('data.data.0');

        foreach (['id', 'name', 'slug', 'type', 'is_active', 'categories', 'posts_count', 'abilities'] as $field) {
            $this->assertArrayHasKey($field, $row, "관리자 목록이 쓰는 {$field} 가 사라졌다");
        }
    }

    /**
     * 목록 응답 어디에도 사용자 이메일이 실리지 않는다.
     *
     * 키 이름만 검사하면 필드가 다른 이름으로 되살아났을 때 통과한다. 노출되면 안 되는 것은
     * 특정 키가 아니라 **값**이므로 응답 전문에서 이메일 문자열 자체를 찾는다.
     *
     * @effects admin_list_never_exposes_member_email
     */
    public function test_board_list_never_exposes_member_email(): void
    {
        $manager = User::factory()->create(['email' => 'board-manager-probe@example.com']);
        $board = Board::factory()->create(['is_active' => true]);

        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            ['name' => json_encode(['ko' => '매니저', 'en' => 'Manager']), 'is_active' => true]
        );
        $managerRole->users()->syncWithoutDetaching([$manager->id]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards');

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            'board-manager-probe@example.com',
            $response->getContent(),
            '게시판 목록 응답에 역할 소속 사용자의 이메일이 실렸다'
        );
    }

    /**
     * 목록에서 뺀 역할 배정은 상세 조회가 여전히 공급한다 (기능 축소 아님).
     *
     * @scenario endpoint=detail,role_assignment=assigned
     *
     * @effects detail_still_provides_role_assignments
     */
    public function test_board_detail_still_provides_role_assignments(): void
    {
        $manager = User::factory()->create();
        $board = Board::factory()->create(['is_active' => true]);

        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            ['name' => json_encode(['ko' => '매니저', 'en' => 'Manager']), 'is_active' => true]
        );
        $managerRole->users()->syncWithoutDetaching([$manager->id]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/boards/{$board->id}");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayHasKey('board_manager_ids', $data, '상세에서 역할 배정이 사라지면 설정 화면이 비어 열린다');
        $this->assertContains($manager->uuid, $data['board_manager_ids']);
    }

    /**
     * 역할이 배정되지 않은 게시판도 상세에서 필드 자체는 유지된다 (빈 배열).
     *
     * 필드가 통째로 사라지면 화면이 "아직 안 불러온 상태" 와 "배정이 없는 상태" 를 구분하지
     * 못한다.
     *
     * @scenario endpoint=detail,role_assignment=unassigned
     *
     * @effects detail_still_provides_role_assignments
     */
    public function test_board_detail_returns_empty_role_assignments_when_unassigned(): void
    {
        $board = Board::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/boards/{$board->id}");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayHasKey('board_manager_ids', $data);
        $this->assertSame([], $data['board_manager_ids']);
    }

    /**
     * 게시판 수가 늘어도 역할 조회가 늘지 않는다 (행당 재조회 부재).
     *
     * @effects role_query_count_does_not_scale_with_board_count
     */
    public function test_board_list_role_query_count_does_not_grow_with_rows(): void
    {
        Board::factory()->count(2)->create(['is_active' => true]);

        // 권한/설정 캐시 워밍 (측정 대상 제외)
        $this->actingAs($this->adminUser)->getJson('/api/modules/sirsoft-board/admin/boards')->assertOk();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->adminUser)->getJson('/api/modules/sirsoft-board/admin/boards')->assertOk();
        $baseline = count(array_filter($queries, fn (string $sql) => $this->isBoardRoleMemberQuery($sql)));

        Board::factory()->count(8)->create(['is_active' => true]);

        $queries = [];
        $this->actingAs($this->adminUser)->getJson('/api/modules/sirsoft-board/admin/boards')->assertOk();
        $grown = count(array_filter($queries, fn (string $sql) => $this->isBoardRoleMemberQuery($sql)));

        $this->assertSame(
            $baseline,
            $grown,
            "게시판 수에 비례해 역할 조회가 늘었다 (기준: {$baseline}, 증가 후: {$grown})"
        );
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
     * getFormData 생성 모드에서 blocked_keywords / allowed_extensions가 배열로 반환되는지 테스트
     *
     * TagInput(태그 입력) 컴포넌트는 배열 값을 요구한다. 생성 모드에서 문자열(콤마 join)로
     * 반환되면 폼이 깨지므로 categories 와 동일하게 배열로 주입되어야 한다.
     *
     * @effects form_data_create_mode_returns_fields_as_array
     */
    public function test_get_form_data_returns_blocked_keywords_and_allowed_extensions_as_array(): void
    {
        // When: 게시판 생성 폼 데이터 조회 (id 없이)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        // Then: blocked_keywords / allowed_extensions가 배열 타입으로 포함됨
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('blocked_keywords', $data);
        $this->assertArrayHasKey('allowed_extensions', $data);
        $this->assertIsArray($data['blocked_keywords']);
        $this->assertIsArray($data['allowed_extensions']);
    }

    /**
     * getFormData에 _meta (limits) 포함 테스트
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
            ],
        ]);

        $meta = $response->json('data._meta');
        $this->assertIsArray($meta['limits']);
        $this->assertArrayHasKey('max_reply_depth_min', $meta['limits']);
        $this->assertArrayHasKey('max_comment_depth_max', $meta['limits']);
    }

    /**
     * getFormData 수정 모드에서 blocked_keywords / allowed_extensions가 배열로 반환되는지 테스트
     *
     * BoardResource 가 두 필드를 문자열(콤마 join)이 아닌 배열로 반환해야 TagInput 이 값을
     * 정상적으로 칩(chip)으로 표시한다. (categories 와 동일한 배열 계약)
     *
     * @effects form_data_edit_mode_returns_fields_as_array
     */
    public function test_get_form_data_edit_mode_returns_blocked_keywords_and_allowed_extensions_as_array(): void
    {
        // Given: 제한 키워드/허용 확장자가 설정된 게시판
        $board = Board::factory()->create([
            'blocked_keywords' => ['욕설', '광고'],
            'allowed_extensions' => ['jpg', 'png'],
        ]);

        // When: 수정 모드로 폼 데이터 조회 (board_id 지정)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_id='.$board->id);

        // Then: 두 필드가 배열 타입으로 반환됨
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data['blocked_keywords']);
        $this->assertIsArray($data['allowed_extensions']);
        $this->assertSame(['욕설', '광고'], $data['blocked_keywords']);
        $this->assertSame(['jpg', 'png'], $data['allowed_extensions']);
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
     * 게시판 상세를 slug 로 조회할 수 있다 (#450 관리자 라우팅 slug 전환)
     */
    public function test_board_detail_can_be_fetched_by_slug(): void
    {
        // Given
        $board = Board::factory()->create(['is_active' => true]);

        // When: 숫자 id 가 아닌 slug 로 상세 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/boards/{$board->slug}");

        // Then: 동일 게시판 반환
        $response->assertStatus(200);
        $this->assertSame($board->id, $response->json('data.id'));
        $this->assertSame($board->slug, $response->json('data.slug'));
    }

    /**
     * 게시판을 slug 로 수정할 수 있다 (#450)
     */
    public function test_board_can_be_updated_by_slug(): void
    {
        // Given
        $board = Board::factory()->create(['is_active' => true]);

        // When: slug 로 PUT
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->slug}", [
                'is_active' => false,
            ]);

        // Then
        $response->assertStatus(200);
        $this->assertFalse($board->fresh()->is_active);
    }

    /**
     * 폼 데이터를 그대로 되돌려 저장해도 통과해야 한다 (이슈 #78 통합 회귀)
     *
     * 관리자 폼은 GET 응답 전체를 _local.form 에 통째로 주입하고 그대로 PUT 한다.
     * 따라서 요청에는 항상 모든 키가 존재하며, 무변경 저장이 검증에 막히면 안 된다.
     * 이 테스트가 "전체 객체 PUT" 이라는 프런트 계약을 백엔드에 고정한다.
     *
     * @dataProvider roundTripBoardAttributesProvider
     *
     * @scenario case=upload_disabled_extensions_null
     *
     * @effects unchanged_form_data_round_trip_saves
     *
     * @param  array<string, mixed>  $attributes  게시판 속성
     */
    public function test_form_data_round_trip_save_passes(array $attributes): void
    {
        // Given: 생성 API 로 만든 게시판 (board-scoped 역할/권한이 실제로 존재하는 상태)
        $slug = 'test-rt-'.substr(md5(microtime().serialize($attributes)), 0, 8);

        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', [
                'name' => ['ko' => '왕복 저장 테스트', 'en' => 'Round Trip Test'],
                'slug' => $slug,
                'type' => 'basic',
                'show_view_count' => true,
                'use_report' => false,
                'board_manager_ids' => [$this->adminUser->uuid],
            ])->assertStatus(201);

        // 검증 대상 상태를 DB 에 직접 반영 (레거시 데이터 재현)
        $board = Board::where('slug', $slug)->firstOrFail();
        $board->forceFill($attributes)->save();

        // When: 폼 데이터를 조회하고 받은 객체를 그대로 PUT
        $formResponse = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_slug='.$board->slug);
        $formResponse->assertStatus(200);

        $payload = $formResponse->json('data');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->slug}", $payload);

        // Then: 무변경 저장이 통과해야 함
        $response->assertStatus(200, '무변경 저장 실패: '.json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * 무변경 저장 회귀 대상 게시판 속성.
     *
     * @return array<string, array{array<string, mixed>}> 게시판 속성
     */
    public static function roundTripBoardAttributesProvider(): array
    {
        return [
            '첨부 미사용 + 확장자 NULL' => [[
                'use_file_upload' => false,
                'allowed_extensions' => null,
            ]],
            '첨부 미사용 + 확장자 빈 배열' => [[
                'use_file_upload' => false,
                'allowed_extensions' => [],
            ]],
            '첨부 미사용 + NEW 배지 끄기' => [[
                'use_file_upload' => false,
                'allowed_extensions' => null,
                'new_display_hours' => 0,
            ]],
            '첨부 사용 + 확장자 지정' => [[
                'use_file_upload' => true,
                'allowed_extensions' => ['jpg', 'png'],
            ]],
        ];
    }

    /**
     * 폼 데이터를 board_slug 로 수정 모드 조회할 수 있다 (#450)
     */
    public function test_get_form_data_edit_mode_by_board_slug(): void
    {
        // Given
        $board = Board::factory()->create(['is_active' => true]);

        // When: board_slug 쿼리로 폼 데이터 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_slug='.$board->slug);

        // Then: 해당 게시판 데이터가 로드됨
        $response->assertStatus(200);
        $this->assertSame($board->id, $response->json('data.id'));
    }

    /**
     * 게시판을 slug 로 관리자 메뉴에 추가할 수 있다 (#450 add-to-menu slug 바인딩)
     *
     * 라우트 파라미터가 {board} 이므로 컨트롤러가 Board route-model binding 으로
     * slug 를 해석해야 한다. int $id 로 받으면 slug 문자열이 전달될 때 실패한다.
     */
    public function test_add_to_admin_menu_resolves_board_by_slug(): void
    {
        // Given: 활성 게시판
        $board = Board::factory()->create([
            'slug' => 'test-'.substr(md5(microtime().'addmenu'), 0, 8),
            'is_active' => true,
        ]);

        // When: 숫자 id 가 아닌 slug 로 add-to-menu 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-board/admin/boards/{$board->slug}/add-to-menu");

        // Then: slug 가 정상 해석되어 메뉴가 추가됨 (route-model binding 성공)
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.menu'));
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

        $slug = 'role-'.substr(md5(time()), 0, 8);
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
        $managerRole = Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
        $this->assertNotNull($managerRole, 'Manager 역할이 생성되어야 합니다.');
        $this->assertTrue(
            $managerRole->users()->where('users.id', $managerUser->id)->exists(),
            'Manager 사용자가 역할에 연결되어야 합니다.'
        );

        // step 역할에 사용자가 연결되었는지 확인
        $stepRole = Role::where('identifier', "sirsoft-board.{$slug}.step")->first();
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
        $slug = 'perm-'.substr(md5(time()), 0, 8);
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
        $postsReadPerm = Permission::where('identifier', "sirsoft-board.{$slug}.posts.read")->first();
        $this->assertNotNull($postsReadPerm);
        $roleIdentifiers = $postsReadPerm->roles()->pluck('identifier')->toArray();

        $this->assertContains("sirsoft-board.{$slug}.manager", $roleIdentifiers, 'Manager 역할이 config 기본값으로 포함되어야 합니다.');
        $this->assertContains("sirsoft-board.{$slug}.step", $roleIdentifiers, 'Step 역할이 config 기본값으로 포함되어야 합니다.');

        // admin.manage 권한에는 manager만 포함 (step 제외)
        $adminManagePerm = Permission::where('identifier', "sirsoft-board.{$slug}.admin.manage")->first();
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
        $slug = 'custom-'.substr(md5(time().'custom'), 0, 8);
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
        $postsReadPerm = Permission::where('identifier', "sirsoft-board.{$slug}.posts.read")->first();
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
        $slug = 'role-'.substr(md5(microtime()), 0, 8);
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
        $managerRole = Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
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

        $stepRole = Role::where('identifier', "sirsoft-board.{$slug}.step")->first();
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
        $slug = 'role-'.substr(md5(microtime().'noname'), 0, 8);
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

        $managerRole = Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
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

    /**
     * 게시판 역할 **소속 사용자 조회** 쿼리인지 판정합니다.
     *
     * 권한 체크(`hasPermission`)도 같은 피벗 테이블을 참조하지만 그쪽은 `exists(...)` 서브쿼리이며,
     * 행마다 실행되는 그 N+1 은 별도 이슈(#519)가 소유한다. 이 테스트는 목록이 게시판마다 역할
     * 소속 사용자를 다시 끌어오지 않는지만 본다.
     *
     * @param  string  $sql  실행된 SQL
     * @return bool 역할 소속 사용자 조회 여부
     */
    private function isBoardRoleMemberQuery(string $sql): bool
    {
        return str_contains($sql, 'user_roles') && ! str_contains($sql, 'exists(');
    }
}
