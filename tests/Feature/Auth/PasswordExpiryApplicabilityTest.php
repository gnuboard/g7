<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Services\PasswordExpiryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 만료 적용 판정 테스트 (#736 D6·D9·D10)
 *
 * 회원 개별 > 역할 > 사이트 범위의 우선순위와 "역할끼리 부딪치면 적용" 을 PHP 판정(`appliesTo`)과
 * SQL 판정(`applyScope` — 배치·건수·명단이 쓰는 쿼리)이 똑같이 내리는지 전 조합으로 대조한다.
 * 둘이 갈리면 로그인 관문과 배치가 서로 다른 회원을 만료 대상으로 본다.
 */
class PasswordExpiryApplicabilityTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 회원 개별 설정 3 × 역할 조합 4 × 관리자 여부 2 회원을 만들고, 범위 3종마다 두 판정을 대조한다.
     *
     * @scenario scope=all|admin|selected, member_policy=inherit|apply|exempt, role_policy=none|apply|exempt|apply_exempt, admin=yes|no
     *
     * @effects php_and_sql_applicability_agree
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function php_and_sql_judgements_agree_for_every_combination(): void
    {
        $this->enableExpiry();
        $this->seed(RolePermissionSeeder::class);

        $applyRole = $this->makeRole('apply');
        $exemptRole = $this->makeRole('exempt');
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        $roleCombos = [
            'none' => [],
            'apply' => [$applyRole->id],
            'exempt' => [$exemptRole->id],
            'apply_exempt' => [$applyRole->id, $exemptRole->id],
        ];

        $users = [];

        foreach (['inherit', 'apply', 'exempt'] as $memberPolicy) {
            foreach ($roleCombos as $comboName => $roleIds) {
                foreach ([false, true] as $isAdmin) {
                    $user = $this->makeMember(['password_expiry_policy' => $memberPolicy]);
                    $user->roles()->syncWithoutDetaching(array_merge($roleIds, $isAdmin ? [$adminRole->id] : []));
                    $users["{$memberPolicy}/{$comboName}/".($isAdmin ? 'admin' : 'member')] = $user->id;
                }
            }
        }

        foreach (['all', 'admin', 'selected'] as $scope) {
            $this->setSecurity(['password_expiry_scope' => $scope]);
            $service = app(PasswordExpiryService::class);

            $sqlIds = $service->applyScope(User::query()->whereKey(array_values($users)))->pluck('id')->all();

            foreach ($users as $label => $id) {
                $php = $service->appliesTo(User::with('roles')->find($id));

                $this->assertSame(
                    $php,
                    in_array($id, $sqlIds, true),
                    "범위={$scope}, 회원/역할/관리자={$label}: PHP 판정과 SQL 판정이 다르다"
                );
            }
        }
    }

    /**
     * 대표 조합의 기대값을 직접 고정한다 — 회원 개별 우선, 역할 충돌은 적용, 따름+역할 없음은 범위를 따른다.
     *
     * @scenario scope=all|admin|selected, member_policy=inherit|apply|exempt
     *
     * @effects priority_member_over_role_over_site
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function priority_is_member_then_role_then_site(): void
    {
        $this->enableExpiry(['password_expiry_scope' => 'selected']);
        $service = app(PasswordExpiryService::class);

        $applyRole = $this->makeRole('apply');
        $exemptRole = $this->makeRole('exempt');

        $memberApply = $this->makeMember(['password_expiry_policy' => 'apply']);
        $memberExemptWithApplyRole = $this->makeMember(['password_expiry_policy' => 'exempt']);
        $memberExemptWithApplyRole->roles()->attach($applyRole->id);
        $conflict = $this->makeMember();
        $conflict->roles()->attach([$applyRole->id, $exemptRole->id]);
        $plain = $this->makeMember();

        $this->assertTrue($service->appliesTo($memberApply->fresh()), '회원 적용');
        $this->assertFalse($service->appliesTo($memberExemptWithApplyRole->fresh()), '회원 제외가 역할 적용보다 우선');
        $this->assertTrue($service->appliesTo($conflict->fresh()), '역할끼리 부딪치면 적용(보안 우선)');
        $this->assertFalse($service->appliesTo($plain->fresh()), '지정한 역할·회원만 범위에서 따름은 미적용');

        $this->setSecurity(['password_expiry_scope' => 'all']);
        $this->assertTrue($service->appliesTo($plain->fresh()), '전체 범위에서 따름은 적용');

        $this->setSecurity(['password_expiry_enabled' => false]);
        $this->assertFalse($service->appliesTo($memberApply->fresh()), '기능이 꺼져 있으면 아무에게도 적용하지 않는다');
    }

    /**
     * 비밀번호 만료 설정을 가진 역할을 만듭니다.
     *
     * @param  string  $policy  만료 설정 (inherit·apply·exempt 중 하나)
     * @return Role 만든 역할
     */
    private function makeRole(string $policy): Role
    {
        return Role::create([
            'identifier' => 'pw_expiry_'.$policy.'_'.uniqid(),
            'name' => ['ko' => $policy, 'en' => $policy],
            'description' => ['ko' => $policy, 'en' => $policy],
            'is_active' => true,
            'password_expiry_policy' => $policy,
        ]);
    }
}
