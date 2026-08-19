<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\BoardTypeService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

class BoardTypeManagementTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath().'/database/migrations',
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

        // basic_defaults 는 파일 기반 전역 설정이라 DB 트랜잭션 롤백 대상이 아니다.
        // 원래 값을 보관했다가 테스트 종료 시 반드시 되돌린다 (미복원 시 개발/운영 설정 오염).
        $settingsService = app(BoardSettingsService::class);
        $originalType = $settingsService->getSettings('basic_defaults')['type'] ?? null;
        $settingsService->setSetting('basic_defaults.type', 'test_default_type');

        try {
            $response = $this->actingAs($this->adminUser)
                ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

            $response->assertStatus(422);
            $this->assertDatabaseHas('board_types', ['id' => $boardType->id]);
        } finally {
            $settingsService->setSetting('basic_defaults.type', $originalType);
        }
    }

    /**
     * 삭제 불가 응답의 메시지가 예외 원문이 아니라 해석된 다국어 문구여야 한다.
     *
     * 종전에는 예외 메시지(이미 번역된 문장)를 다시 메시지 키 자리에 넘겨서, 키 해석에
     * 실패한 원문이 그대로 사용자 화면에 나갔다. 상태코드만 보는 단언은 이 결함을
     * 통과시킨다 — 422 는 그대로였기 때문이다.
     *
     * @scenario error_class=domain
     *
     * @effects board_type_delete_domain_exception_returns_422_with_resolved_message
     */
    public function test_delete_in_use_response_message_is_resolved_key_not_exception_text(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_in_use_msg',
            'name' => ['ko' => '사용중 유형'],
        ]);

        Board::factory()->create(['type' => 'test_in_use_msg']);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

        $response->assertStatus(422);

        $expected = __('sirsoft-board::messages.board_type.delete_in_use', ['count' => 1]);
        $this->assertSame($expected, $response->json('message'));
        // 키 문자열 자체가 노출되면(해석 실패) 그것도 결함이다
        $this->assertStringNotContainsString('sirsoft-board::', (string) $response->json('message'));
    }

    /**
     * 인프라 예외는 422 로 뭉개지 않고 500 으로 구분하며 예외 원문을 노출하지 않는다.
     *
     * @scenario error_class=infrastructure
     *
     * @effects board_type_delete_infrastructure_exception_returns_500
     */
    public function test_delete_infrastructure_exception_returns_500(): void
    {
        $boardType = BoardType::create([
            'slug' => 'test_infra_500',
            'name' => ['ko' => '인프라 예외 유형'],
        ]);

        $this->mock(BoardTypeService::class, function ($mock) {
            $mock->shouldReceive('deleteBoardType')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}");

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
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

        $adminRole = Role::where('identifier', 'admin')->first();
        $readPerm = Permission::firstOrCreate(
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
