<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use App\Models\User;
use App\Support\SiteUrl;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * SAML SP-initiated 로그인 왕복 — 시작(AuthnRequest), ACS 검증, 회원 결정, 코어 로그인 인계, 실패 화면.
 *
 * @since 1.0.0
 */
class SamlLoginFlowTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * 시작하면 IdP SSO 주소로 302 하고 AuthnRequest 의 ACS 가 사이트 주소 기준이며 RelayState 가 state 다.
     *
     * @scenario protocol=saml, binding=redirect
     *
     * @effects authn_request_sent, acs_is_site_url, request_id_in_state
     *
     * @return void 반환값 없음
     */
    public function test_start_sends_authn_request(): void
    {
        $idp = $this->mockSamlIdp();

        $start = $this->startSaml($idp);

        $start['response']->assertStatus(302);
        $this->assertStringStartsWith(SiteUrl::absolute('/plugins/sirsoft-sso/mock/saml/sso'), (string) $start['response']->headers->get('Location'));
        $this->assertStringContainsString('AssertionConsumerServiceURL="'.SiteUrl::absolute(SamlDriver::acsPath($idp)).'"', $start['xml']);
        $this->assertNotSame('', $start['request_id']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{43}$/', $start['relay_state']);

        $intent = app(StateStore::class)->pull($start['relay_state']);
        $this->assertSame($start['request_id'], $intent?->extra[SamlDriver::EXTRA_REQUEST_ID] ?? null, 'AuthnRequest ID 가 state 에 저장된다');
    }

    /**
     * 연결된 회원은 ACS 뒤 코어 로그인 인계로 넘어가고, 같은 브라우저로 교환된다.
     *
     * @scenario account=linked, guard=user, redirect=/mypage
     *
     * @effects handoff_issued, context_login_method, attempt_recorded
     *
     * @return void 반환값 없음
     */
    public function test_linked_member_gets_core_login_handoff(): void
    {
        $idp = $this->mockSamlIdp();
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $this->link($user, $idp, 'saml-emp-100');

        $response = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-emp-100'], 'guard=user&redirect=%2Fmypage');

        $response->assertStatus(302);
        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertSame('/mypage', $this->redirectQuery($response)['redirect'] ?? null);
        $redeemed = $this->redeemHandoff($response);
        $this->assertSame($user->id, $redeemed['user']->id ?? null);
        $this->assertSame('sso:mock_saml_a', $redeemed['context']['login_method'] ?? null);
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'outcome' => SsoLoginAttempt::OUTCOME_HANDOFF]);
    }

    /**
     * 처음 온 직원은 JIT 로 만들어지고 연결 행에 NameID 와 형식이 남는다.
     *
     * @scenario account=new, provisioning=jit, consent=skip
     *
     * @effects user_created, account_subject_name_id, subject_format_saved
     *
     * @return void 반환값 없음
     */
    public function test_new_employee_is_provisioned_just_in_time(): void
    {
        $idp = $this->mockSamlIdp();
        $email = $this->uniqueEmail('corp798.test');

        $response = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-new-001', 'email' => $email]);

        $this->assertNotSame('', $this->redirectQuery($response)['login_handoff'] ?? '');
        $account = SsoAccount::query()->where('idp_id', $idp->id)->where('subject', 'saml-new-001')->first();
        $this->assertNotNull($account);
        $this->assertSame('jit', $account->linked_via);
        $this->assertSame('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', $account->subject_format);
        $this->assertSame($email, User::query()->find($account->user_id)?->email);
    }

    /**
     * 검증에 실패한 응답은 로그인 화면 오류로 돌아오고 회원은 생기지 않는다.
     *
     * @scenario variant=unsigned|audience_other
     *
     * @effects sso_error_saml_invalid, member_change_zero, attempt_failed
     *
     * @return void 반환값 없음
     */
    public function test_invalid_response_returns_error_screen(): void
    {
        $idp = $this->mockSamlIdp();
        $before = User::query()->count();

        foreach (['unsigned', 'audience_other'] as $variant) {
            $response = $this->samlRoundTrip($idp, $variant, ['email' => $this->uniqueEmail('corp798.test')]);
            $this->assertSame('/login', $this->redirectPath($response), $variant);
            $this->assertSame('saml_invalid', $this->redirectQuery($response)['sso_error'] ?? null, $variant);
        }

        $this->assertSame($before, User::query()->count());
        $this->assertSame(2, SsoLoginAttempt::query()->where('idp_id', $idp->id)->where('reason_code', 'saml_invalid')->count());
    }

    /**
     * 실패한 시도에는 진단용 IdP 응답 요약(서명 여부, 유효 시각, 대상)이 남고 응답 원문은 남지 않는다(§7 L-3) — 진단 카드의 최근 시도에도 실린다.
     *
     * @scenario variant=unsigned|audience_other
     *
     * @effects response_summary_recorded, raw_response_not_stored, recent_attempt_has_summary
     *
     * @return void 반환값 없음
     */
    public function test_failed_attempt_keeps_response_summary(): void
    {
        $idp = $this->mockSamlIdp();

        $this->samlRoundTrip($idp, 'unsigned', ['email' => $this->uniqueEmail('corp798.test')]);
        $unsigned = SsoLoginAttempt::query()->where('idp_id', $idp->id)->latest('id')->first();
        $this->assertFalse($unsigned->response_summary['signed']);
        $this->assertNotNull($unsigned->response_summary['not_on_or_after']);
        $this->assertNotSame([], $unsigned->response_summary['audiences']);

        $this->samlRoundTrip($idp, 'audience_other', ['email' => $this->uniqueEmail('corp798.test')]);
        $other = SsoLoginAttempt::query()->where('idp_id', $idp->id)->latest('id')->first();
        $this->assertTrue($other->response_summary['signed']);
        $spEntityId = (string) (collect($idp->driver()->serviceProviderInfo($idp))->firstWhere('key', 'entity_id')['value'] ?? '');
        $this->assertNotSame('', $spEntityId);
        $this->assertNotContains($spEntityId, $other->response_summary['audiences']);

        $raw = (string) json_encode($other->getAttributes());
        $this->assertStringNotContainsString('SAMLResponse', $raw);
        $this->assertStringNotContainsString('<saml', $raw);

        $recent = app(\Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface::class)->recentAttempts((int) $idp->id, 10);
        $this->assertSame($other->response_summary, $recent[0]['response_summary']);
    }

    /**
     * 같은 RelayState(state)로 두 번 들어오면 두 번째는 state 만료다(1회용).
     *
     * @scenario state=reused
     *
     * @effects second_state_expired
     *
     * @return void 반환값 없음
     */
    public function test_state_is_single_use(): void
    {
        $idp = $this->mockSamlIdp();
        $start = $this->startSaml($idp);
        $first = $this->samlResponse($idp, 'ok', $start['request_id']);
        $this->postAcs($idp, $first, $start['relay_state'])->assertStatus(302);

        $second = $this->postAcs($idp, $this->samlResponse($idp, 'ok', $start['request_id']), $start['relay_state']);

        $this->assertSame('state_expired', $this->redirectQuery($second)['sso_error'] ?? null);
    }

    /**
     * SAMLResponse 가 없거나 비정상이면 IdP 응답을 읽지 않고 오류 화면으로 보낸다.
     *
     * @scenario acs_input=missing
     *
     * @effects sso_error_saml_invalid
     *
     * @return void 반환값 없음
     */
    public function test_malformed_acs_request(): void
    {
        $idp = $this->mockSamlIdp();

        $response = $this->withBrowserBind()->post(SamlDriver::acsPath($idp), ['RelayState' => str_repeat('a', 43)]);

        $this->assertSame('saml_invalid', $this->redirectQuery($response)['sso_error'] ?? null);
    }

    /**
     * 관리자 경로로 시작하면 관리자 로그인 화면으로 인계한다.
     *
     * @scenario guard=admin
     *
     * @effects admin_login_screen
     *
     * @return void 반환값 없음
     */
    public function test_admin_guard_returns_to_admin_login(): void
    {
        $idp = $this->mockSamlIdp();
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $this->link($user, $idp, 'saml-admin-1');

        $response = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-admin-1'], 'guard=admin');

        $this->assertSame('/admin/login', $this->redirectPath($response));
        $this->assertNotSame('', $this->redirectQuery($response)['login_handoff'] ?? '');
    }

    /**
     * POST 바인딩 IdP 는 자동 제출 폼(현재 로케일 문구)으로 AuthnRequest 를 보낸다.
     *
     * @scenario binding=post, locale=ko|en
     *
     * @effects auto_submit_form, localized_text
     *
     * @return void 반환값 없음
     */
    public function test_post_binding_renders_auto_submit_form(): void
    {
        $idp = $this->mockSamlIdp(['config' => ['authn_request_binding' => 'post']]);

        $response = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key);

        $response->assertStatus(200);
        $html = (string) $response->getContent();
        $this->assertStringContainsString('action="'.e(SiteUrl::absolute('/plugins/sirsoft-sso/mock/saml/sso')).'"', $html);
        $this->assertStringContainsString('name="SAMLRequest"', $html);
        $this->assertStringContainsString('name="RelayState"', $html);
        $this->assertStringContainsString((string) __('sirsoft-sso::saml.autosubmit.message'), $html);
        $this->assertStringNotContainsString('sirsoft-sso::', $html, '문구 키가 그대로 보이지 않는다');
    }

    /**
     * 재확인 의도는 IdP 에 다시 인증을 요구한다(ForceAuthn).
     *
     * @scenario intent=reauth
     *
     * @effects force_authn
     *
     * @return void 반환값 없음
     */
    public function test_reauth_forces_authentication(): void
    {
        $idp = $this->mockSamlIdp();
        $intent = app(StateStore::class)->create([
            'intent' => LoginIntent::REAUTH, 'guard' => 'user', 'redirect' => '/', 'idp_id' => $idp->id, 'idp_key' => $idp->key,
        ], str_repeat('b', 64));

        $location = (string) app(ProtocolRegistry::class)->get('saml')->beginLogin($idp, $intent)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringContainsString('ForceAuthn="true"', (string) gzinflate((string) base64_decode((string) $query['SAMLRequest'])));
    }
}
