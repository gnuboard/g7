<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\ProvidesVerifiedClaimsInterface;
use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Fields\FieldContext;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityClaimsService;
use App\Services\IdentityVerificationService;
use App\Services\UserLockedFieldsService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 본인인증 값(이름, 휴대폰, 생년월일, 성별)을 회원 항목에 잇는 중립 계약.
 *
 * 인증 값을 주는 가짜 수단으로 채움, 덮어쓰기, 잠금, 재인증 동기화를 재고, 계약을 구현하지 않은 수단과 만료된 토큰에서는
 * 채움도 잠금도 일어나지 않음을 잰다.
 */
class VerifiedClaimsTest extends TestCase
{
    use RefreshDatabase;

    /** 인증 값을 주는 가짜 수단의 식별자 */
    private const CLAIMS_PROVIDER = 'test:claims';

    /**
     * 역할과 코어 회원 항목을 시딩하고, 인증 값을 주는 수단과 주지 않는 수단을 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $logs = app(IdentityVerificationLogRepositoryInterface::class);
        $manager = app(IdentityVerificationManager::class);
        $manager->register(new ClaimsTestIdentityProvider($logs));
        $manager->register(new TestIdentityProvider($logs));

        ClaimsTestIdentityProvider::$claims = new VerifiedClaims(
            name: '홍길동',
            mobile: '01012345678',
            birthDate: '1990-05-17',
            gender: VerifiedClaims::GENDER_FEMALE,
        );

        // 이름은 인증 값으로 채우고 잠근다. 휴대폰은 채우기만 한다(코어 선언의 연결을 그대로 쓴다).
        FieldDefinition::where('field_set', 'core.user')->where('key', 'name')->update(['idv_lock' => true]);
        $this->custom('vc_birth', 'date', ['idv_claim' => 'birth_date', 'idv_lock' => true]);
        $this->custom('vc_gender', 'choice', [
            'idv_claim' => 'gender',
            'options' => [['value' => 'male', 'label' => ['ko' => '남']], ['value' => 'female', 'label' => ['ko' => '여']]],
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        // 가입 인증 값은 가입 전 본인인증 정책이 켜진 사이트에서만 쓴다 — 정책 꺼짐은 test_policy_off_* 가 잰다
        $this->enableSignupVerificationPolicy();
    }

    /**
     * 등록한 가짜 수단과 테스트가 건 필터를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $manager = app(IdentityVerificationManager::class);
        $manager->unregister(self::CLAIMS_PROVIDER);
        $manager->unregister(TestIdentityProvider::ID);
        HookManager::clearFilter('core.identity.filter_verified_claims');
        ClaimsTestIdentityProvider::$claims = null;
        parent::tearDown();
    }

    /**
     * 운영자 추가 항목을 만듭니다 (가입 화면에 보이는 본인 수정 항목).
     *
     * @param  string  $key  항목 키
     * @param  string  $type  유형 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, string $type, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => $type, 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
            'enabled' => true, 'show_on_signup' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 확인을 마친 본인인증 요청 행을 만들고 토큰을 반환합니다.
     *
     * @param  string  $providerId  수단 식별자
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return string 본인인증 토큰
     */
    private function verifiedToken(string $providerId = self::CLAIMS_PROVIDER, array $attributes = []): string
    {
        $token = Str::random(48);

        app(IdentityVerificationLogRepositoryInterface::class)->create(array_merge([
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => 'phone',
            'user_id' => null,
            'target_hash' => hash('sha256', 'signup@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'external_redirect',
            'attempts' => 0,
            'max_attempts' => 5,
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ], $attributes));

        return $token;
    }

    /**
     * 가입 전 본인인증 정책을 켭니다.
     *
     * 가입 인증 값(채움·덮어쓰기)과 가입 뒤 회원 본인인증 기록은 가입 전 본인인증 정책이 켜진 사이트에서만 쓴다. 기록은 그 가입
     * 요청이 인정(결박 확인 + 1회 소비)한 결과만 쓰고(7.1.6 「같은 요청에서 인정한 결과만」), 정책이 꺼진 사이트는 결과를 인정하지도
     * 쓰지도 않는다(2026-11-17 결정).
     *
     * @return void 반환값 없음
     */
    private function enableSignupVerificationPolicy(): void
    {
        $this->seed(IdentityPolicySeeder::class);
        // 이 시험이 재는 것은 가입 전 확인 하나다 — 기본으로 켜진 다른 정책(내 정보 수정 등)은 끈다
        IdentityPolicy::query()->update(['enabled' => false]);
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);
    }

    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 요청 본문
     */
    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'signup@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'name' => '입력한이름',
            'mobile' => '010-9999-8888',
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $overrides);
    }

    /**
     * 인증 값을 주는 수단의 토큰이면 채움값 API 와 가입 항목 API 가 인증 값으로 채우고 잠금 항목을 읽기 전용으로 준다.
     *
     * @scenario case=fill
     *
     * @effects claims_api_returns_claims_and_field_values, signup_fields_prefilled_from_claims, signup_locked_fields_marked_readonly_identity
     *
     * @return void 반환값 없음
     */
    public function test_claims_fill_signup_fields(): void
    {
        $token = $this->verifiedToken();

        $data = $this->postJson('/api/identity/claims', ['verification_token' => $token])->assertOk()->json('data');
        $this->assertSame('applied', $data['status']);
        $this->assertSame(self::CLAIMS_PROVIDER, $data['provider_id']);
        $this->assertSame(['name' => '홍길동', 'mobile' => '01012345678', 'birth_date' => '1990-05-17', 'gender' => 'female'], $data['claims']);
        $this->assertSame(['name' => '홍길동', 'mobile' => '01012345678', 'vc_birth' => '1990-05-17', 'vc_gender' => 'female'], $data['fields']);
        $this->assertSame(['name', 'vc_birth'], $data['locked']);

        $signup = $this->getJson('/api/auth/register/fields?verification_token='.$token)->assertOk()->json('data');
        $fields = array_column($signup['fields'], null, 'key');

        $this->assertSame(['status' => 'applied', 'filled' => ['name', 'mobile', 'vc_birth', 'vc_gender'], 'locked' => ['name', 'vc_birth']], $signup['verified_claims']);
        $this->assertSame('홍길동', $fields['name']['default']);
        $this->assertTrue($fields['name']['readonly']);
        $this->assertSame('identity', $fields['name']['locked_reason']);
        $this->assertSame('1990-05-17', $fields['vc_birth']['default']);
        $this->assertSame('identity', $fields['vc_birth']['locked_reason']);
        // 잠금을 켜지 않은 항목은 채우기만 한다.
        $this->assertSame('01012345678', $fields['mobile']['default']);
        $this->assertFalse($fields['mobile']['readonly']);
        $this->assertNull($fields['mobile']['locked_reason']);
        $this->assertSame('female', $fields['vc_gender']['default']);

        // 토큰 없이 부르면 채움도 잠금도 없다.
        $plain = $this->getJson('/api/auth/register/fields')->assertOk()->json('data');
        $this->assertNull($plain['verified_claims']);
        $this->assertFalse(array_column($plain['fields'], null, 'key')['name']['readonly']);
    }

    /**
     * 가입 요청은 잠금 항목의 입력을 인증 값으로 덮어쓴다 (입력이 달라도 오류가 아니다). 잠금을 켜지 않은 항목은 입력을 그대로 받는다.
     *
     * @scenario case=overwrite
     *
     * @effects signup_locked_input_overwritten_by_claims, signup_unlocked_input_kept, signup_verification_recorded_on_member
     *
     * @return void 반환값 없음
     */
    public function test_register_overwrites_locked_input_with_claims(): void
    {
        $token = $this->verifiedToken();

        $this->postJson('/api/auth/register', $this->signupPayload([
            'verification_token' => $token,
            'custom_fields' => ['vc_birth' => '2001-01-01', 'vc_gender' => 'male'],
        ]))->assertStatus(201);

        $user = User::where('email', 'signup@example.com')->firstOrFail();
        $values = app(FieldValueService::class)->read($user, 'core.user');

        $this->assertSame('홍길동', $user->name, '잠금 항목의 입력이 인증 값으로 덮이지 않았다');
        $this->assertSame('1990-05-17', $values['vc_birth'] ?? null);
        $this->assertSame('010-9999-8888', $user->mobile, '잠금을 켜지 않은 항목의 입력이 바뀌었다');
        $this->assertSame('male', $values['vc_gender'] ?? null);
        $this->assertSame(self::CLAIMS_PROVIDER, $user->identity_verified_provider);
        $this->assertNotNull($user->identity_verified_at);
    }

    /**
     * 인증 값으로 채워진 잠금 항목은 내 정보 수정에서 잠긴다 — 화면 계약은 읽기 전용과 사유를 주고, 다른 값으로 고치면 422 다.
     *
     * @scenario case=lock
     *
     * @effects profile_locks_identity_filled_fields, profile_update_rejects_changed_locked_field, admin_surface_not_locked_by_identity
     *
     * @return void 반환값 없음
     */
    public function test_identity_filled_fields_are_locked_on_profile(): void
    {
        $token = $this->verifiedToken();
        $this->postJson('/api/auth/register', $this->signupPayload(['verification_token' => $token]))->assertStatus(201);
        $user = User::where('email', 'signup@example.com')->firstOrFail();
        $this->app['auth']->forgetGuards();

        $fields = array_column($this->actingAs($user, 'sanctum')->getJson('/api/me/fields')->assertOk()->json('data.fields'), null, 'key');
        $this->assertTrue($fields['name']['readonly']);
        $this->assertSame('identity', $fields['name']['locked_reason']);
        $this->assertTrue($fields['vc_birth']['readonly']);
        $this->assertSame('identity', $fields['vc_birth']['locked_reason']);
        $this->assertFalse($fields['mobile']['readonly']);

        $auth = $this->actingAs($user, 'sanctum')->getJson('/api/auth/user')->assertOk()->json('data');
        $this->assertSame(['name'], $auth['locked_fields']);
        $this->assertSame(['name' => 'identity'], $auth['locked_reasons']);

        // 잠긴 기본 칸과 잠긴 추가 항목을 다른 값으로 보내면 거부한다.
        $this->actingAs($user, 'sanctum')->putJson('/api/me', ['name' => '다른이름', 'email' => $user->email])
            ->assertStatus(422)->assertJsonPath('errors.name.0', __('fields.validation.locked_identity'));
        $rejected = $this->actingAs($user, 'sanctum')->putJson('/api/me', ['name' => '홍길동', 'email' => $user->email, 'custom_fields' => ['vc_birth' => '2001-01-01']])
            ->assertStatus(422);
        $this->assertSame([__('fields.validation.locked_identity')], $rejected->json('errors')['custom_fields.vc_birth'] ?? null);

        // 같은 값을 그대로 보내거나 잠기지 않은 칸을 고치는 것은 통과한다.
        $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'name' => '홍길동', 'email' => $user->email, 'mobile' => '010-2222-3333', 'custom_fields' => ['vc_birth' => '1990-05-17'],
        ])->assertOk();
        $this->assertSame('010-2222-3333', $user->fresh()->mobile);

        // 관리자 화면 표면은 본인인증으로 잠그지 않는다.
        $this->assertSame([], app(UserLockedFieldsService::class)->lockedFor($user->fresh(), 'admin'));
    }

    /**
     * 로그인 회원이 다시 본인인증을 마치면 잠금 항목이 새 인증 값으로 맞춰진다.
     *
     * @scenario case=resync
     *
     * @effects reverification_syncs_locked_fields_to_new_claims
     *
     * @return void 반환값 없음
     */
    public function test_reverification_syncs_locked_fields(): void
    {
        $user = User::factory()->create(['name' => '옛이름', 'mobile' => '010-0000-0000']);
        ClaimsTestIdentityProvider::$claims = new VerifiedClaims(name: '개명한이름', mobile: '01077776666', birthDate: '1988-12-01');

        $service = app(IdentityVerificationService::class);
        $challenge = $service->start('sensitive_action', $user, [], self::CLAIMS_PROVIDER);
        $result = $service->verify($challenge->id, ['code' => TestIdentityProvider::FIXED_CODE]);
        $this->assertTrue($result->success);

        $fresh = $user->fresh();
        $this->assertSame('개명한이름', $fresh->name);
        $this->assertSame('1988-12-01', app(FieldValueService::class)->read($fresh, 'core.user')['vc_birth'] ?? null);
        $this->assertSame('010-0000-0000', $fresh->mobile, '잠금을 켜지 않은 항목이 재인증으로 바뀌었다');
        $this->assertSame(self::CLAIMS_PROVIDER, $fresh->identity_verified_provider);
        $this->assertSame(['name', 'vc_birth'], app(IdentityClaimsService::class)->lockedKeysFor($fresh));
    }

    /**
     * 계약을 구현하지 않은 수단의 토큰은 인증 값이 없다 — 채움도, 덮어쓰기도, 잠금도 일어나지 않는다.
     *
     * @scenario case=provider_without_claims
     *
     * @effects provider_without_contract_yields_no_claims, signup_input_kept_without_claims, no_lock_without_claims_provider
     *
     * @return void 반환값 없음
     */
    public function test_provider_without_contract_gives_no_claims(): void
    {
        $token = $this->verifiedToken(TestIdentityProvider::ID);

        $data = $this->postJson('/api/identity/claims', ['verification_token' => $token])->assertOk()->json('data');
        $this->assertSame('no_claims', $data['status']);
        $this->assertSame([], $data['claims']);
        $this->assertSame([], $data['fields']);
        $this->assertSame([], $data['locked']);

        $signup = $this->getJson('/api/auth/register/fields?verification_token='.$token)->assertOk()->json('data');
        $this->assertSame(['status' => 'no_claims', 'filled' => [], 'locked' => []], $signup['verified_claims']);
        $this->assertFalse(array_column($signup['fields'], null, 'key')['name']['readonly']);

        $this->postJson('/api/auth/register', $this->signupPayload(['verification_token' => $token]))->assertStatus(201);
        $user = User::where('email', 'signup@example.com')->firstOrFail();
        $this->assertSame('입력한이름', $user->name);
        $this->assertNull($user->identity_verified_provider);
        $this->assertSame([], app(IdentityClaimsService::class)->lockedKeysFor($user));

        // 확장은 필터로 계약을 구현하지 않은 수단의 인증 값을 대신 줄 수 있다.
        $second = $this->verifiedToken(TestIdentityProvider::ID);
        HookManager::addFilter('core.identity.filter_verified_claims', fn ($claims, $log) => $log instanceof IdentityVerificationLog
            ? new VerifiedClaims(name: '필터가준이름')
            : $claims);
        $filtered = $this->postJson('/api/identity/claims', ['verification_token' => $second])->assertOk()->json('data');
        $this->assertSame('applied', $filtered['status']);
        $this->assertSame(['name' => '필터가준이름'], $filtered['fields']);
    }

    /**
     * 만료됐거나 알 수 없는 토큰은 채움값을 주지 않는다 — 가입 항목은 잠금 없이 오고 상태로 알린다.
     *
     * @scenario case=token_expired
     *
     * @effects expired_token_rejected_by_claims_api, expired_token_leaves_signup_fields_unlocked, expired_token_does_not_overwrite_signup_input, member_bound_token_hidden_from_others
     *
     * @return void 반환값 없음
     */
    public function test_expired_or_unknown_token_gives_nothing(): void
    {
        $expired = $this->verifiedToken(self::CLAIMS_PROVIDER, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/identity/claims', ['verification_token' => $expired])
            ->assertStatus(422)
            ->assertJsonPath('errors.status', 'expired')
            ->assertJsonPath('message', __('identity.errors.invalid_verification_token'));
        $this->postJson('/api/identity/claims', ['verification_token' => 'unknown-token'])
            ->assertStatus(422)->assertJsonPath('errors.status', 'invalid');
        $this->postJson('/api/identity/claims', [])->assertStatus(422)->assertJsonValidationErrors(['verification_token']);

        $signup = $this->getJson('/api/auth/register/fields?verification_token='.$expired)->assertOk()->json('data');
        $this->assertSame(['status' => 'expired', 'filled' => [], 'locked' => []], $signup['verified_claims']);
        $name = array_column($signup['fields'], null, 'key')['name'];
        $this->assertFalse($name['readonly']);
        $this->assertNull($name['locked_reason']);
        $this->assertNull($name['default']);

        // 만료된 토큰으로는 입력을 덮어쓰지 않는다 — 가입 전 본인인증 정책이 켜져 있으니 가입 자체가 토큰 검증 오류(422)로 막힌다.
        $this->postJson('/api/auth/register', $this->signupPayload(['verification_token' => $expired]))
            ->assertStatus(422)->assertJsonValidationErrors(['verification_token']);
        $this->assertDatabaseMissing('users', ['email' => 'signup@example.com']);

        // 회원에게 묶인 토큰은 그 회원으로 로그인한 요청만 값을 받는다.
        $owner = User::factory()->create();
        $bound = $this->verifiedToken(self::CLAIMS_PROVIDER, ['user_id' => $owner->id, 'purpose' => 'sensitive_action']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/identity/claims', ['verification_token' => $bound, 'purpose' => 'sensitive_action'])
            ->assertStatus(422)->assertJsonPath('errors.status', 'invalid');
        $this->actingAs($owner, 'sanctum')->postJson('/api/identity/claims', ['verification_token' => $bound, 'purpose' => 'sensitive_action'])
            ->assertOk()->assertJsonPath('data.status', 'applied');
    }

    /**
     * 가입 전 본인인증 정책이 꺼진 사이트는 가입 요청의 본인인증 결과를 인정하지도 쓰지도 않는다.
     *
     * 인증 값을 주는 수단의 유효한 토큰이라도 채움값 API·가입 항목 API 는 채우지 않고, 가입은 입력 그대로 받으며 회원 본인인증과
     * 이메일 인증을 기록하지 않고, 토큰은 소비되지 않는다(7.1.6 계약 — 정책 꺼짐 ⇒ 결과를 인정·소비하지 않음, 2026-11-17 결정).
     *
     * @scenario case=policy_off
     *
     * @effects policy_off_claims_api_disabled, policy_off_signup_fields_not_prefilled, policy_off_signup_input_kept, policy_off_nothing_recorded, policy_off_token_not_consumed
     *
     * @return void 반환값 없음
     */
    public function test_policy_off_signup_ignores_verification_result(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => false]);
        $token = $this->verifiedToken();

        $data = $this->postJson('/api/identity/claims', ['verification_token' => $token])->assertOk()->json('data');
        $this->assertSame('disabled', $data['status']);
        $this->assertNull($data['provider_id']);
        $this->assertSame([], $data['claims']);
        $this->assertSame([], $data['fields']);
        $this->assertSame([], $data['locked']);

        $signup = $this->getJson('/api/auth/register/fields?verification_token='.$token)->assertOk()->json('data');
        $this->assertNull($signup['verified_claims']);
        $name = array_column($signup['fields'], null, 'key')['name'];
        $this->assertFalse($name['readonly']);
        $this->assertNull($name['locked_reason']);
        $this->assertNull($name['default']);

        $this->postJson('/api/auth/register', $this->signupPayload([
            'verification_token' => $token,
            'custom_fields' => ['vc_birth' => '2001-01-01'],
        ]))->assertStatus(201);

        $user = User::where('email', 'signup@example.com')->firstOrFail();
        $this->assertSame('입력한이름', $user->name, '정책이 꺼졌는데 잠금 항목을 인증 값으로 덮어썼다');
        $this->assertSame('2001-01-01', app(FieldValueService::class)->read($user, 'core.user')['vc_birth'] ?? null);
        $this->assertNull($user->identity_verified_provider, '정책이 꺼졌는데 본인인증을 기록했다');
        $this->assertNull($user->identity_verified_at);
        $this->assertNull($user->email_verified_at, '정책이 꺼졌는데 이메일 인증을 기록했다');
        $this->assertSame([], app(IdentityClaimsService::class)->lockedKeysFor($user));
        $this->assertNull(IdentityVerificationLog::where('verification_token', $token)->value('consumed_at'), '정책이 꺼졌는데 토큰을 소비했다');
        $this->assertNull(IdentityVerificationLog::where('verification_token', $token)->value('user_id'));
    }

    /**
     * 선택지에 없는 값이나 형식이 다른 인증 값은 항목에 넣지 않는다.
     *
     * @scenario case=value_rejected_by_type
     *
     * @effects claims_not_matching_field_type_are_skipped
     *
     * @return void 반환값 없음
     */
    public function test_claims_not_matching_field_type_are_skipped(): void
    {
        ClaimsTestIdentityProvider::$claims = new VerifiedClaims(name: '홍길동', birthDate: '19900517', gender: 'unknown');
        $claims = app(IdentityClaimsService::class)->forToken($this->verifiedToken());

        $this->assertNotNull($claims);
        $this->assertSame(['name' => '홍길동'], app(IdentityClaimsService::class)->fieldValues($claims));
        $this->assertSame(['name' => '홍길동'], app(IdentityClaimsService::class)->fieldValues($claims, lockedOnly: true));
    }

    /**
     * 잠금은 지금 값이 최근 인증 값과 같은 항목에만 건다.
     *
     * 운영자가 회원 인증 뒤에 「인증 값으로 채우고 잠금」을 켰거나, 인증 값이 유형에 맞지 않아 채워지지 않은 칸에 회원이 직접
     * 쓴 값은 인증 값이 아니므로 잠그지 않는다. 최근 인증 기록을 찾을 수 없거나 그 기록에서 인증 값을 얻지 못해도 잠그지 않는다.
     *
     * @scenario case=lock_value_mismatch
     *
     * @effects lock_applies_only_when_value_matches_latest_claims
     *
     * @return void 반환값 없음
     */
    public function test_lock_applies_only_when_value_matches_latest_claims(): void
    {
        // 회원이 직접 쓴 이름(인증 값과 다름)과 인증 값과 같은 생년월일
        $user = User::factory()->create(['name' => '직접쓴이름', 'identity_verified_provider' => self::CLAIMS_PROVIDER, 'identity_verified_at' => now()]);
        app(FieldValueService::class)->write($user, 'core.user', ['vc_birth' => '1990-05-17'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        $this->verifiedToken(attributes: ['user_id' => $user->id, 'purpose' => 'sensitive_action']);

        $this->assertSame(['vc_birth'], app(IdentityClaimsService::class)->lockedKeysFor($user->fresh()), '인증 값과 다른 이름까지 잠겼다');

        // 인증 기록이 없는 회원은 수단이 같아도 잠그지 않는다.
        $orphan = User::factory()->create(['name' => '홍길동', 'identity_verified_provider' => self::CLAIMS_PROVIDER]);
        $this->assertSame([], app(IdentityClaimsService::class)->lockedKeysFor($orphan));
    }
}

/**
 * 인증 값을 주는 테스트 전용 본인인증 수단 — 고정 코드 수단에 선택 계약만 더했다.
 */
class ClaimsTestIdentityProvider extends TestIdentityProvider implements ProvidesVerifiedClaimsInterface
{
    public const ID = 'test:claims';

    /** 이 수단이 돌려줄 인증 값 (테스트가 바꾼다) */
    public static ?VerifiedClaims $claims = null;

    /**
     * 수단 식별자를 반환합니다.
     *
     * @return string 식별자
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * 요청 행을 만들고 수단 식별자를 이 수단으로 바꿉니다 (부모는 자기 식별자로 적는다).
     *
     * @param  User|array<string, mixed>  $target  대상
     * @param  array<string, mixed>  $context  문맥
     * @return VerificationChallenge 발행된 요청
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $challenge = parent::requestChallenge($target, $context);
        $this->logRepository->updateById($challenge->id, ['provider_id' => self::ID]);

        return $challenge;
    }

    /**
     * 고정 코드로 확인하고 결과의 수단 식별자를 이 수단으로 돌려줍니다.
     *
     * @param  string  $challengeId  요청 ID
     * @param  array<string, mixed>  $input  입력
     * @param  array<string, mixed>  $context  문맥
     * @return VerificationResult 확인 결과
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $this->logRepository->updateById($challengeId, ['provider_id' => parent::ID]);
        $result = parent::verify($challengeId, $input, $context);
        $this->logRepository->updateById($challengeId, ['provider_id' => self::ID]);

        return $result->success
            ? VerificationResult::success($challengeId, self::ID, $result->verifiedAt, claims: $result->claims)
            : $result;
    }

    /**
     * 줄 수 있는 인증 값 키를 반환합니다.
     *
     * @return array<int, string> 인증 값 키
     */
    public function supportedClaims(): array
    {
        return VerifiedClaims::CLAIMS;
    }

    /**
     * 테스트가 정해 둔 인증 값을 반환합니다.
     *
     * @param  IdentityVerificationLog  $log  확인을 마친 요청 행
     * @param  User|null  $user  값을 받을 회원
     * @return VerifiedClaims|null 인증 값
     */
    public function verifiedClaims(IdentityVerificationLog $log, ?User $user): ?VerifiedClaims
    {
        return self::$claims;
    }
}
