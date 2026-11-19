<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserConsent;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SCIM `/Users` — 목록 필터 3종, 생성, 교체, PATCH 연산 3종, 삭제 = 퇴사 처리.
 *
 * @since 1.0.0
 */
class ScimUsersTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 부록 B-5 와 같은 생성 본문.
     *
     * @param  string  $userName  userName 값
     * @param  string  $email  이메일
     * @return array<string, mixed> 본문
     */
    private function userBody(string $userName, string $email): array
    {
        return [
            'schemas' => [ScimSchema::USER, ScimSchema::ENTERPRISE_USER],
            'userName' => $userName,
            'externalId' => 'E-'.$userName,
            'name' => ['formatted' => '스킴 일호'],
            'emails' => [['value' => $email, 'primary' => true]],
            'phoneNumbers' => [['value' => '010-1234-5678', 'type' => 'mobile']],
            'title' => '대리',
            ScimSchema::ENTERPRISE_USER => ['department' => '인사팀', 'employeeNumber' => '798001'],
            'active' => true,
        ];
    }

    /**
     * 생성 — 비밀번호 없는 회원 + 연결(scim) + 추가 속성, 동의 기록 없음, 201 + Location.
     *
     * @scenario existing_user=none, email_domain=owned
     *
     * @effects user_created_passwordless, account_linked_via_scim, attributes_saved, no_consents
     *
     * @return void 반환값 없음
     */
    public function test_create_makes_passwordless_member_and_link(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $email = $this->uniqueEmail('corp798.test');

        $response = $this->scim('POST', $idp, '/Users', $token, $this->userBody('scim-001', $email));

        $response->assertStatus(201)
            ->assertJsonPath('userName', 'scim-001')
            ->assertJsonPath('externalId', 'E-scim-001')
            ->assertJsonPath('active', true)
            ->assertJsonPath('emails.0.value', $email);
        $this->assertSame('인사팀', $response->json()[ScimSchema::ENTERPRISE_USER]['department'] ?? null);
        $this->assertSame($response->json('meta.location'), $response->headers->get('Location'));

        $account = SsoAccount::query()->findOrFail((int) $response->json('id'));
        $this->assertSame('scim', $account->linked_via);
        $this->assertSame('scim-001', $account->subject);
        $user = User::query()->findOrFail($account->user_id);
        $this->assertFalse($user->hasPassword());
        $this->assertSame('스킴 일호', $user->name);
        $this->assertSame('010-1234-5678', $user->mobile);
        $this->assertSame(UserStatus::Active->value, $user->status);
        $this->assertSame(0, UserConsent::query()->where('user_id', $user->id)->count());
        $this->assertSame('798001', SsoUserAttribute::query()->where('user_id', $user->id)->where('key', 'employee_number')->value('value'));
    }

    /**
     * 생성 — 소유 도메인 밖 이메일은 받을 수 없는 주소로 만든다(응답 emails 비움).
     *
     * @scenario email_domain=foreign
     *
     * @effects fallback_invalid_email
     *
     * @return void 반환값 없음
     */
    public function test_create_with_foreign_domain_uses_fallback_email(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $response = $this->scim('POST', $idp, '/Users', $token, $this->userBody('scim-foreign', $this->uniqueEmail('other.test')))->assertStatus(201);

        $user = User::query()->findOrFail(SsoAccount::query()->findOrFail((int) $response->json('id'))->user_id);
        $this->assertStringEndsWith('@sso.invalid', $user->email);
        $response->assertJsonPath('emails', []);
    }

    /**
     * 같은 연결이 있으면 409, 같은 이메일 회원은 소유 도메인 자동 연결이 켜졌을 때만 연결(아니면 409).
     *
     * @scenario existing=same_subject|same_email, domain_auto=off|on
     *
     * @effects scim_409_uniqueness, linked_existing_member
     *
     * @return void 반환값 없음
     */
    public function test_conflicts_and_existing_member_link(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $email = $this->uniqueEmail('corp798.test');

        $this->scim('POST', $idp, '/Users', $token, $this->userBody('dup-1', $this->uniqueEmail('corp798.test')))->assertStatus(201);
        $this->scim('POST', $idp, '/Users', $token, $this->userBody('dup-1', $this->uniqueEmail('corp798.test')))
            ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

        $member = User::factory()->create(['email' => $email]);
        $this->scim('POST', $idp, '/Users', $token, $this->userBody('existing-1', $email))->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

        $idp->forceFill(['link_methods' => ['domain_auto' => true, 'verify' => true, 'admin' => true]])->save();
        $linked = $this->scim('POST', $idp, '/Users', $token, $this->userBody('existing-1', $email))->assertStatus(201);
        $this->assertSame($member->id, SsoAccount::query()->findOrFail((int) $linked->json('id'))->user_id);
    }

    /**
     * 목록 필터 3종(userName, externalId, emails.value)과 쪽(startIndex, count).
     *
     * @scenario filter=userName|externalId|emails.value, page=startIndex+count
     *
     * @effects filtered_list, paged_list
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_and_paging(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $emailB = $this->uniqueEmail('corp798.test');
        $this->scim('POST', $idp, '/Users', $token, $this->userBody('list-a', $this->uniqueEmail('corp798.test')))->assertStatus(201);
        $this->scim('POST', $idp, '/Users', $token, $this->userBody('list-b', $emailB))->assertStatus(201);
        $this->scim('POST', $idp, '/Users', $token, $this->userBody('list-c', $this->uniqueEmail('corp798.test')))->assertStatus(201);

        $byUserName = $this->scim('GET', $idp, '/Users?filter='.rawurlencode('userName eq "list-b"'), $token)->assertOk();
        $byUserName->assertJsonPath('schemas.0', ScimSchema::LIST_RESPONSE)->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.userName', 'list-b');

        $this->scim('GET', $idp, '/Users?filter='.rawurlencode('externalId eq "E-list-c"'), $token)->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.userName', 'list-c');
        $this->scim('GET', $idp, '/Users?filter='.rawurlencode('emails.value eq "'.mb_strtoupper($emailB).'"'), $token)->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.userName', 'list-b');
        $this->scim('GET', $idp, '/Users?filter='.rawurlencode('userName eq "nobody"'), $token)->assertJsonPath('totalResults', 0)->assertJsonPath('Resources', []);

        $page = $this->scim('GET', $idp, '/Users?startIndex=2&count=1', $token)->assertOk();
        $page->assertJsonPath('totalResults', 3)->assertJsonPath('startIndex', 2)->assertJsonPath('itemsPerPage', 1)->assertJsonPath('Resources.0.userName', 'list-b');
    }

    /**
     * 교체(PUT) — 이름, 휴대폰, 부서가 바뀐다.
     *
     * @scenario method=PUT
     *
     * @effects user_name_changed, attributes_replaced
     *
     * @return void 반환값 없음
     */
    public function test_replace_updates_member(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $id = $this->scim('POST', $idp, '/Users', $token, $this->userBody('put-1', $this->uniqueEmail('corp798.test')))->json('id');

        $body = $this->userBody('put-1', $this->uniqueEmail('corp798.test'));
        $body['name'] = ['formatted' => '스킴 이호'];
        $body[ScimSchema::ENTERPRISE_USER]['department'] = '재무팀';

        $response = $this->scim('PUT', $idp, '/Users/'.$id, $token, $body)->assertOk()->assertJsonPath('name.formatted', '스킴 이호');
        $this->assertSame('재무팀', $response->json()[ScimSchema::ENTERPRISE_USER]['department'] ?? null);
    }

    /**
     * PATCH 연산 3종 — replace(경로), add(경로 없는 값 객체), remove(경로).
     *
     * @scenario op=replace|add|remove
     *
     * @effects name_replaced, department_added, title_removed
     *
     * @return void 반환값 없음
     */
    public function test_patch_operations(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $id = $this->scim('POST', $idp, '/Users', $token, $this->userBody('patch-1', $this->uniqueEmail('corp798.test')))->json('id');

        $response = $this->scim('PATCH', $idp, '/Users/'.$id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [
                ['op' => 'Replace', 'path' => 'name.formatted', 'value' => '패치 삼호'],
                ['op' => 'add', 'value' => [ScimSchema::ENTERPRISE_USER => ['department' => '법무팀']]],
                ['op' => 'remove', 'path' => 'title'],
            ],
        ])->assertOk();

        $response->assertJsonPath('name.formatted', '패치 삼호');
        $this->assertSame('법무팀', $response->json()[ScimSchema::ENTERPRISE_USER]['department'] ?? null);
        $this->assertArrayNotHasKey('title', $response->json());
    }

    /**
     * PATCH `active=false` 와 DELETE 는 퇴사 처리 — 회원은 남고 차단, 토큰 0, 연결 비활성.
     *
     * @scenario op=patch_active_false|delete
     *
     * @effects member_blocked, tokens_revoked, account_inactive, member_kept
     *
     * @return void 반환값 없음
     */
    public function test_patch_inactive_and_delete_deprovision(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        foreach (['patch', 'delete'] as $how) {
            $id = $this->scim('POST', $idp, '/Users', $token, $this->userBody('dep-'.$how, $this->uniqueEmail('corp798.test')))->json('id');
            $account = SsoAccount::query()->findOrFail((int) $id);
            $user = User::query()->findOrFail($account->user_id);
            $this->tokens($user, 2);

            if ($how === 'patch') {
                $this->scim('PATCH', $idp, '/Users/'.$id, $token, [
                    'schemas' => [ScimSchema::PATCH_OP],
                    'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
                ])->assertOk()->assertJsonPath('active', false);
            } else {
                $this->scim('DELETE', $idp, '/Users/'.$id, $token)->assertNoContent();
            }

            $user->refresh();
            $account->refresh();
            $this->assertSame(UserStatus::Blocked->value, $user->status, $how);
            $this->assertSame(0, $user->tokens()->count(), $how);
            $this->assertFalse($account->active, $how);
            $this->assertSame('scim', $account->deprovision_reason, $how);
            $this->assertTrue($user->blocked_at->equalTo($account->blocked_user_at), $how);
        }
    }
}
