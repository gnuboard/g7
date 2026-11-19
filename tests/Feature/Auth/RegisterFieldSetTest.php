<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 가입 요청(`POST /api/auth/register`)이 회원 항목 설정을 따르는지.
 *
 * 기본 설정에서는 종전과 같고, 운영자가 항목의 사용·필수·가입 표시를 바꾸면 검증과 저장이 따라 바뀐다.
 */
class RegisterFieldSetTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed>|null 가입 훅이 받은 registration_data */
    private ?array $registrationData = null;

    /**
     * 역할과 코어 회원 항목을 시딩하고 가입 훅 페이로드를 기록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        HookManager::addAction('core.auth.after_register', function ($user, array $context) {
            $this->registrationData = $context['registration_data'] ?? null;
        });
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.auth.after_register');
        parent::tearDown();
    }

    /**
     * 가입 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 입력 (값이 null 인 키는 뺀다)
     * @return TestResponse 응답
     */
    private function register(array $overrides = []): TestResponse
    {
        $body = array_merge([
            'name' => '홍길동',
            'email' => 'issue490+'.uniqid().'@example.com',
            'password' => 'Str0ng!Passw0rd#490',
            'password_confirmation' => 'Str0ng!Passw0rd#490',
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $overrides);

        return $this->postJson('/api/auth/register', array_filter($body, fn ($value) => $value !== null));
    }

    /**
     * 코어 항목의 속성을 바꿉니다 (운영자 수정과 같은 경로).
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function tune(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', 'core.user')->where('key', $key)->firstOrFail()->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  string  $type  유형 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, string $type = 'text', array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => $type, 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 설정을 바꾸지 않은 사이트의 가입은 종전과 같다 — 이름 필수, 나머지 선택, 저장되는 칸도 같다.
     *
     * @scenario setting=defaults
     *
     * @effects signup_unchanged_with_default_settings
     *
     * @return void 반환값 없음
     */
    public function test_default_settings_keep_legacy_signup(): void
    {
        $this->register(['name' => null])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->register(['mobile' => '010-abcd'])->assertStatus(422)->assertJsonValidationErrors(['mobile']);
        $this->register(['language' => 'xx'])->assertStatus(422)->assertJsonValidationErrors(['language']);

        $email = 'issue490+default@example.com';
        $this->register(['email' => $email, 'nickname' => '길동이', 'mobile' => '010-1234-5678', 'phone' => '02-123-4567', 'language' => 'en'])
            ->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame(['홍길동', '길동이', '010-1234-5678', '02-123-4567', 'en'], [$user->name, $user->nickname, $user->mobile, $user->phone, $user->language]);
        $this->assertNull($user->required_fields_deferred_at, '화면에서 한 가입에 필수 항목 나중 입력 표시가 붙었다');

        // 언어를 보내지 않으면 종전 기본값(ko)이 들어간다.
        $this->register(['email' => 'issue490+nolang@example.com'])->assertStatus(201);
        $this->assertSame('ko', User::where('email', 'issue490+nolang@example.com')->value('language'));
    }

    /**
     * 가입에서 받지 않는 칸(국가, 홈페이지, 자기소개 등)은 보내도 저장되지 않는다(종전과 같다).
     *
     * @scenario setting=defaults
     *
     * @effects signup_ignores_fields_not_shown_on_signup
     *
     * @return void 반환값 없음
     */
    public function test_fields_not_shown_on_signup_are_ignored(): void
    {
        $email = 'issue490+ignored@example.com';
        $this->register(['email' => $email, 'homepage' => 'https://example.com', 'bio' => '소개', 'zipcode' => '04524'])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->homepage);
        $this->assertNull($user->bio);
        $this->assertNull($user->zipcode);
    }

    /**
     * 이름을 선택으로 돌리면 이름 없이 가입되고, 이름을 끄면 보낸 이름도 저장되지 않는다.
     *
     * @scenario setting=name_optional_or_disabled
     *
     * @effects signup_name_can_be_optional_or_disabled
     *
     * @return void 반환값 없음
     */
    public function test_name_can_be_optional_or_disabled(): void
    {
        $this->tune('name', ['required' => false]);
        $this->register(['name' => null, 'email' => 'issue490+noname@example.com'])->assertStatus(201);
        $this->assertNull(User::where('email', 'issue490+noname@example.com')->firstOrFail()->name);

        $this->tune('name', ['enabled' => false]);
        $response = $this->register(['name' => '보낸 이름', 'email' => 'issue490+nameoff@example.com'])->assertStatus(201);
        $this->assertNull(User::where('email', 'issue490+nameoff@example.com')->firstOrFail()->name, '꺼진 이름 항목의 값이 저장됐다');
        $this->assertNull($response->json('data.user.name'));
    }

    /**
     * 선택이던 휴대폰을 필수로 바꾸면 없는 가입은 그 칸 오류로 거부된다. 가입 표시를 끄면 필수라도 가입에서 받지 않는다.
     *
     * @scenario setting=core_field_required
     *
     * @effects signup_follows_required_and_signup_visibility
     *
     * @return void 반환값 없음
     */
    public function test_required_core_field_and_signup_visibility(): void
    {
        $this->tune('mobile', ['required' => true]);
        $this->register()->assertStatus(422)->assertJsonValidationErrors(['mobile']);
        $this->register(['mobile' => '010-1111-2222'])->assertStatus(201);

        $this->tune('mobile', ['show_on_signup' => false]);
        $this->register(['email' => 'issue490+hidden@example.com', 'mobile' => '010-3333-4444'])->assertStatus(201);
        $this->assertNull(User::where('email', 'issue490+hidden@example.com')->value('mobile'), '가입 미표시 항목의 값이 저장됐다');
    }

    /**
     * 운영자가 필수로 바꾼 기본 항목의 오류 문구에는 항목 이름이 들어간다 (요청 키 `mobile` 이 그대로 나오지 않는다).
     *
     * @scenario setting=core_field_required
     *
     * @effects signup_core_field_error_uses_field_label
     *
     * @return void 반환값 없음
     */
    public function test_required_core_field_error_message_uses_field_label(): void
    {
        $this->tune('mobile', ['required' => true]);

        $message = $this->withHeader('Accept-Language', 'ko')->register()->assertStatus(422)->json('errors.mobile.0');

        $this->assertStringContainsString('휴대폰', (string) $message, '오류 문구에 항목 이름이 없다');
        $this->assertStringNotContainsString('mobile', (string) $message, '오류 문구에 요청 키가 그대로 나왔다');
    }

    /**
     * 운영자 추가 항목 — 필수면 칸 키(`custom_fields.{키}`)로 오류가 나고, 넣으면 값 테이블에 저장된다.
     *
     * @scenario setting=custom_field
     *
     * @effects signup_custom_field_validated_and_stored
     *
     * @return void 반환값 없음
     */
    public function test_custom_fields_are_validated_and_stored(): void
    {
        $text = $this->custom('t490_text', 'text', ['required' => true, 'name' => ['ko' => '직함']]);
        $job = $this->custom('t490_job', 'choice', ['options' => [['value' => 'student', 'label' => ['ko' => '학생']]]]);
        $period = $this->custom('t490_period', 'date_range');
        $home = $this->custom('t490_home', 'address');
        $grade = $this->custom('t490_grade', 'text', ['edit_scope' => 'admin_only']);
        $file = $this->custom('t490_file', 'file', ['required' => true]);

        // 회원 항목을 그리는 화면은 정의 버전을 싣는다 — 싣지 않은 요청의 옛 화면 호환은 LegacyMemberFieldScreenTest 가 잰다.
        $version = app(FieldRegistry::class)->version('core.user');

        $missing = $this->register(['fields_version' => $version])->assertStatus(422)->assertJsonValidationErrors(['custom_fields.t490_text']);
        $this->assertStringContainsString('직함', $missing->json('errors')['custom_fields.t490_text'][0], '오류 문구에 항목 이름이 아니라 키가 실렸다');
        $this->assertArrayNotHasKey('custom_fields.t490_file', $missing->json('errors'), '파일 항목이 가입에서 필수로 걸렸다');

        $this->register(['fields_version' => $version, 'custom_fields' => ['t490_text' => '팀장', 't490_job' => 'hacker']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.t490_job']);

        $email = 'issue490+custom@example.com';
        $this->register(['email' => $email, 'fields_version' => $version, 'custom_fields' => [
            't490_text' => '팀장',
            't490_job' => 'student',
            't490_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
            't490_home' => ['zipcode' => '04524', 'address' => '서울 중구 세종대로 110', 'address_detail' => '3층'],
            't490_grade' => 'A',
        ]])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $rows = FieldValue::where('owner_type', 'user')->where('owner_id', $user->id)->get()->keyBy('field_definition_id');
        $this->assertCount(4, $rows, '입력한 항목 수와 값 행 수가 다르다(관리자 전용 항목은 저장되지 않아야 한다)');
        $this->assertSame('팀장', $rows[$text->id]->value_text);
        $this->assertSame('student', $rows[$job->id]->value_index);
        $this->assertSame('2026-01-31', $rows[$period->id]->value_date_end->format('Y-m-d'));
        $this->assertSame('04524', $rows[$home->id]->value_json['zipcode']);
        $this->assertFalse($rows->has($grade->id), '관리자만 열람 항목이 가입 요청으로 저장됐다');
        $this->assertFalse($rows->has($file->id));
    }

    /**
     * 낡은 화면의 가입 제출은 검증 오류보다 먼저 409 로 알리고, 지금 버전을 실은 제출은 통과하며 버전은 훅 페이로드에 실리지 않는다.
     *
     * @scenario setting=fields_version
     *
     * @effects signup_stale_form_rejected_before_validation
     *
     * @return void 반환값 없음
     */
    public function test_stale_fields_version_is_rejected_before_validation(): void
    {
        $stale = app(FieldRegistry::class)->version('core.user');
        $this->custom('t490_text', 'text', ['required' => true]);
        $current = app(FieldRegistry::class)->version('core.user');
        $this->assertNotSame($stale, $current);

        // 낡은 화면에는 새 필수 칸이 없다 — 422 가 아니라 409 로 알려야 화면이 다시 불러온다.
        $response = $this->register(['fields_version' => $stale]);
        $response->assertStatus(409)->assertJsonPath('error_code', 'fields_changed')->assertJsonPath('data.fields_version', $current);
        $this->assertNull($response->json('errors'));

        $this->register(['fields_version' => $current, 'custom_fields' => ['t490_text' => '값']])->assertStatus(201);
        $this->assertIsArray($this->registrationData);
        $this->assertArrayNotHasKey('fields_version', $this->registrationData, '정의 버전이 가입 훅 페이로드에 실렸다');
        $this->assertSame('값', $this->registrationData['custom_fields']['t490_text'] ?? null, '추가 항목 값이 가입 훅 페이로드에 없다');

        // 버전을 싣지 않은 요청(회원 항목을 모르는 옛 화면)은 대조 없이 옛 화면 호환으로 간다 — 운영자 추가 필수 항목을 건너뛰고
        // 만들어지며 추가 정보 입력 대기 표시는 남기지 않는다(상세는 LegacyMemberFieldScreenTest).
        $this->register(['email' => 'issue490+noversion@example.com'])->assertStatus(201);
        $this->assertNull(User::where('email', 'issue490+noversion@example.com')->firstOrFail()->required_fields_deferred_at);
    }

    /**
     * 확장 선언 항목의 옛 요청 키는 정식 키가 비었을 때 정식 키로 옮겨지고, 훅 페이로드에는 둘 다 남는다.
     *
     * @scenario setting=legacy_keys
     *
     * @effects signup_legacy_request_key_is_accepted
     *
     * @return void 반환값 없음
     */
    public function test_legacy_request_keys_are_copied_to_canonical_keys(): void
    {
        // 값 테이블에 저장하는 코어 선언 항목 하나를 옛 키와 함께 둔다.
        FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'favorite_color', 'type' => 'text', 'source_type' => 'core',
            'source_identifier' => 'core', 'storage' => 'value', 'name' => ['ko' => '좋아하는 색'],
            'legacy_keys' => ['fav_color'], 'required' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $email = 'issue490+legacy@example.com';
        $this->register(['email' => $email, 'fav_color' => '파랑'])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('파랑', FieldValue::where('owner_id', $user->id)->value('value_text'));
        $this->assertSame('파랑', $this->registrationData['favorite_color'] ?? null);

        // 정식 키가 있으면 옛 키로 덮지 않는다.
        $this->register(['email' => 'issue490+both@example.com', 'favorite_color' => '빨강', 'fav_color' => '파랑'])->assertStatus(201);
        $both = User::where('email', 'issue490+both@example.com')->firstOrFail();
        $this->assertSame('빨강', FieldValue::where('owner_id', $both->id)->value('value_text'));
    }

    /**
     * 기존 가입 규칙 필터는 그대로 뒤에 돌아 확장이 더한 규칙이 적용된다.
     *
     * @scenario setting=extension_filter
     *
     * @effects signup_validation_filter_still_runs
     *
     * @return void 반환값 없음
     */
    public function test_register_validation_filter_still_applies(): void
    {
        HookManager::addFilter('core.auth.register_validation_rules', function (array $rules) {
            $this->assertArrayHasKey('name', $rules, '필터가 받은 규칙에 회원 항목 규칙이 없다');
            $rules['referral_code'] = ['required', 'string', 'size:6'];

            return $rules;
        });

        try {
            $this->register()->assertStatus(422)->assertJsonValidationErrors(['referral_code']);
            $this->register(['referral_code' => 'ABC123'])->assertStatus(201);
        } finally {
            HookManager::clearFilter('core.auth.register_validation_rules');
        }
    }
}
