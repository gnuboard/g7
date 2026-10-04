<?php

namespace Tests\Feature\Api\Admin;

use App\Helpers\PermissionHelper;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 회원 목록 통계의 권한 스코프 회귀 테스트 (#705 D11)
 *
 * 목록 응답은 `data`(행)와 `statistics`(숫자)를 함께 싣는다. self 스코프 운영자에게 목록이
 * 본인 1건인데 통계가 전체 회원 수면, 숨긴 것을 숫자로 흘린다. 스코프는 앞선 보안 수정으로 이미
 * 저장소 한 자리(`scopedUserQuery()`)에 모였으므로, 이 테스트는 **목록 건수와 통계가 같은 범위**
 * 라는 계약을 필터 개편 이후에도 고정한다.
 *
 * 트랜잭션 격리 — 코어 역할·권한은 시더로 심는다.
 */
#[Group('core')]
#[Group('admin')]
#[Group('user')]
#[Group('security')]
class UserStatisticsScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 코어 역할·권한을 심고 권한 메타 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * self 스코프 매니저는 목록 1건 = 통계 총수 1 이다(필터를 걸어도 마찬가지).
     *
     * @scenario filter_axis=permission_scope, outcome=narrows
     *
     * @effects admin_user_list_statistics_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_manager_statistics_match_list_total(): void
    {
        $manager = $this->userWithRole('manager');
        User::factory()->count(3)->create();

        $response = $this->actingAs($manager)->getJson('/api/admin/users')->assertOk();

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame(1, $response->json('data.statistics.total_users'));
        $this->assertLessThanOrEqual(1, $response->json('data.statistics.users_this_month'));

        // 필터를 건 목록에서도 통계가 스코프 밖으로 새지 않는다.
        $filtered = $this->actingAs($manager)->getJson('/api/admin/users?status[]=active&status[]=blocked')->assertOk();
        $this->assertSame(1, $filtered->json('data.statistics.total_users'));
    }

    /**
     * 슈퍼관리자는 전체 회원 수를 그대로 본다(무회귀).
     *
     * @scenario filter_axis=permission_scope, outcome=narrows
     *
     * @effects admin_user_list_statistics_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_statistics_cover_all_users(): void
    {
        $super = $this->userWithRole('admin', ['is_super' => true]);
        User::factory()->count(2)->create();

        $response = $this->actingAs($super)->getJson('/api/admin/users')->assertOk();

        $this->assertSame(User::query()->count(), $response->json('data.statistics.total_users'));
    }

    /**
     * `core.users.read` 가 없는 계정은 목록 자체가 403 이다.
     *
     * @scenario filter_axis=permission_scope, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_user_without_read_permission_gets_403(): void
    {
        $plain = User::factory()->create(['email' => Str::lower(Str::random(10)).'@plain.test']);

        $this->actingAs($plain)->getJson('/api/admin/users')->assertStatus(403);
    }

    /**
     * 시드된 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $roleIdentifier  역할 식별자
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 회원
     */
    private function userWithRole(string $roleIdentifier, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $role = Role::where('identifier', $roleIdentifier)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
