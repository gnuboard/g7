<?php

namespace Tests\Unit\Fields;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Fields\FieldContext;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Rules\NicknameChangeInterval;
use App\Rules\NicknameForbiddenWords;
use App\Rules\UniqueNickname;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 항목 규칙 팩토리 — 표면별 대상 항목, 필수·선택, 꺼진 항목 제외, 관리자 수정의 빈 필수 완화, 중복 금지.
 */
class FieldRuleFactoryTest extends TestCase
{
    use RefreshDatabase;

    private FieldRuleFactory $factory;

    /**
     * 코어 회원 항목을 시딩하고 팩토리를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->factory = app(FieldRuleFactory::class);
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
            'field_set' => 'core.user',
            'key' => $key,
            'type' => $type,
            'source_type' => 'custom',
            'source_identifier' => 'custom',
            'storage' => 'value',
            'name' => ['ko' => $key],
            'enabled' => true,
            'sort_order' => 500,
        ], $attributes));
        $this->forgetFieldMemo();

        return $definition;
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
        $this->forgetFieldMemo();
    }

    /**
     * 요청 안에 담긴 항목 목록 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetFieldMemo(): void
    {
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 기본 설정의 가입 규칙은 종전 가입 요청의 항목 규칙과 같다(이름 필수, 닉네임·휴대폰·전화·언어 선택).
     *
     * @scenario surface=signup
     *
     * @effects rule_factory_defaults_match_legacy_rules
     *
     * @return void 반환값 없음
     */
    public function test_signup_rules_match_legacy_defaults(): void
    {
        $rules = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SIGNUP));

        $this->assertSame(['name', 'nickname', 'mobile', 'phone', 'language'], array_keys($rules), '가입 화면의 항목 집합이나 순서가 종전과 다르다');
        $this->assertSame(['required', 'string', 'max:255'], $rules['name']);
        // 닉네임은 종전 규칙 뒤에 닉네임 정책 규칙(금지어, 중복, 변경 주기)이 붙는다 — 정책이 꺼진 기본 설정에서는 아무것도 막지 않는다.
        $this->assertSame(['nullable', 'string', 'max:50'], array_values(array_filter($rules['nickname'], 'is_string')));
        $this->assertSame(
            [NicknameForbiddenWords::class, UniqueNickname::class, NicknameChangeInterval::class],
            array_map(fn ($rule) => $rule::class, array_values(array_filter($rules['nickname'], 'is_object'))),
        );
        $this->assertSame(['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'], $rules['mobile']);
        $this->assertSame('nullable', $rules['language'][0]);

        $this->assertTrue(Validator::make(['name' => '홍길동', 'language' => 'ko'], $rules)->passes());
        $this->assertTrue(Validator::make(['language' => 'ko'], $rules)->fails(), '이름 없는 가입이 통과했다');
        $this->assertTrue(Validator::make(['name' => '홍길동', 'language' => 'xx'], $rules)->fails(), '지원하지 않는 언어가 통과했다');
    }

    /**
     * 관리자 추가·수정과 내 정보 수정은 가입 미표시 항목(국가·시간대·홈페이지·주소·서명·자기소개)까지 받는다.
     *
     * @scenario surface=admin_create
     *
     * @effects rule_factory_defaults_match_legacy_rules
     *
     * @return void 반환값 없음
     */
    public function test_admin_and_self_surfaces_include_non_signup_fields(): void
    {
        $expected = ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'zipcode', 'address', 'address_detail', 'signature', 'bio'];

        foreach ([FieldContext::SURFACE_ADMIN_CREATE, FieldContext::SURFACE_SELF_UPDATE] as $surface) {
            $rules = $this->factory->rules('core.user', FieldContext::for($surface));
            $this->assertSame($expected, array_keys($rules), "{$surface} 의 항목 집합이 종전과 다르다");
            $this->assertSame(['nullable', 'string', 'max:10'], $rules['zipcode']);
            $this->assertSame(['nullable', 'string', 'url', 'max:255'], $rules['homepage']);
            $this->assertSame(['nullable', 'string', 'max:5000'], $rules['bio']);
            $this->assertTrue(Validator::make(['name' => '홍', 'country' => 'KR', 'timezone' => 'Asia/Seoul'], $rules)->passes());
            $this->assertTrue(Validator::make(['name' => '홍', 'country' => 'KOR'], $rules)->fails(), '세 글자 국가 코드가 통과했다');
        }
    }

    /**
     * 꺼진 항목은 규칙에서 빠진다 — 요청이 그 키를 보내도 검증된 데이터에 들어가지 않는다.
     *
     * @scenario surface=signup
     *
     * @effects rule_factory_disabled_field_is_silently_ignored
     *
     * @return void 반환값 없음
     */
    public function test_disabled_field_is_excluded_and_ignored(): void
    {
        $this->tune('name', ['enabled' => false]);

        $rules = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SIGNUP));
        $this->assertArrayNotHasKey('name', $rules);

        $validated = Validator::make(['name' => '보낸 이름', 'nickname' => '길동이'], $rules)->validate();
        $this->assertSame(['nickname' => '길동이'], $validated, '꺼진 항목의 값이 검증된 데이터에 들어갔다');
    }

    /**
     * 가입 화면 표시를 끈 항목과 본인 수정 범위가 아닌 항목은 가입에서 받지 않는다.
     *
     * @scenario surface=signup
     *
     * @effects rule_factory_scope_and_signup_visibility
     *
     * @return void 반환값 없음
     */
    public function test_signup_visibility_and_edit_scope(): void
    {
        $this->tune('phone', ['show_on_signup' => false]);
        $this->custom('grade', 'text', ['edit_scope' => 'admin_only', 'required' => true]);
        $this->custom('memo', 'text', ['edit_scope' => 'admin_edit']);
        $this->custom('biz_license', 'file', ['required' => true]);

        $signup = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SIGNUP));
        $this->assertArrayNotHasKey('phone', $signup, '가입 미표시 항목이 가입 규칙에 들어갔다');
        $this->assertArrayNotHasKey('custom_fields.grade', $signup, '관리자만 열람 항목이 가입 규칙에 들어갔다');
        $this->assertArrayNotHasKey('custom_fields.memo', $signup);
        $this->assertArrayNotHasKey('custom_fields.biz_license', $signup, '파일 항목이 가입 규칙에 들어갔다');

        $self = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SELF_UPDATE));
        $this->assertArrayHasKey('phone', $self);
        $this->assertArrayNotHasKey('custom_fields.grade', $self, '관리자만 열람 항목을 본인이 고칠 수 있다');
        $this->assertArrayNotHasKey('custom_fields.memo', $self, '관리자만 수정 항목을 본인이 고칠 수 있다');

        $admin = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_ADMIN_CREATE));
        $this->assertSame('required', $admin['custom_fields.grade'][0], '관리자 추가에서 관리자 전용 필수 항목이 필수가 아니다');
        $this->assertArrayHasKey('custom_fields.memo', $admin);
    }

    /**
     * 필수 항목은 유형에 맞는 존재 규칙을 받는다(예/아니오는 accepted, 주소·기간은 하위 경로).
     *
     * @scenario surface=signup
     *
     * @effects rule_factory_required_presence_by_type
     *
     * @return void 반환값 없음
     */
    public function test_required_presence_rules_by_type(): void
    {
        $this->custom('agree_extra', 'boolean', ['required' => true]);
        $this->custom('home', 'address', ['required' => true]);
        $this->custom('stay', 'date_range', ['required' => true]);
        $this->custom('job', 'choice', ['required' => true, 'options' => [['value' => 'a', 'label' => ['ko' => '가']]]]);

        $rules = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SIGNUP));

        $this->assertSame('accepted', $rules['custom_fields.agree_extra'][0]);
        $this->assertSame('required', $rules['custom_fields.home'][0]);
        $this->assertSame('required', $rules['custom_fields.home.zipcode'][0]);
        $this->assertSame('required', $rules['custom_fields.home.address'][0]);
        $this->assertSame('nullable', $rules['custom_fields.home.address_detail'][0], '상세 주소까지 필수가 됐다');
        $this->assertSame('required', $rules['custom_fields.stay.start'][0]);
        $this->assertSame('required', $rules['custom_fields.job'][0]);

        $base = ['name' => '홍길동'];
        $full = $base + ['custom_fields' => [
            'agree_extra' => true,
            'home' => ['zipcode' => '04524', 'address' => '서울'],
            'stay' => ['start' => '2026-01-01', 'end' => '2026-01-02'],
            'job' => 'a',
        ]];
        $this->assertTrue(Validator::make($full, $rules)->passes());

        $errors = Validator::make($base, $rules)->errors()->keys();
        foreach (['custom_fields.agree_extra', 'custom_fields.home.zipcode', 'custom_fields.stay.start', 'custom_fields.job'] as $key) {
            $this->assertContains($key, $errors, "필수 항목 {$key} 누락이 오류로 잡히지 않았다");
        }
    }

    /**
     * 관리자 수정에서 대상의 현재 값이 빈 필수 항목은 선택으로 본다 — 채워져 있던 필수 항목은 비울 수 없다.
     *
     * @scenario surface=admin_update
     *
     * @effects rule_factory_admin_update_relaxes_empty_required
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_relaxes_required_when_current_value_is_empty(): void
    {
        $this->tune('mobile', ['required' => true]);
        $job = $this->custom('job', 'text', ['required' => true]);

        $empty = User::factory()->create(['mobile' => null]);
        $filled = User::factory()->create(['mobile' => '010-1111-2222']);
        app(FieldValueService::class)->write($filled, 'core.user', ['job' => '개발자'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $filled));
        $this->assertDatabaseHas('field_values', ['field_definition_id' => $job->id, 'owner_id' => $filled->id]);

        $forEmpty = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $empty));
        $this->assertSame('nullable', $forEmpty['mobile'][0], '값이 비어 있던 회원의 필수 항목이 선택으로 풀리지 않았다');
        $this->assertSame('nullable', $forEmpty['custom_fields.job'][0]);

        $forFilled = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $filled));
        $this->assertSame('required', $forFilled['mobile'][0], '값이 있던 회원의 필수 항목이 선택으로 풀렸다');
        $this->assertSame('required', $forFilled['custom_fields.job'][0]);
    }

    /**
     * 추가 정보 입력 표면은 지정한 키의 항목만 받는다.
     *
     * @scenario surface=required_info
     *
     * @effects rule_factory_required_info_limits_to_pending_keys
     *
     * @return void 반환값 없음
     */
    public function test_required_info_limits_to_given_keys(): void
    {
        $this->tune('nickname', ['required' => true]);
        $this->custom('job', 'text', ['required' => true]);

        $context = new FieldContext(FieldContext::SURFACE_REQUIRED_INFO, onlyKeys: ['nickname', 'job']);
        $rules = $this->factory->rules('core.user', $context);

        $this->assertSame(['nickname', 'custom_fields.job'], array_keys($rules));
        $this->assertSame('required', $rules['nickname'][0]);
    }

    /**
     * 중복 금지 항목은 다른 회원이 쓰는 값을 거부하고 본인의 현재 값은 받는다.
     *
     * @scenario surface=self_update
     *
     * @effects rule_factory_unique_value_conflict
     *
     * @return void 반환값 없음
     */
    public function test_unique_field_rejects_value_used_by_another_member(): void
    {
        $this->custom('handle', 'text', ['constraints' => ['unique' => true]]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $values = app(FieldValueService::class);
        $values->write($owner, 'core.user', ['handle' => 'Gildong'], FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $owner));

        $forOther = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $other));
        $conflict = Validator::make(['name' => '다른 회원', 'custom_fields' => ['handle' => ' gildong ']], $forOther);
        $this->assertSame(['custom_fields.handle'], $conflict->errors()->keys(), '대소문자·공백만 다른 값이 중복으로 잡히지 않았다');
        $this->assertTrue(Validator::make(['name' => '다른 회원', 'custom_fields' => ['handle' => 'gildong2']], $forOther)->passes());

        $forOwner = $this->factory->rules('core.user', FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $owner));
        $this->assertTrue(Validator::make(['name' => '본인', 'custom_fields' => ['handle' => 'Gildong']], $forOwner)->passes(), '본인의 현재 값이 중복으로 거부됐다');
    }

    /**
     * 오류 문구용 속성 이름과 운영자가 적은 형식 오류 문구를 돌려준다.
     *
     * @scenario surface=signup
     *
     * @effects rule_factory_attribute_names_and_messages
     *
     * @return void 반환값 없음
     */
    public function test_attributes_and_custom_regex_message(): void
    {
        $this->custom('code', 'text', [
            'name' => ['ko' => '사번', 'en' => 'Employee no.'],
            'constraints' => ['regex' => '/^\d{4}$/', 'regex_message' => ['ko' => '숫자 4자리로 입력해 주세요.']],
        ]);
        $context = FieldContext::for(FieldContext::SURFACE_SIGNUP);

        app()->setLocale('ko');
        $this->assertSame('사번', $this->factory->attributes('core.user', $context)['custom_fields.code']);
        $this->assertSame('이름', $this->factory->attributes('core.user', $context)['name']);
        $this->assertSame(['custom_fields.code.regex' => '숫자 4자리로 입력해 주세요.'], $this->factory->messages('core.user', $context));

        app()->setLocale('en');
        $this->assertSame('Employee no.', $this->factory->attributes('core.user', $context)['custom_fields.code']);
    }
}
