<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

class BoardTypeManagementTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath() . '/database/migrations',
                '--realpath' => true,
            ]);
        }

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.create',
        ]);
    }

    /**
     * 게시판 유형 목록 조회 테스트
     */
    public function test_can_list_board_types(): void
    {
        BoardType::create([
            'slug' => 'test_type_a',
            'name' => ['ko' => '유형 A', 'en' => 'Type A'],
        ]);
        BoardType::create([
            'slug' => 'test_type_b',
            'name' => ['ko' => '유형 B', 'en' => 'Type B'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/board-types');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'slug', 'name'],
            ],
        ]);

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('test_type_a'));
        $this->assertTrue($slugs->contains('test_type_b'));
    }

    /**
     * 목록이 id 순으로 정렬되는지 테스트
     */
    public function test_list_returns_sorted_by_id(): void
    {
        $typeC = BoardType::create([
            'slug' => 'test_sort_c',
            'name' => ['ko' => 'C'],
        ]);
        $typeA = BoardType::create([
            'slug' => 'test_sort_a',
            'name' => ['ko' => 'A'],
        ]);
        $typeB = BoardType::create([
            'slug' => 'test_sort_b',
            'name' => ['ko' => 'B'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/board-types');

        $response->assertStatus(200);
        $data = $response->json('data');
        $testSlugs = collect($data)->filter(fn ($item) => str_starts_with($item['slug'], 'test_sort_'))->values();

        // id 순 정렬 (생성 순서: c → a → b)
        $this->assertEquals('test_sort_c', $testSlugs[0]['slug']);
        $this->assertEquals('test_sort_a', $testSlugs[1]['slug']);
        $this->assertEquals('test_sort_b', $testSlugs[2]['slug']);
    }

    /**
     * 게시판 유형 생성 테스트
     */
    public function test_can_create_board_type(): void
    {
        $data = [
            'slug' => 'test-new-type',
            'name' => ['ko' => '새 유형', 'en' => 'New Type'],
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/board-types', $data);

        $response->assertStatus(201);
        $response->assertJsonFragment(['slug' => 'test-new-type']);
        $this->assertDatabaseHas('board_types', ['slug' => 'test-new-type']);
    }

    /**
     * slug 유효성 검증 테스트
     */
    public function test_create_validates_slug_format(): void
    {
        $data = [
            'slug' => 'Test-Invalid',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/board-types', $data);

        $response->assertStatus(422);
    }

    /**
     * 중복 slug 검증 테스트
     */
    public function test_create_validates_unique_slug(): void
    {
        BoardType::create([
            'slug' => 'test-existing',
            'name' => ['ko' => '기존 유형', 'en' => 'Existing'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/board-types', [
                'slug' => 'test-existing',
                'name' => ['ko' => '중복 유형', 'en' => 'Duplicate'],
            ]);

        $response->assertStatus(422);
    }

    /**
     * name.ko 필수 검증 테스트
     */
    public function test_create_validates_name_ko_required(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withHeader('Accept-Language', 'ko')
            ->postJson('/api/modules/sirsoft-board/admin/board-types', [
                'slug' => 'test-no-name',
                'name' => ['en' => 'No Korean Name'],
            ]);

        $response->assertStatus(422);
    }

    /**
     * 게시판 유형 수정 테스트
     */
    public function test_can_update_board_type(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_update',
            'name' => ['ko' => '수정 전', 'en' => 'Before Update'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}", [
                'name' => ['ko' => '수정 후', 'en' => 'After Update'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name.ko', '수정 후');
    }

    /**
     * 존재하지 않는 유형 수정 시 404 테스트
     */
    public function test_update_nonexistent_type_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/board-types/99999', [
                'name' => ['ko' => '없는 유형', 'en' => 'Not Found'],
            ]);

        $response->assertStatus(404);
    }

    /**
     * 게시판 유형 삭제 테스트
     */
    public function test_can_delete_unused_board_type(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_delete',
            'name' => ['ko' => '삭제 테스트'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('board_types', ['id' => $boardType->id]);
    }

    /**
     * 사용 중인 유형 삭제 실패 테스트
     */
    public function test_cannot_delete_board_type_in_use(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_in_use',
            'name' => ['ko' => '사용중 유형'],
        ]);

        Board::factory()->create(['type' => 'test_in_use']);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('board_types', ['id' => $boardType->id]);
    }

    /**
     * 기본값으로 설정된 유형 삭제 실패 테스트
     */
    public function test_cannot_delete_default_board_type(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_default_type',
            'name' => ['ko' => '기본 유형'],
        ]);

        $settingsService = app(BoardSettingsService::class);
        $settingsService->setSetting('basic_defaults.type', 'test_default_type');

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('board_types', ['id' => $boardType->id]);
    }

    /**
     * 존재하지 않는 유형 삭제 시 404 테스트
     */
    public function test_delete_nonexistent_type_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-board/admin/board-types/99999');

        $response->assertStatus(404);
    }

    /**
     * 비인증 사용자 접근 차단 테스트
     */
    public function test_unauthenticated_user_cannot_access(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/admin/board-types');

        $response->assertStatus(401);
    }

    /**
     * getFormData에 board_types가 포함되는지 테스트
     */
    public function test_board_form_data_includes_board_types(): void
    {
        BoardType::create([
            'slug' => 'test_formdata',
            'name' => ['ko' => '폼 테스트 유형'],
        ]);

        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        $readPerm = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.boards.read'],
            ['name' => ['ko' => '게시판 조회', 'en' => 'Read Boards'], 'type' => 'admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$readPerm->id]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'board_types',
            ],
        ]);

        $types = collect($response->json('data.board_types'));
        $this->assertTrue($types->contains('slug', 'test_formdata'));
    }
}
