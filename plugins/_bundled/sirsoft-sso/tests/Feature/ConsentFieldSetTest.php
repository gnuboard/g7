<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Listeners\Provisioning\PrefillConsentFieldsListener;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 첫 로그인 동의 창이 받는 회원 항목 — 운영자가 환경설정 > 회원에서 정한 가입 항목을 코어 가입 화면과 같게 받는다.
 *
 * 동의 창이 항목을 그렸으면 받은 정의 버전(`fields_version`)을 요청에 싣고, 그 요청에만 항목 규칙(필수 포함)이 걸린다.
 * 버전을 싣지 않는 창(약관만 받는 원본 창, 관리자 로그인 화면의 동의 창)은 종전과 같은 본문으로 가입되고 빠진 필수 항목은
 * 첫 로그인 뒤 받는다. IdP 가 값을 주는 칸은 창에서 읽기 전용이고, 요청이 다른 값을 실어 와도 IdP 값이 저장된다.
 *
 * @since 1.0.0
 */
class ConsentFieldSetTest extends PluginTestCase
{
    /**
     * 운영자가 가입 필수로 둔 추가 항목(사번)과 선택 추가 항목(메모)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->customField('sso490_emp', ['name' => ['ko' => '사번', 'en' => 'Employee no.'], 'required' => true]);
        $this->customField('sso490_memo', ['name' => ['ko' => '메모', 'en' => 'Memo']]);
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return void 반환값 없음
     */
    private function customField(string $key, array $attributes = []): void
    {
        FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key],
            'enabled' => true, 'required' => false, 'show_on_signup' => true, 'sort_order' => 900,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 회사 계정 왕복을 마치고 동의 대기 토큰을 꺼냅니다.
     *
     * @param  IdentityProvider  $idp  로그인할 회사 계정 IdP
     * @param  string  $subject  IdP 안의 식별자
     * @param  string  $email  이메일
     * @param  string  $name  표시 이름
     * @return string 동의 대기 토큰
     */
    private function beginConsent(IdentityProvider $idp, string $subject, string $email, string $name = '새직원'): string
    {
        return $this->redirectQuery($this->roundTrip($idp, 'full', ['sub' => $subject, 'email' => $email, 'name' => $name]))['sso_pending'];
    }

    /**
     * 동의 창이 받는 회원 항목과 정의 버전을 조회합니다 (코어 가입 화면과 같은 API, 같은 브라우저).
     *
     * @param  string  $token  동의 대기 토큰
     * @return TestResponse 응답
     */
    private function fields(string $token): TestResponse
    {
        return $this->withBrowserBind()
            ->getJson('/api/auth/register/fields?pending_source='.PrefillConsentFieldsListener::PENDING_SOURCE.'&pending_token='.$token)
            ->assertOk();
    }

    /**
     * 동의 완료를 요청합니다 (같은 브라우저).
     *
     * @param  string  $token  동의 대기 토큰
     * @param  array<string, mixed>  $input  추가 입력
     * @return TestResponse 응답
     */
    private function complete(string $token, array $input = []): TestResponse
    {
        return $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$token.'/complete', $input + [
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);
    }

    /**
     * 지금의 가입 항목 정의 버전.
     *
     * @return string 정의 버전
     */
    private function version(): string
    {
        return app(FieldRegistry::class)->version('core.user');
    }

    /**
     * 응답의 항목 계약을 키로 찾습니다.
     *
     * @param  TestResponse  $response  항목 조회 응답
     * @param  string  $key  항목 키
     * @return array<string, mixed>|null 항목 계약
     */
    private function field(TestResponse $response, string $key): ?array
    {
        foreach ($response->json('data.fields') as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * 정의 버전을 싣지 않은 요청은 종전과 같은 본문(약관 둘)으로 가입되고, 빠진 필수 항목은 나중에 받도록 표시가 남는다.
     *
     * 회원 항목을 그리지 않는 창(플러그인 원본 창, 오버라이드가 없는 템플릿, 관리자 로그인 화면)에서도 가입이 깨지지 않아야 한다.
     *
     * @scenario fields_version=absent, locked=none
     *
     * @effects legacy_body_creates_member_and_defers_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_request_without_fields_version_creates_member_and_defers_required_fields(): void
    {
        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');
        $token = $this->beginConsent($idp, 'fs-1', $email);

        // 실어 보낸 항목 값은 규칙에 없어 버려진다(종전과 같다).
        $this->complete($token, ['custom_fields' => ['sso490_memo' => '버려질 값']])
            ->assertOk()
            ->assertJsonStructure(['data' => ['handoff_code', 'guard', 'redirect']]);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('새직원', $user->name);
        $this->assertNotNull($user->required_fields_deferred_at, '항목을 받지 않은 생성인데 필수 항목 나중 입력 표시가 없다');
        $this->assertNull(app(FieldValueService::class)->read($user, 'core.user')['sso490_memo'] ?? null, '규칙에 없는 항목 값이 저장됐다');
    }

    /**
     * 정의 버전을 실은 요청은 필수 항목이 비면 그 칸의 키로 422 를 주고 동의 대기를 남긴다. 채워 다시 보내면 만들어지고 값이 저장된다.
     *
     * @scenario fields_version=current, locked=none
     *
     * @effects required_field_missing_returns_field_keyed_error, consent_pending_survives_validation_error, field_values_outside_fixed_keys_are_saved, field_set_consent_is_not_deferred
     *
     * @return void 반환값 없음
     */
    public function test_required_field_missing_returns_422_then_values_are_saved(): void
    {
        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');
        $token = $this->beginConsent($idp, 'fs-2', $email);

        $this->complete($token, ['name' => '새직원', 'fields_version' => $this->version()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.sso490_emp']);
        $this->assertFalse(User::query()->where('email', $email)->exists(), '필수 항목이 비었는데 회원이 만들어졌다');

        // 검증 오류는 동의 대기를 쓰지 않는다 — 같은 토큰으로 빠진 칸만 채워 다시 보낸다.
        $this->complete($token, [
            'name' => '고친 이름', 'mobile' => '010-1234-5678', 'fields_version' => $this->version(),
            'custom_fields' => ['sso490_emp' => 'E-490', 'sso490_memo' => '메모 값'],
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $values = app(FieldValueService::class)->read($user, 'core.user');
        // IdP 규칙이 없는 이름은 창의 초기값일 뿐이라 고친 값이 저장된다.
        $this->assertSame('고친 이름', $user->name);
        $this->assertSame('010-1234-5678', $user->mobile);
        $this->assertSame('E-490', $values['sso490_emp'] ?? null);
        $this->assertSame('메모 값', $values['sso490_memo'] ?? null);
        $this->assertNull($user->required_fields_deferred_at, '필수 항목을 다 받은 생성인데 나중 입력 표시가 남았다');
    }

    /**
     * 동의 대기 토큰을 넘긴 항목 조회는 IdP 값을 초기값으로 주고 IdP 가 관리하는 칸을 읽기 전용(사유 sso)으로 준다.
     * 다른 브라우저의 조회에는 값을 주지 않고 대기도 폐기하지 않는다.
     *
     * @scenario fields_version=current, locked=sync_lock
     *
     * @effects idp_values_become_field_defaults, idp_managed_field_is_readonly_with_sso_reason, field_lookup_from_other_browser_gets_no_values_and_keeps_pending
     *
     * @return void 반환값 없음
     */
    public function test_idp_values_are_defaults_and_managed_fields_are_readonly(): void
    {
        $idp = $this->mockOidcIdp(['attribute_map' => [
            ['field' => 'nickname', 'source' => 'display_name', 'mode' => 'sync_lock'],
        ]]);
        $token = $this->beginConsent($idp, 'fs-3', $this->uniqueEmail('corp798.test'), '김직원');

        $response = $this->fields($token);
        $nickname = $this->field($response, 'nickname');
        $this->assertSame(['김직원', true, 'sso'], [$nickname['default'], $nickname['readonly'], $nickname['locked_reason']]);
        // 이름은 IdP 규칙이 없다 — 자동 생성 이름이 초기값이고 고칠 수 있다.
        $name = $this->field($response, 'name');
        $this->assertSame(['김직원', false, null], [$name['default'], $name['readonly'], $name['locked_reason']]);
        $this->assertNull($this->field($response, 'sso490_emp')['default']);
        // 저장된 항목 정의와 정의 버전은 바뀌지 않는다.
        $this->assertSame($this->version(), $response->json('data.fields_version'));

        // 다른 브라우저(묶음 쿠키 다름) — 신원을 내주지 않고, 대기도 폐기하지 않는다.
        $mine = $this->browserBind;
        $this->switchBrowser();
        $other = $this->fields($token);
        $this->assertSame([null, false], [$this->field($other, 'nickname')['default'], $this->field($other, 'nickname')['readonly']]);
        $this->browserBind = $mine;
        $this->assertSame('김직원', $this->field($this->fields($token), 'nickname')['default']);

        // 출처를 밝히지 않은 조회에도 값을 주지 않는다.
        $plain = $this->withBrowserBind()->getJson('/api/auth/register/fields?pending_token='.$token)->assertOk();
        $this->assertNull($this->field($plain, 'nickname')['default']);
    }

    /**
     * IdP 가 관리하는 칸은 요청이 다른 값을 실어 와도 무시하고 IdP 값으로 저장한다. 그 칸의 규칙은 걸지 않는다.
     *
     * @scenario fields_version=current, locked=sync_lock
     *
     * @effects idp_managed_field_input_is_ignored
     *
     * @return void 반환값 없음
     */
    public function test_idp_managed_field_input_is_ignored(): void
    {
        $idp = $this->mockOidcIdp(['attribute_map' => [
            ['field' => 'nickname', 'source' => 'display_name', 'mode' => 'sync_lock'],
        ]]);
        $email = $this->uniqueEmail('corp798.test');
        $token = $this->beginConsent($idp, 'fs-4', $email, '회사가 준 닉');

        // 닉네임 칸에 규칙을 어기는 값(51자 초과)을 실어도 그 칸은 규칙에서 빠져 오류가 나지 않는다.
        $this->complete($token, [
            'name' => '새직원', 'nickname' => str_repeat('가', 80), 'fields_version' => $this->version(),
            'custom_fields' => ['sso490_emp' => 'E-491'],
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('회사가 준 닉', $user->nickname);
        $this->assertSame('새직원', $user->name);
    }

    /**
     * 창을 연 뒤 운영자가 가입 항목을 바꿨으면 코어 가입과 같은 409(fields_changed)로 답하고 동의 대기는 남는다.
     *
     * @scenario fields_version=stale, locked=none
     *
     * @effects stale_fields_version_returns_409_and_keeps_pending
     *
     * @return void 반환값 없음
     */
    public function test_stale_fields_version_returns_409_and_keeps_pending(): void
    {
        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');
        $token = $this->beginConsent($idp, 'fs-5', $email);
        $stale = $this->version();

        $this->customField('sso490_added', ['name' => ['ko' => '새 항목']]);
        $current = $this->version();
        $this->assertNotSame($stale, $current);

        $body = ['name' => '새직원', 'custom_fields' => ['sso490_emp' => 'E-492']];
        $this->complete($token, $body + ['fields_version' => $stale])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'fields_changed');
        $this->assertFalse(User::query()->where('email', $email)->exists());

        $this->complete($token, $body + ['fields_version' => $current])->assertOk();
        $this->assertTrue(User::query()->where('email', $email)->exists());
    }

    /**
     * 자동 가입 데이터 필터의 이름과 인자(가입 데이터, 신원, IdP)는 그대로이고, 항목 값은 가입 데이터에 실려 필터에 보인다.
     *
     * @scenario fields_version=current, locked=none
     *
     * @effects jit_registration_data_filter_contract_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_jit_registration_data_filter_contract_is_unchanged(): void
    {
        $seen = [];
        HookManager::addFilter('sirsoft-sso.jit.filter_registration_data', function (...$args) use (&$seen) {
            $seen[] = $args;

            return array_merge($args[0], ['name' => '필터가 고친 이름']);
        });

        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');
        $token = $this->beginConsent($idp, 'fs-6', $email);
        $this->complete($token, [
            'name' => '새직원', 'fields_version' => $this->version(), 'custom_fields' => ['sso490_emp' => 'E-493'],
        ])->assertOk();

        $this->assertCount(1, $seen);
        $this->assertCount(3, $seen[0], '필터 인자는 (가입 데이터, 신원, IdP) 셋이다');
        [$data, $identity, $seenIdp] = $seen[0];
        $this->assertInstanceOf(ExternalIdentity::class, $identity);
        $this->assertInstanceOf(IdentityProvider::class, $seenIdp);
        // 종전 키(이름, 이메일, 언어)는 그대로 있고 항목 값이 더해졌다. 약관과 정의 버전은 가입 데이터가 아니다.
        foreach (['name', 'email', 'language'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(['sso490_emp' => 'E-493'], $data['custom_fields']);
        $this->assertArrayNotHasKey('fields_version', $data);
        $this->assertArrayNotHasKey('agree_terms', $data);

        $this->assertSame('필터가 고친 이름', User::query()->where('email', $email)->value('name'));
    }
}
