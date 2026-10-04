<?php

namespace Tests\Unit\Providers;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AuthServiceProvider::checkPermissionsWithLogic() 평가 로직 테스트
 *
 * flat array(AND), 구조화 객체(OR/AND), 중첩 구조, admin 전권, Guest 케이스를 포함합니다.
 */
class AuthServiceProviderPermissionLogicTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    private User $user;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 역할 및 사용자 생성
        $this->role = Role::create([
            'name' => '테스트 역할',
            'identifier' => 'test_role',
        ]);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($this->role);

        // 게스트 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();
    }

    /**
     * 사용자에게 지정된 권한을 부여합니다.
     *
     * @param  array<string>  $identifiers  권한 식별자 배열
     * @return void 반환값 없음
     */
    private function grantPermissions(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'type' => PermissionType::Admin,
                ]
            );
            $this->role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    // ================================================================
    // A-1. 기본 동작 (하위 호환)
    // ================================================================

    /**
     * #1 빈 배열은 권한 불필요
     *
     * @scenario checker=grant_logic, permission_shape=empty, held_permissions=satisfying
     *
     * @effects a1_empty_array_passes
     *
     * @return void 반환값 없음
     */
    public function test_a1_empty_array_passes(): void
    {
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic([], $this->user)
        );
    }

    /**
     * #2 단일 권한 보유 시 통과
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=satisfying
     *
     * @effects a1_single_permission_with_permission
     *
     * @return void 반환값 없음
     */
    public function test_a1_single_permission_with_permission(): void
    {
        $this->grantPermissions(['a']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['a'], $this->user)
        );
    }

    /**
     * #3 단일 권한 미보유 시 실패
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=not_satisfying
     *
     * @effects a1_single_permission_without_permission
     *
     * @return void 반환값 없음
     */
    public function test_a1_single_permission_without_permission(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['a'], $this->user)
        );
    }

    /**
     * #4 복수 AND 모두 보유 시 통과
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=satisfying
     *
     * @effects a1_multiple_and_all_granted
     *
     * @return void 반환값 없음
     */
    public function test_a1_multiple_and_all_granted(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['a', 'b'], $this->user)
        );
    }

    /**
     * #5 복수 AND 일부만 보유 시 실패
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=not_satisfying
     *
     * @effects a1_multiple_and_partial
     *
     * @return void 반환값 없음
     */
    public function test_a1_multiple_and_partial(): void
    {
        $this->grantPermissions(['a']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['a', 'b'], $this->user)
        );
    }

    /**
     * #6 복수 AND 없음 시 실패
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=not_satisfying
     *
     * @effects a1_multiple_and_none
     *
     * @return void 반환값 없음
     */
    public function test_a1_multiple_and_none(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['a', 'b'], $this->user)
        );
    }

    // ================================================================
    // A-2. OR 로직
    // ================================================================

    /**
     * #7 OR: 첫 번째만 보유
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a2_or_first_only
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_first_only(): void
    {
        $this->grantPermissions(['a']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #8 OR: 두 번째만 보유
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a2_or_second_only
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_second_only(): void
    {
        $this->grantPermissions(['b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #9 OR: 모두 보유
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a2_or_both
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_both(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #10 OR: 없음
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=not_satisfying
     *
     * @effects a2_or_none
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_none(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #11 OR: 3개 중 1개 보유
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a2_or_three_items_one_match
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_three_items_one_match(): void
    {
        $this->grantPermissions(['b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b', 'c']], $this->user)
        );
    }

    /**
     * #12 OR: 3개 중 0개
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=not_satisfying
     *
     * @effects a2_or_three_items_none
     *
     * @return void 반환값 없음
     */
    public function test_a2_or_three_items_none(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b', 'c']], $this->user)
        );
    }

    // ================================================================
    // A-3. AND 로직 (명시적)
    // ================================================================

    /**
     * #13 명시적 AND: 모두 보유
     *
     * @scenario checker=grant_logic, permission_shape=and_group, held_permissions=satisfying
     *
     * @effects a3_explicit_and_all
     *
     * @return void 반환값 없음
     */
    public function test_a3_explicit_and_all(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['and' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #14 명시적 AND: 일부만
     *
     * @scenario checker=grant_logic, permission_shape=and_group, held_permissions=not_satisfying
     *
     * @effects a3_explicit_and_partial
     *
     * @return void 반환값 없음
     */
    public function test_a3_explicit_and_partial(): void
    {
        $this->grantPermissions(['a']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['and' => ['a', 'b']], $this->user)
        );
    }

    /**
     * #15 명시적 AND: 3개 중 2개
     *
     * @scenario checker=grant_logic, permission_shape=and_group, held_permissions=not_satisfying
     *
     * @effects a3_explicit_and_three_partial
     *
     * @return void 반환값 없음
     */
    public function test_a3_explicit_and_three_partial(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['and' => ['a', 'b', 'c']], $this->user)
        );
    }

    // ================================================================
    // A-4. 2단계 중첩 (AND > OR, OR > AND)
    // ================================================================

    /**
     * #16 AND>OR: a + b 보유
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a4_and_or_a_and_b
     *
     * @return void 반환값 없음
     */
    public function test_a4_and_or_a_and_b(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', 'c']]]],
                $this->user
            )
        );
    }

    /**
     * #17 AND>OR: a + c 보유
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a4_and_or_a_and_c
     *
     * @return void 반환값 없음
     */
    public function test_a4_and_or_a_and_c(): void
    {
        $this->grantPermissions(['a', 'c']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', 'c']]]],
                $this->user
            )
        );
    }

    /**
     * #18 AND>OR: a만 보유 (or 미충족)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a4_and_or_a_only
     *
     * @return void 반환값 없음
     */
    public function test_a4_and_or_a_only(): void
    {
        $this->grantPermissions(['a']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', 'c']]]],
                $this->user
            )
        );
    }

    /**
     * #19 AND>OR: b만 보유 (a 미충족)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a4_and_or_b_only
     *
     * @return void 반환값 없음
     */
    public function test_a4_and_or_b_only(): void
    {
        $this->grantPermissions(['b']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', 'c']]]],
                $this->user
            )
        );
    }

    /**
     * #20 OR>AND: 첫 AND 통과 (a,b)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a4_or_and_first_group
     *
     * @return void 반환값 없음
     */
    public function test_a4_or_and_first_group(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['and' => ['a', 'b']], ['and' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #21 OR>AND: 둘째 AND 통과 (c,d)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a4_or_and_second_group
     *
     * @return void 반환값 없음
     */
    public function test_a4_or_and_second_group(): void
    {
        $this->grantPermissions(['c', 'd']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['and' => ['a', 'b']], ['and' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #22 OR>AND: 어느 AND도 미통과 (a,c)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a4_or_and_cross_partial
     *
     * @return void 반환값 없음
     */
    public function test_a4_or_and_cross_partial(): void
    {
        $this->grantPermissions(['a', 'c']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['and' => ['a', 'b']], ['and' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #23 OR>AND: 없음
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a4_or_and_none
     *
     * @return void 반환값 없음
     */
    public function test_a4_or_and_none(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['and' => ['a', 'b']], ['and' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    // ================================================================
    // A-5. 3단계 중첩 (최대 허용 깊이)
    // ================================================================

    /**
     * #24 3단계: a + or의 b 통과
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth3, held_permissions=satisfying
     *
     * @effects a5_depth3_a_and_b
     *
     * @return void 반환값 없음
     */
    public function test_a5_depth3_a_and_b(): void
    {
        $this->grantPermissions(['a', 'b']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', 'd']]]]]],
                $this->user
            )
        );
    }

    /**
     * #25 3단계: a + or의 and(c,d) 통과
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth3, held_permissions=satisfying
     *
     * @effects a5_depth3_a_and_cd
     *
     * @return void 반환값 없음
     */
    public function test_a5_depth3_a_and_cd(): void
    {
        $this->grantPermissions(['a', 'c', 'd']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', 'd']]]]]],
                $this->user
            )
        );
    }

    /**
     * #26 3단계: a + c만 (and 미통과)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth3, held_permissions=not_satisfying
     *
     * @effects a5_depth3_a_and_c_only
     *
     * @return void 반환값 없음
     */
    public function test_a5_depth3_a_and_c_only(): void
    {
        $this->grantPermissions(['a', 'c']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', 'd']]]]]],
                $this->user
            )
        );
    }

    /**
     * #27 3단계: c,d만 (a 없음)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth3, held_permissions=not_satisfying
     *
     * @effects a5_depth3_cd_without_a
     *
     * @return void 반환값 없음
     */
    public function test_a5_depth3_cd_without_a(): void
    {
        $this->grantPermissions(['c', 'd']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', 'd']]]]]],
                $this->user
            )
        );
    }

    // ================================================================
    // A-6. 4단계 중첩 (초과 → 거부)
    // ================================================================

    /**
     * #28 4단계 중첩 초과 → false + Log::warning
     *
     * @scenario checker=grant_logic, permission_shape=nested_too_deep, held_permissions=not_satisfying
     *
     * @effects a6_depth4_exceeds_max
     *
     * @return void 반환값 없음
     */
    public function test_a6_depth4_exceeds_max(): void
    {
        // 'b'를 부여하지 않아 OR 단축 평가를 방지 → 깊은 분기(depth 4)까지 평가 강제
        $this->grantPermissions(['a', 'c', 'd', 'e']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '최대 중첩 깊이');
            });

        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', ['or' => ['d', 'e']]]]]]]],
                $this->user
            )
        );
    }

    // ================================================================
    // A-7. 복수 OR/AND 조합
    // ================================================================

    /**
     * #29 AND[OR,OR]: or1+or2 모두 통과 (a,c)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a7_and_or_or_ac
     *
     * @return void 반환값 없음
     */
    public function test_a7_and_or_or_ac(): void
    {
        $this->grantPermissions(['a', 'c']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * AND 안의 두 OR 묶음에서 b 와 d 를 가진 경우를 판정한다.
     *
     * #30 AND[OR,OR]: (b,d)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a7_and_or_or_bd
     *
     * @return void 반환값 없음
     */
    public function test_a7_and_or_or_bd(): void
    {
        $this->grantPermissions(['b', 'd']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * AND 안의 두 OR 묶음에서 a 와 d 를 가진 경우를 판정한다.
     *
     * #31 AND[OR,OR]: (a,d)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a7_and_or_or_ad
     *
     * @return void 반환값 없음
     */
    public function test_a7_and_or_or_ad(): void
    {
        $this->grantPermissions(['a', 'd']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #32 AND[OR,OR]: a만 (or2 미통과)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a7_and_or_or_a_only
     *
     * @return void 반환값 없음
     */
    public function test_a7_and_or_or_a_only(): void
    {
        $this->grantPermissions(['a']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #33 AND[OR,OR]: c만 (or1 미통과)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a7_and_or_or_c_only
     *
     * @return void 반환값 없음
     */
    public function test_a7_and_or_or_c_only(): void
    {
        $this->grantPermissions(['c']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #34 OR[OR,OR]: a만 (or1 통과)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a7_or_or_or_a
     *
     * @return void 반환값 없음
     */
    public function test_a7_or_or_or_a(): void
    {
        $this->grantPermissions(['a']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #35 OR[OR,OR]: d만 (or2 통과)
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a7_or_or_or_d
     *
     * @return void 반환값 없음
     */
    public function test_a7_or_or_or_d(): void
    {
        $this->grantPermissions(['d']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    /**
     * #36 OR[OR,OR]: 없음
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a7_or_or_or_none
     *
     * @return void 반환값 없음
     */
    public function test_a7_or_or_or_none(): void
    {
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => [['or' => ['a', 'b']], ['or' => ['c', 'd']]]],
                $this->user
            )
        );
    }

    // ================================================================
    // A-8. admin 역할 (전권)
    // ================================================================

    /**
     * #37 admin: OR 구조 통과 (admin은 시더에서 모든 권한 할당)
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a8_admin_or
     *
     * @return void 반환값 없음
     */
    public function test_a8_admin_or(): void
    {
        $adminRole = Role::create(['name' => '관리자', 'identifier' => 'admin']);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        // admin 역할에 필요 권한 할당 (프로덕션에서는 시더가 모든 리프 권한 할당)
        foreach (['a', 'b'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin, 'group' => 'test']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], $admin)
        );
    }

    /**
     * #38 admin: AND>OR 중첩 통과
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a8_admin_nested
     *
     * @return void 반환값 없음
     */
    public function test_a8_admin_nested(): void
    {
        $adminRole = Role::create(['name' => '관리자', 'identifier' => 'admin']);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        foreach (['a', 'b', 'c'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin, 'group' => 'test']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', 'c']]]],
                $admin
            )
        );
    }

    /**
     * #39 admin: 3단계 복잡 구조 통과
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth3, held_permissions=satisfying
     *
     * @effects a8_admin_depth3
     *
     * @return void 반환값 없음
     */
    public function test_a8_admin_depth3(): void
    {
        $adminRole = Role::create(['name' => '관리자', 'identifier' => 'admin']);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        foreach (['a', 'b', 'c', 'd'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin, 'group' => 'test']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['a', ['or' => ['b', ['and' => ['c', 'd']]]]]],
                $admin
            )
        );
    }

    // ================================================================
    // A-9. Guest (미인증)
    // ================================================================

    /**
     * #40 Guest: flat 권한 보유
     *
     * @scenario checker=grant_logic, permission_shape=flat, held_permissions=satisfying
     *
     * @effects a9_guest_flat_with_permission
     *
     * @return void 반환값 없음
     */
    public function test_a9_guest_flat_with_permission(): void
    {
        AuthServiceProvider::clearGuestRoleCache();

        $guestRole = Role::create(['name' => '게스트', 'identifier' => 'guest']);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'guest.perm'],
            ['name' => ['ko' => 'guest.perm', 'en' => 'guest.perm'], 'type' => PermissionType::Admin, 'group' => 'test']
        );
        $guestRole->permissions()->attach($permission);

        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['guest.perm'], null)
        );
    }

    /**
     * #41 Guest: OR 구조에서 보유한 권한 있음
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a9_guest_or_with_permission
     *
     * @return void 반환값 없음
     */
    public function test_a9_guest_or_with_permission(): void
    {
        AuthServiceProvider::clearGuestRoleCache();

        $guestRole = Role::create(['name' => '게스트', 'identifier' => 'guest']);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'guest.perm'],
            ['name' => ['ko' => 'guest.perm', 'en' => 'guest.perm'], 'type' => PermissionType::Admin, 'group' => 'test']
        );
        $guestRole->permissions()->attach($permission);

        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'guest.perm']], null)
        );
    }

    /**
     * #42 Guest: OR 구조에서 미보유
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=not_satisfying
     *
     * @effects a9_guest_or_without_permission
     *
     * @return void 반환값 없음
     */
    public function test_a9_guest_or_without_permission(): void
    {
        AuthServiceProvider::clearGuestRoleCache();

        // guest role 없거나 권한 없음
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(['or' => ['a', 'b']], null)
        );
    }

    // ================================================================
    // A-10. 실제 사용 사례
    // ================================================================

    /**
     * #43 user_form: create만 보유 → 통과
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a10_user_form_create_only
     *
     * @return void 반환값 없음
     */
    public function test_a10_user_form_create_only(): void
    {
        $this->grantPermissions(['core.users.create']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => ['core.users.create', 'core.users.update']],
                $this->user
            )
        );
    }

    /**
     * #44 user_form: update만 보유 → 통과
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=satisfying
     *
     * @effects a10_user_form_update_only
     *
     * @return void 반환값 없음
     */
    public function test_a10_user_form_update_only(): void
    {
        $this->grantPermissions(['core.users.update']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => ['core.users.create', 'core.users.update']],
                $this->user
            )
        );
    }

    /**
     * #45 user_form: read만 보유 → 실패
     *
     * @scenario checker=grant_logic, permission_shape=or_group, held_permissions=not_satisfying
     *
     * @effects a10_user_form_read_only
     *
     * @return void 반환값 없음
     */
    public function test_a10_user_form_read_only(): void
    {
        $this->grantPermissions(['core.users.read']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['or' => ['core.users.create', 'core.users.update']],
                $this->user
            )
        );
    }

    /**
     * #46 menu_form: read+create → 통과
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=satisfying
     *
     * @effects a10_menu_form_read_create
     *
     * @return void 반환값 없음
     */
    public function test_a10_menu_form_read_create(): void
    {
        $this->grantPermissions(['core.menus.read', 'core.menus.create']);
        $this->assertTrue(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['core.menus.read', ['or' => ['core.menus.create', 'core.menus.update']]]],
                $this->user
            )
        );
    }

    /**
     * #47 menu_form: read만 → 실패
     *
     * @scenario checker=grant_logic, permission_shape=nested_depth2, held_permissions=not_satisfying
     *
     * @effects a10_menu_form_read_only
     *
     * @return void 반환값 없음
     */
    public function test_a10_menu_form_read_only(): void
    {
        $this->grantPermissions(['core.menus.read']);
        $this->assertFalse(
            AuthServiceProvider::checkPermissionsWithLogic(
                ['and' => ['core.menus.read', ['or' => ['core.menus.create', 'core.menus.update']]]],
                $this->user
            )
        );
    }
}
