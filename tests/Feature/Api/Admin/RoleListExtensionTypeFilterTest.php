<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 역할 목록 API — 소유 구분 필터(`extension_type`).
 *
 * 역할 관리 화면의 「구분」 선택 상자가 쓰는 쿼리다. core(시스템)·module·plugin 은 그 주체가 만든 역할만,
 * custom 은 관리자가 직접 만든 역할(extension_type 이 비어 있는 행)만 돌려주고, 그 밖의 값은 422 로 거부한다.
 * 시드한 코어 역할 위에서 트랜잭션으로 격리한다.
 */
class RoleListExtensionTypeFilterTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;

    /**
     * 코어 역할·권한을 심고, 비교용 모듈 역할·직접 만든 역할과 슈퍼 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Role::create([
            'identifier' => 'filter_module_role',
            'name' => ['ko' => '모듈 역할', 'en' => 'Module Role'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'is_active' => true,
        ]);
        Role::create([
            'identifier' => 'filter_custom_role',
            'name' => ['ko' => '직접 만든 역할', 'en' => 'Custom Role'],
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create(['is_super' => true]);
        $this->superAdmin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now()]);
    }

    /**
     * 필터를 걸어 목록을 받아 행의 식별자와 소유 구분을 돌려줍니다.
     *
     * @param  string  $type  extension_type 쿼리 값
     * @return array{identifiers: array<int, string>, types: array<int, string|null>} 식별자 목록과 소유 구분 종류
     */
    private function listBy(string $type): array
    {
        $response = $this->actingAs($this->superAdmin)->getJson("/api/admin/roles?extension_type={$type}&per_page=100");
        $response->assertStatus(200);
        $rows = collect($response->json('data.data'));

        return [
            'identifiers' => $rows->pluck('identifier')->all(),
            'types' => $rows->pluck('extension_type')->unique()->values()->all(),
        ];
    }

    /**
     * core 는 시스템(코어) 역할만 돌려준다 — 모듈 역할·직접 만든 역할은 빠진다.
     *
     * @scenario extension_type=core
     *
     * @effects filters_core_roles_only
     *
     * @return void 반환값 없음
     */
    public function test_core_returns_only_system_roles(): void
    {
        $result = $this->listBy('core');

        $this->assertSame(['core'], $result['types']);
        $this->assertContains('admin', $result['identifiers']);
        $this->assertNotContains('filter_module_role', $result['identifiers']);
        $this->assertNotContains('filter_custom_role', $result['identifiers']);
    }

    /**
     * module 은 모듈이 만든 역할만 돌려준다.
     *
     * @scenario extension_type=module
     *
     * @effects filters_module_roles_only
     *
     * @return void 반환값 없음
     */
    public function test_module_returns_only_module_roles(): void
    {
        $result = $this->listBy('module');

        $this->assertSame(['module'], $result['types']);
        $this->assertContains('filter_module_role', $result['identifiers']);
    }

    /**
     * custom 은 extension_type 이 비어 있는(직접 만든) 역할만 돌려준다.
     *
     * @scenario extension_type=custom
     *
     * @effects filters_custom_roles_only
     *
     * @return void 반환값 없음
     */
    public function test_custom_returns_only_user_created_roles(): void
    {
        $result = $this->listBy('custom');

        $this->assertSame([null], $result['types']);
        $this->assertContains('filter_custom_role', $result['identifiers']);
        $this->assertNotContains('admin', $result['identifiers']);
    }

    /**
     * 정해진 값 밖이면 422 로 거부한다.
     *
     * @scenario extension_type=invalid
     *
     * @effects rejects_unknown_extension_type
     *
     * @return void 반환값 없음
     */
    public function test_unknown_value_is_rejected(): void
    {
        $this->actingAs($this->superAdmin)->getJson('/api/admin/roles?extension_type=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extension_type']);
    }
}
