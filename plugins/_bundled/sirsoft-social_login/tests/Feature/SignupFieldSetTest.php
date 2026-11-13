<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\Services\SignupFlowService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 소셜 가입 완료 창이 받는 회원 항목 — 운영자가 환경설정 > 회원에서 정한 가입 항목을 코어 가입 화면과 같게 받는다.
 *
 * 가입 창이 항목을 그렸으면 받은 정의 버전(`fields_version`)을 요청에 싣고, 그 요청에만 항목 규칙(필수 포함)이 걸린다.
 * 버전을 싣지 않는 창(이름, 닉네임, 약관만 받는 원본 창)은 종전과 같은 본문으로 가입되고, 빠진 필수 항목은 첫 로그인 뒤 받는다.
 *
 * @since 1.0.0
 */
class SignupFieldSetTest extends PluginTestCase
{
    /**
     * 카카오를 켜고, 운영자가 가입 필수로 둔 추가 항목(직업)과 선택 추가 항목(메모)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->enableKakao();

        $this->customField('sl490_job', ['name' => ['ko' => '직업', 'en' => 'Job'], 'required' => true]);
        $this->customField('sl490_memo', ['name' => ['ko' => '메모', 'en' => 'Memo']]);
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
     * 카카오 콜백까지 마치고 가입 대기 토큰을 꺼냅니다.
     *
     * @param  array<string, mixed>  $profile  카카오 프로필
     * @return string 대기 토큰
     */
    private function beginSignup(array $profile): string
    {
        return $this->redirectQuery($this->kakaoRoundTrip($profile))['social_pending'];
    }

    /**
     * 가입 창이 받는 회원 항목과 정의 버전을 조회합니다 (코어 가입 화면과 같은 API).
     *
     * @param  string|null  $token  가입 대기 토큰 (null 이면 토큰 없이)
     * @return TestResponse 응답
     */
    private function fields(?string $token): TestResponse
    {
        $query = $token !== null ? '?pending_source='.SignupFlowService::PENDING_SOURCE.'&pending_token='.$token : '';

        return $this->getJson('/api/auth/register/fields'.$query)->assertOk();
    }

    /**
     * 가입 완료를 요청합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $input  추가 입력
     * @return TestResponse 응답
     */
    private function complete(string $token, array $input = []): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/complete', $input + [
            'pending_token' => $token,
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
     * 정의 버전을 싣지 않은 요청은 종전과 같은 본문으로 가입되고, 빠진 필수 항목은 나중에 받도록 표시가 남는다.
     *
     * 회원 항목을 그리지 않는 창(플러그인 원본 창, 오버라이드가 없는 템플릿)에서도 가입이 깨지지 않아야 한다.
     *
     * @scenario fields_version=absent
     *
     * @effects legacy_body_signs_up_and_defers_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_request_without_fields_version_signs_up_and_defers_required_fields(): void
    {
        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs1', $email));

        // 필수 추가 항목(직업)이 비어 있고, 실어 보낸 항목 값은 규칙에 없어 버려진다(종전과 같다).
        $this->complete($token, ['name' => '홍길동', 'custom_fields' => ['sl490_memo' => '버려질 값']])
            ->assertOk()
            ->assertJsonStructure(['data' => ['handoff_url']]);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(['홍길동', '카카오닉'], [$user->name, $user->nickname]);
        $this->assertNotNull($user->required_fields_deferred_at, '항목을 받지 않은 가입인데 필수 항목 나중 입력 표시가 없다');
        $this->assertNull(app(FieldValueService::class)->read($user, 'core.user')['sl490_memo'] ?? null, '규칙에 없는 항목 값이 저장됐다');
    }

    /**
     * 정의 버전을 실은 요청은 필수 항목이 비면 그 칸의 키로 422 를 주고, 채워 다시 보내면 가입된다(표시 없음).
     *
     * @scenario fields_version=current
     *
     * @effects required_field_missing_returns_field_keyed_error, field_set_signup_is_not_deferred
     *
     * @return void 반환값 없음
     */
    public function test_required_field_missing_returns_422_under_field_key(): void
    {
        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs2', $email));

        $this->complete($token, ['name' => '홍길동', 'nickname' => '길동', 'fields_version' => $this->version()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.sl490_job']);
        $this->assertFalse(User::query()->where('email', $email)->exists(), '필수 항목이 비었는데 회원이 만들어졌다');

        // 가입 대기는 남아 있어 빠진 칸만 채워 다시 보낼 수 있다
        $this->complete($token, [
            'name' => '홍길동', 'nickname' => '길동', 'fields_version' => $this->version(),
            'custom_fields' => ['sl490_job' => '개발자'],
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNull($user->required_fields_deferred_at, '필수 항목을 다 받은 가입인데 나중 입력 표시가 남았다');
    }

    /**
     * 고정 키(이름, 닉네임, 이메일, 약관) 밖의 항목 값(연락처, 운영자 추가 항목)이 버려지지 않고 저장된다.
     *
     * @scenario fields_version=current
     *
     * @effects field_values_outside_fixed_keys_are_saved
     *
     * @return void 반환값 없음
     */
    public function test_field_values_outside_fixed_keys_are_saved(): void
    {
        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs3', $email));

        $this->complete($token, [
            'name' => '홍길동', 'nickname' => '', 'mobile' => '010-1234-5678', 'fields_version' => $this->version(),
            'custom_fields' => ['sl490_job' => '개발자', 'sl490_memo' => '메모 값'],
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $values = app(FieldValueService::class)->read($user, 'core.user');
        $this->assertSame('개발자', $values['sl490_job'] ?? null);
        $this->assertSame('메모 값', $values['sl490_memo'] ?? null);
        $this->assertSame('010-1234-5678', $user->mobile);
        // 창에서 비운 닉네임은 비운 채로 저장된다 — 제공자 닉네임은 창의 초기값일 뿐 다시 채워 넣지 않는다.
        $this->assertNull($user->nickname);
    }

    /**
     * 가입 대기 토큰을 넘긴 항목 조회는 제공자가 준 이름, 닉네임을 초기값으로 주고, 가입 대기 조회의 미입력 판정에 필수 항목이 든다.
     *
     * @scenario fields_version=current
     *
     * @effects provider_values_become_field_defaults, pending_view_lists_required_fields_as_missing
     *
     * @return void 반환값 없음
     */
    public function test_provider_values_are_field_defaults(): void
    {
        $token = $this->beginSignup($this->kakaoProfile('k-fs4', $this->uniqueEmail('fs'), nickname: '라이언'));

        $withToken = $this->fields($token);
        $this->assertSame('라이언', $this->field($withToken, 'nickname')['default']);
        // 카카오는 이름 없이 닉네임만 준다 — 이름 칸의 초기값도 닉네임이다(고칠 수 있다).
        $this->assertSame('라이언', $this->field($withToken, 'name')['default']);
        $this->assertFalse($this->field($withToken, 'name')['readonly']);
        $this->assertNull($this->field($withToken, 'sl490_job')['default']);

        // 토큰이 없거나 없는 대기면 초기값을 주지 않는다(조회는 그대로 답한다). 저장된 항목 정의와 정의 버전은 바뀌지 않는다.
        $this->assertNull($this->field($this->fields(null), 'nickname')['default']);
        $this->assertNull($this->field($this->fields(str_repeat('a', 48)), 'nickname')['default']);
        $this->assertSame($this->version(), $withToken->json('data.fields_version'));

        $missing = $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$token)->assertOk()->json('data.missing');
        $this->assertContains('sl490_job', $missing);
        $this->assertNotContains('sl490_memo', $missing);
        $this->assertNotContains('nickname', $missing);
    }

    /**
     * 창을 연 뒤 운영자가 가입 항목을 바꿨으면 코어 가입과 같은 409(fields_changed)로 답하고 가입 대기는 남는다.
     *
     * @scenario fields_version=stale
     *
     * @effects stale_fields_version_returns_409_and_keeps_pending
     *
     * @return void 반환값 없음
     */
    public function test_stale_fields_version_returns_409_and_keeps_pending(): void
    {
        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs5', $email));
        $stale = $this->version();

        $this->customField('sl490_added', ['name' => ['ko' => '새 항목']]);
        $current = $this->version();
        $this->assertNotSame($stale, $current);

        $body = ['name' => '홍길동', 'custom_fields' => ['sl490_job' => '개발자']];
        $this->complete($token, $body + ['fields_version' => $stale])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'fields_changed');
        $this->assertFalse(User::query()->where('email', $email)->exists());

        $this->complete($token, $body + ['fields_version' => $current])->assertOk();
        $this->assertTrue(User::query()->where('email', $email)->exists());
    }

    /**
     * 항목을 받는 창의 닉네임도 사이트의 닉네임 정책을 따르고, 걸리면 닉네임 칸의 오류로 돌아온다.
     *
     * @scenario fields_version=current
     *
     * @effects nickname_policy_error_stays_under_nickname_field
     *
     * @return void 반환값 없음
     */
    public function test_nickname_policy_error_is_keyed_by_nickname(): void
    {
        config(['g7_settings.core.member.nickname_unique' => true]);
        app(AuthService::class)->register(
            ['name' => '먼저 쓴 회원', 'nickname' => '겹치는닉', 'email' => $this->uniqueEmail('taken')],
            ['issue_token' => false, 'record_consents' => false],
        );
        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs6', $email));

        $body = ['name' => '홍길동', 'fields_version' => $this->version(), 'custom_fields' => ['sl490_job' => '개발자']];
        $this->complete($token, $body + ['nickname' => '겹치는 닉'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nickname.0', __('user.validation.nickname_duplicate'));

        $this->complete($token, $body + ['nickname' => '안겹치는닉'])->assertOk();
        $this->assertSame('안겹치는닉', User::query()->where('email', $email)->value('nickname'));
    }

    /**
     * 가입 데이터 필터의 이름과 인자(가입 데이터, 제공자 프로필)는 그대로이고, 항목 값은 가입 데이터에 실려 필터에 보인다.
     *
     * @scenario fields_version=current
     *
     * @effects registration_data_filter_contract_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_registration_data_filter_contract_is_unchanged(): void
    {
        $seen = [];
        HookManager::addFilter('sirsoft-social_login.signup.filter_registration_data', function (...$args) use (&$seen) {
            $seen[] = $args;

            return array_merge($args[0], ['name' => '필터가 고친 이름']);
        });

        $email = $this->uniqueEmail('fs');
        $token = $this->beginSignup($this->kakaoProfile('k-fs7', $email));
        $this->complete($token, [
            'name' => '홍길동', 'fields_version' => $this->version(), 'custom_fields' => ['sl490_job' => '개발자'],
        ])->assertOk();

        $this->assertCount(1, $seen);
        $this->assertCount(2, $seen[0], '필터 인자는 (가입 데이터, 제공자 프로필) 둘이다');
        [$data, $profile] = $seen[0];
        $this->assertInstanceOf(NormalizedProfile::class, $profile);
        // 종전 키는 그대로 있고(이름, 닉네임, 이메일, 약관, 언어, 가입 경로), 항목 값이 더해졌다.
        foreach (['name', 'nickname', 'email', 'agree_terms', 'agree_privacy', 'language', 'social_provider'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame('kakao', $data['social_provider']);
        $this->assertSame(['sl490_job' => '개발자'], $data['custom_fields']);
        // 정의 버전과 대기 토큰은 가입 데이터가 아니다
        $this->assertArrayNotHasKey('fields_version', $data);
        $this->assertArrayNotHasKey('pending_token', $data);

        $this->assertSame('필터가 고친 이름', User::query()->where('email', $email)->value('name'));
    }
}
