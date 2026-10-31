<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 회사 계정 회원이 비밀번호를 만들 수 없으면 회원 응답이 미리 알린다 (D22 화면 안내).
 *
 * 연결 IdP 가 「이 사이트 비밀번호 허용」(`allow_local_password`)을 끈 회원(기본값)은 비밀번호를 만들 수 없다. 화면이 이 사실을 몰라
 * 「비밀번호 처음 설정 → 본인 확인 시작」을 안내하고 IdP 왕복까지 시킨 뒤 저장에서 403 을 냈다. 코어 회원 응답이 같은 판정(코어 필터
 * `core.auth.password_auth_decision` 의 `set_password`·`admin_set_password`)을 미리 실어 화면이 칸과 본인 확인 대신 안내를 보인다.
 *
 * @since 1.0.0
 */
class PasswordSetAvailabilityTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 플러그인 문구를 올립니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPluginLang();
    }

    /**
     * 비밀번호 허용이 꺼진 IdP 에 연결된 회원은 마이페이지 응답·관리자 회원 상세에서 비밀번호를 만들 수 없다고 나오고, 문구는 저장 403
     * 과 같다. IdP 가 허용하면 만들 수 있다고 나온다.
     *
     * @scenario allow_local_password=false|true, surface=profile|admin_detail
     *
     * @effects member_response_reports_password_unsettable
     *
     * @return void 반환값 없음
     */
    public function test_member_response_reports_password_unsettable_for_linked_member(): void
    {
        $idp = $this->makeIdp('corp', ['allow_local_password' => false]);
        // 응답 문구는 회원 언어를 따른다 — 기대 문구와 같은 언어(ko)로 둔다
        $member = $this->makeMember(['email' => $this->uniqueEmail('sso.invalid'), 'password' => null, 'language' => 'ko']);
        $this->linkAccount($member, $idp);
        $message = __('sirsoft-sso::policy.password_set_disabled', [], 'ko');

        Sanctum::actingAs($member);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.can_set_password', false)
            ->assertJsonPath('data.password_set_blocked_message', $message);
        $this->putJson('/api/me/password', ['password' => 'N3w!Passw0rd#x', 'password_confirmation' => 'N3w!Passw0rd#x'])
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled')
            ->assertJsonPath('message', $message);

        Sanctum::actingAs($this->makeAdmin(['core.users.read', 'core.users.update'], ['language' => 'ko']));
        $this->getJson('/api/admin/users/'.$member->uuid)->assertOk()
            ->assertJsonPath('data.password_status.can_set', false)
            ->assertJsonPath('data.password_status.set_blocked_message', $message);

        $idp->update(['allow_local_password' => true]);
        $this->getJson('/api/admin/users/'.$member->uuid)->assertOk()
            ->assertJsonPath('data.password_status.can_set', true)
            ->assertJsonPath('data.password_status.set_blocked_message', null);
        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.can_set_password', true);
    }
}
