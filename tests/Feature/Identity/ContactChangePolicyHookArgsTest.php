<?php

namespace Tests\Feature\Identity;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 연락처 변경 정책(core.profile.contact_change)이 실제 회원 수정 훅 인자로 발화하는지 검증한다.
 *
 * 회원 수정 서비스는 `core.user.before_update` 를 (아직 값을 채우지 않은 회원 모델, 저장할 데이터) 로 발화한다.
 * 리스너가 첫 인자의 getDirty() — 아직 빈 목록 — 만 보고 「바뀐 항목 없음」으로 판정해, 정책이 켜져 있어도
 * 회원이 휴대폰·이메일을 인증 없이 바꿀 수 있었다(#699 브라우저 실측 중 발견). 정책 서비스에 바뀐 항목을
 * 직접 넣는 기존 매트릭스 테스트는 이 추출 단계를 거치지 않아 놓쳤다.
 */
class ContactChangePolicyHookArgsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 역할·권한과 코어 본인인증 정책을 시드하고 연락처 변경 정책을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::where('key', 'core.profile.contact_change')->update(['enabled' => true]);
    }

    /**
     * 회원 수정 훅에 휴대폰 번호 변경이 실리면 연락처 변경 정책이 본인인증을 요구한다.
     *
     * @scenario surface=profile_update_hook
     *
     * @effects contact_change_policy_fires_on_profile_update
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_changing_mobile_requires_verification(): void
    {
        $user = User::factory()->create(['mobile' => '010-1111-2222']);
        $this->actingAs($user);

        try {
            HookManager::doAction('core.user.before_update', $user, ['name' => $user->name, 'mobile' => '010-3333-4444']);
            $this->fail('휴대폰 번호를 바꾸는 회원 수정은 연락처 변경 정책에 걸려야 한다');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('core.profile.contact_change', $e->policyKey);
        }
    }

    /**
     * 연락처 칸을 같은 값으로 다시 보내고 다른 칸만 바꾸면 정책이 걸리지 않는다(폼은 모든 칸을 함께 보낸다).
     *
     * @scenario surface=profile_update_hook
     *
     * @effects contact_change_policy_fires_on_profile_update
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_resubmitting_same_contact_values_does_not_require_verification(): void
    {
        $user = User::factory()->create(['mobile' => '010-1111-2222', 'phone' => null]);
        $this->actingAs($user);

        HookManager::doAction('core.user.before_update', $user, [
            'nickname' => 'renamed',
            'email' => $user->email,
            'mobile' => '010-1111-2222',
            'phone' => null,
        ]);

        $this->assertTrue(true, '연락처 값이 그대로면 인증 없이 통과한다');
    }
}
