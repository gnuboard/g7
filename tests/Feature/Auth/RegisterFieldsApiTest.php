<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Models\FieldDefinition;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 가입 화면 항목 조회(`GET /api/auth/register/fields`)가 운영자 설정대로 항목 계약을 주는지.
 *
 * 화면은 이 응답만 보고 입력칸을 그린다 — 순서, 필수 표시, 폼 경로(`name`), 선택지 글자, 정의 버전이 계약이다.
 */
class RegisterFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 역할과 코어 회원 항목을 시딩한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);
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
            'enabled' => true, 'show_on_signup' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 응답의 항목을 키로 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $fields  항목 계약 목록
     * @param  string  $key  항목 키
     * @return array<string, mixed>|null 항목 계약
     */
    private function field(array $fields, string $key): ?array
    {
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * 설정을 바꾸지 않은 사이트는 종전 가입 화면의 다섯 칸을 같은 순서로 주고 이름만 필수다. 비로그인으로 열린다.
     *
     * @scenario setting=defaults
     *
     * @effects schema_matches_legacy_signup_form
     *
     * @return void 반환값 없음
     */
    public function test_default_schema_matches_legacy_signup_form(): void
    {
        $response = $this->getJson('/api/auth/register/fields')->assertOk();

        $fields = $response->json('data.fields');
        $this->assertSame(['name', 'nickname', 'mobile', 'phone', 'language'], array_column($fields, 'key'));
        $this->assertSame(['name', 'nickname', 'mobile', 'phone', 'language'], array_column($fields, 'name'));
        $this->assertSame([true, false, false, false, false], array_column($fields, 'required'));
        $this->assertSame(app(FieldRegistry::class)->version('core.user'), $response->json('data.fields_version'));

        $language = $this->field($fields, 'language');
        $this->assertSame('choice', $language['type']);
        $this->assertSame(app()->getLocale(), $language['default']);
        $this->assertContains('ko', array_column($language['options'], 'value'));
        $this->assertArrayNotHasKey('options_source', $language['constraints']);
    }

    /**
     * 사용을 끈 칸과 가입 표시를 끈 칸은 빠지고, 필수로 바꾼 칸은 필수로 나온다.
     *
     * @scenario setting=core_field_tuned
     *
     * @effects schema_follows_enabled_required_and_signup_visibility
     *
     * @return void 반환값 없음
     */
    public function test_schema_follows_operator_settings(): void
    {
        $this->tune('nickname', ['enabled' => false]);
        $this->tune('phone', ['show_on_signup' => false]);
        $this->tune('mobile', ['required' => true]);
        $this->tune('homepage', ['show_on_signup' => true]);

        $fields = $this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields');

        $this->assertSame(['name', 'mobile', 'language', 'homepage'], array_column($fields, 'key'));
        $this->assertTrue($this->field($fields, 'mobile')['required']);
    }

    /**
     * 운영자 추가 항목은 `custom_fields.{키}` 폼 경로로 나오고 이름, 안내, 선택지가 요청 로케일의 글자다.
     *
     * @scenario setting=custom_field
     *
     * @effects schema_custom_field_path_and_localized_text
     *
     * @return void 반환값 없음
     */
    public function test_custom_field_contract(): void
    {
        $this->custom('t490_job', 'choice', [
            'name' => ['ko' => '직업', 'en' => 'Job'],
            'description' => ['ko' => '현재 직업', 'en' => 'Current job'],
            'required' => true,
            'constraints' => ['display' => 'radio', 'allow_other' => true],
            'options' => [
                ['value' => 'student', 'label' => ['ko' => '학생', 'en' => 'Student']],
                ['value' => 'worker', 'label' => ['ko' => '직장인', 'en' => 'Worker']],
            ],
        ]);

        $field = $this->field($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'en'])->assertOk()->json('data.fields'), 't490_job');

        $this->assertSame('custom_fields.t490_job', $field['name']);
        $this->assertSame('custom', $field['owner']);
        $this->assertSame('Job', $field['label']);
        $this->assertSame('Current job', $field['help']);
        $this->assertTrue($field['required']);
        $this->assertSame([['value' => 'student', 'label' => 'Student'], ['value' => 'worker', 'label' => 'Worker']], $field['options']);
        $this->assertSame('radio', $field['constraints']['display']);
        $this->assertTrue($field['constraints']['allow_other']);

        // 숫자 항목의 단위(다국어)도 요청 로케일의 글자로 나간다 — 객체로 나가면 화면에 「[object Object]」가 보였다
        $this->custom('t490_weight', 'number', ['constraints' => ['unit' => ['ko' => '킬로그램', 'en' => 'kg'], 'min' => 0]]);
        $number = $this->field($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'en'])->assertOk()->json('data.fields'), 't490_weight');
        $this->assertSame('kg', $number['constraints']['unit']);
        $this->assertSame(0, $number['constraints']['min']);
        $koNumber = $this->field($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->assertOk()->json('data.fields'), 't490_weight');
        $this->assertSame('킬로그램', $koNumber['constraints']['unit']);
    }

    /**
     * 파일 항목은 가입에서 받지 않으므로 안내 줄용(`deferred`)으로만 나오고 필수 표시가 없다. 관리자 전용 항목은 나오지 않는다.
     *
     * @scenario setting=file_and_admin_scope
     *
     * @effects schema_file_field_is_deferred_and_admin_scope_hidden
     *
     * @return void 반환값 없음
     */
    public function test_file_field_is_deferred_and_admin_fields_are_hidden(): void
    {
        $this->custom('t490_doc', 'file', ['required' => true]);
        $this->custom('t490_grade', 'text', ['edit_scope' => 'admin_only']);
        $this->custom('t490_memo', 'text', ['edit_scope' => 'admin_edit']);

        $fields = $this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields');

        $doc = $this->field($fields, 't490_doc');
        $this->assertTrue($doc['deferred']);
        $this->assertFalse($doc['required']);
        $this->assertSame('file', $doc['renderer']);
        $this->assertNull($this->field($fields, 't490_grade'));
        $this->assertNull($this->field($fields, 't490_memo'));
    }

    /**
     * 주소 항목은 부분별 폼 경로를 준다 — 코어 주소는 최상위 세 키, 운영자 주소는 자기 묶음 아래.
     *
     * @scenario setting=address_parts
     *
     * @effects schema_address_parts_paths
     *
     * @return void 반환값 없음
     */
    public function test_address_field_gives_part_paths(): void
    {
        $this->tune('address', ['show_on_signup' => true]);
        $this->custom('t490_addr', 'address');

        $fields = $this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields');

        $this->assertSame(
            ['zipcode' => 'zipcode', 'address' => 'address', 'address_detail' => 'address_detail'],
            $this->field($fields, 'address')['parts'],
        );
        $this->assertSame(
            ['zipcode' => 'custom_fields.t490_addr.zipcode', 'address' => 'custom_fields.t490_addr.address', 'address_detail' => 'custom_fields.t490_addr.address_detail'],
            $this->field($fields, 't490_addr')['parts'],
        );
    }

    /**
     * 화면이 받은 정의 버전으로 가입하면 통과하고, 그 사이 운영자가 항목을 바꾸면 409 로 알린다.
     *
     * @scenario setting=fields_version
     *
     * @effects schema_version_round_trips_to_signup
     *
     * @return void 반환값 없음
     */
    public function test_version_from_schema_is_accepted_by_signup_until_fields_change(): void
    {
        $version = $this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields_version');
        $body = [
            'name' => '홍길동', 'password' => 'Str0ng!Passw0rd#490', 'password_confirmation' => 'Str0ng!Passw0rd#490',
            'agree_terms' => true, 'agree_privacy' => true, 'fields_version' => $version,
        ];

        $this->postJson('/api/auth/register', [...$body, 'email' => 'issue490+'.uniqid().'@example.com'])->assertCreated();

        $this->tune('mobile', ['required' => true]);

        $this->postJson('/api/auth/register', [...$body, 'email' => 'issue490+'.uniqid().'@example.com'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'fields_changed');
    }

    /**
     * 조회 인자(`pending_token`, `pending_source`)는 필터 `core.field.filter_definitions` 의 문맥으로 넘어가고,
     * 구독자가 항목 사본에 채운 초기값과 잠금이 항목 계약의 `default`, `readonly`, `locked_reason` 으로 나간다.
     *
     * 소셜, SSO 가입 창이 공급자 값을 받는 통로다. 코어는 인자를 해석하지 않고, 저장된 항목 정의와 정의 버전은 바뀌지 않는다.
     *
     * @scenario setting=pending_prefill
     *
     * @effects schema_carries_filter_supplied_default_and_lock
     *
     * @return void 반환값 없음
     */
    public function test_pending_arguments_reach_filter_and_supplied_default_and_lock_are_served(): void
    {
        $seen = [];
        HookManager::addFilter('core.field.filter_definitions', function ($definitions, $set, $scopeKey, $context) use (&$seen) {
            $extra = $context['extra'] ?? [];
            if (($extra['pending_source'] ?? null) !== 'vendor-external') {
                return $definitions;
            }
            $seen[] = $extra;

            foreach ($definitions as $definition) {
                if ($definition->key === 'nickname') {
                    $definition->constraints = [...($definition->constraints ?? []), 'default' => '공급자닉'];
                    $definition->ui = [...($definition->ui ?? []), 'readonly' => true, 'locked_reason' => 'sso'];
                }
            }

            return $definitions;
        });

        try {
            $version = app(FieldRegistry::class)->version('core.user');

            $response = $this->getJson('/api/auth/register/fields?pending_source=vendor-external&pending_token=tok-490')->assertOk();
            $nickname = $this->field($response->json('data.fields'), 'nickname');
            $this->assertSame(['공급자닉', true, 'sso'], [$nickname['default'], $nickname['readonly'], $nickname['locked_reason']]);
            $this->assertContains(['pending_token' => 'tok-490', 'pending_source' => 'vendor-external'], $seen);
            $this->assertSame($version, $response->json('data.fields_version'));

            // 인자가 없는 조회에는 잠금과 초기값이 없다 — 구독자가 고친 것은 그 요청의 사본뿐이다.
            $plain = $this->field($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'), 'nickname');
            $this->assertSame([null, false, null], [$plain['default'], $plain['readonly'], $plain['locked_reason']]);
        } finally {
            HookManager::clearFilter('core.field.filter_definitions');
        }
    }
}
