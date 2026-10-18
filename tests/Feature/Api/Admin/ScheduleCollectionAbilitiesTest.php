<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 스케줄 목록 응답의 컬렉션 abilities (G22).
 *
 * 「예약 작업 등록」·일괄 상태 변경·행 선택은 페이지 수준 버튼이라 행 abilities 로는 잠글 수 없다.
 * 목록 응답에 라우트 미들웨어와 같은 식별자로 판정한 컬렉션 abilities 가 실려야 화면이 잠근다.
 */
class ScheduleCollectionAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주어진 권한만 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 운영자
     */
    private function operatorWith(array $permissions): User
    {
        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'schedule_test_'.uniqid(),
            'name' => ['ko' => '운영자', 'en' => 'Operator'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 조회 권한만 가진 운영자에게 컬렉션 abilities 는 전부 false 다 (서버 쓰기 라우트도 403)
     *
     * @scenario endpoint=admin.schedules.index,permission=read_only
     *
     * @effects schedule_collection_abilities_false_for_read_only
     *
     * @return void 반환값 없음
     */
    public function test_read_only_operator_gets_false_collection_abilities(): void
    {
        $user = $this->operatorWith(['core.schedules.read']);

        $response = $this->actingAs($user)->getJson('/api/admin/schedules')->assertOk();

        $this->assertSame([
            'can_create' => false,
            'can_update' => false,
            'can_delete' => false,
            'can_run' => false,
        ], $response->json('data.abilities'));

        $this->actingAs($user)->patchJson('/api/admin/schedules/bulk-status', ['ids' => [1], 'is_active' => false])
            ->assertForbidden();
    }

    /**
     * 각 권한 보유자에게는 해당 키만 true 다
     *
     * @scenario endpoint=admin.schedules.index,permission=read+create+update
     *
     * @effects schedule_collection_abilities_follow_route_permissions
     *
     * @return void 반환값 없음
     */
    public function test_collection_abilities_follow_permissions(): void
    {
        $user = $this->operatorWith(['core.schedules.read', 'core.schedules.create', 'core.schedules.update']);

        $abilities = $this->actingAs($user)->getJson('/api/admin/schedules')->assertOk()->json('data.abilities');

        $this->assertTrue($abilities['can_create']);
        $this->assertTrue($abilities['can_update']);
        $this->assertFalse($abilities['can_delete']);
        $this->assertFalse($abilities['can_run']);
    }
}
