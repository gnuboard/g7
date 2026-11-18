<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldTypeRegistry;
use App\Fields\FieldValueService;
use App\Fields\Types\TextType;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 관리자 — 항목 집합의 항목 CRUD·순서 API.
 *
 * 환경설정 > 회원 > 가입 항목 표가 쓰는 계약: 목록과 정의 버전, 즉시 저장(부분 수정), 운영자 항목 추가·삭제,
 * 순서 변경, 낡은 표의 요청 거부(409), 선택지 삭제 시 값 정리, 권한.
 */
class FieldDefinitionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/field-sets/core.user/fields';

    private string $adminToken;

    /**
     * 권한과 코어 회원 항목을 시딩하고 최고관리자 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->adminToken = $admin->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 가짜 확장이 건 필터를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.field_definition.filter_usages');
        parent::tearDown();
    }

    /**
     * 인증 헤더를 만듭니다.
     *
     * @param  string|null  $token  토큰 (null 이면 최고관리자)
     * @return array<string, string> 헤더
     */
    private function auth(?string $token = null): array
    {
        // 한 테스트 안에서 토큰을 바꿔 가며 요청한다 — 앞 요청이 풀어 둔 사용자가 가드에 남지 않게 비운다.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($token ?? $this->adminToken), 'Accept' => 'application/json'];
    }

    /**
     * 지정한 권한만 가진 관리자 토큰을 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자 목록
     * @return string 토큰
     */
    private function tokenWithPermissions(array $identifiers): string
    {
        $user = User::factory()->create(['is_super' => false]);
        $role = Role::create([
            'identifier' => 'test-field-role-'.uniqid(),
            'name' => ['ko' => '항목 테스트 역할', 'en' => 'Field test role'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => '', 'en' => ''],
                    'type' => PermissionType::Admin,
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'order' => 0,
                ],
            );
            $role->permissions()->attach($permission->id, ['granted_at' => now(), 'granted_by' => null]);
        }
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 코어 항목의 ID 를 반환합니다.
     *
     * @param  string  $key  항목 키
     * @return int 항목 ID
     */
    private function idOf(string $key): int
    {
        return (int) FieldDefinition::where('field_set', 'core.user')->where('key', $key)->value('id');
    }

    /**
     * 지금의 정의 버전을 목록 API 로 읽습니다.
     *
     * @return string 정의 버전
     */
    private function currentVersion(): string
    {
        return (string) $this->getJson(self::BASE, $this->auth())->json('data.fields_version');
    }

    /**
     * 운영자 항목을 API 로 추가합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 입력
     * @return TestResponse 응답
     */
    private function storeCustom(array $overrides = [])
    {
        return $this->postJson(self::BASE, array_merge([
            'key' => 't490_text',
            'type' => 'text',
            'name' => ['ko' => '직함', 'en' => 'Title'],
            'required' => true,
            'show_on_signup' => true,
        ], $overrides), $this->auth());
    }

    /**
     * 목록은 항목 11개를 순서대로, 정의 버전과 고를 수 있는 유형 15종과 함께 돌려준다.
     *
     * @scenario action=index
     *
     * @effects admin_field_list_contract
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_fields_version_and_types(): void
    {
        $response = $this->getJson(self::BASE, $this->auth())->assertOk();

        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'],
            array_column($response->json('data.fields'), 'key'),
        );
        $this->assertSame(15, count($response->json('data.types')));
        $this->assertSame(12, strlen($response->json('data.fields_version')));
        $this->assertTrue($response->json('data.abilities.can_update'));
        $this->assertTrue($response->json('data.set.allows_custom'));

        $name = $response->json('data.fields.0');
        $this->assertSame('이름', $name['label']);
        $this->assertSame('name', $name['attribute']);
        $this->assertTrue($name['required']);
        $this->assertFalse($name['is_custom']);
        $this->assertTrue($name['available']);
        $this->assertTrue($name['supports']['display_name']);
        $this->assertFalse($name['supports']['unique'], '컬럼 저장 항목이 값 테이블 중복 금지를 지원한다고 답했다');
        // 언어 항목의 선택지는 지원 로케일로 채워진다.
        $language = collect($response->json('data.fields'))->firstWhere('key', 'language');
        $this->assertSame(config('app.supported_locales'), array_column($language['options'], 'value'));
    }

    /**
     * 표의 스위치 한 칸 저장 — 휴대폰을 필수로 바꾸면 그 속성만 바뀌고 정의 버전이 달라진다.
     *
     * @scenario action=update
     *
     * @effects admin_field_partial_update_changes_version
     *
     * @return void 반환값 없음
     */
    public function test_update_single_toggle_changes_only_that_attribute(): void
    {
        $before = $this->currentVersion();

        $response = $this->putJson(self::BASE.'/'.$this->idOf('mobile'), [
            'required' => true,
            'require_existing_members' => true,
            'fields_version' => $before,
        ], $this->auth())->assertOk();

        $this->assertTrue($response->json('data.field.required'));
        $this->assertTrue($response->json('data.field.require_existing_members'));
        $this->assertTrue($response->json('data.field.enabled'), '보내지 않은 속성이 바뀌었다');
        $this->assertNotSame($before, $response->json('data.fields_version'));
        $this->assertEqualsCanonicalizing(['required', 'require_existing_members'], FieldDefinition::find($this->idOf('mobile'))->user_overrides);
    }

    /**
     * 낡은 표에서 온 요청은 저장하지 않고 409 와 fields_changed 로 거부한다.
     *
     * @scenario action=update
     *
     * @effects admin_field_stale_version_rejected
     *
     * @return void 반환값 없음
     */
    public function test_stale_version_is_rejected_with_409(): void
    {
        $stale = $this->currentVersion();
        $this->putJson(self::BASE.'/'.$this->idOf('phone'), ['required' => true, 'fields_version' => $stale], $this->auth())->assertOk();
        $custom = FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'old_one', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '옛 항목'],
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $update = $this->putJson(self::BASE.'/'.$this->idOf('mobile'), ['required' => true, 'fields_version' => $stale], $this->auth());
        $update->assertStatus(409)->assertJsonPath('error_code', 'fields_changed');
        $this->assertSame($this->currentVersion(), $update->json('data.fields_version'), '거부 응답이 새 버전을 싣지 않았다');
        $this->assertFalse((bool) FieldDefinition::find($this->idOf('mobile'))->required, '거부한 요청이 저장됐다');

        $this->storeCustom(['fields_version' => $stale])->assertStatus(409)->assertJsonPath('error_code', 'fields_changed');
        $this->assertDatabaseMissing('field_definitions', ['key' => 't490_text']);

        $order = FieldDefinition::where('field_set', 'core.user')->orderByDesc('sort_order')->pluck('id')->all();
        $this->putJson(self::BASE.'/order', ['order' => $order, 'fields_version' => $stale], $this->auth())
            ->assertStatus(409)->assertJsonPath('error_code', 'fields_changed');

        $this->deleteJson(self::BASE.'/'.$custom->id.'?fields_version='.$stale, [], $this->auth())
            ->assertStatus(409)->assertJsonPath('error_code', 'fields_changed');
        $this->assertDatabaseHas('field_definitions', ['id' => $custom->id]);
    }

    /**
     * 운영자 항목 추가 — 201, 값 테이블 저장, 맨 뒤 순서, 요청 속성 경로는 custom_fields 아래.
     *
     * @scenario action=store
     *
     * @effects admin_custom_field_created
     *
     * @return void 반환값 없음
     */
    public function test_store_creates_custom_field(): void
    {
        $response = $this->storeCustom(['constraints' => ['max_length' => 30, 'unique' => true], 'display_name_usable' => true])
            ->assertStatus(201);

        $field = $response->json('data.field');
        $this->assertSame('custom', $field['source_type']);
        $this->assertSame('value', $field['storage']);
        $this->assertSame('custom_fields.t490_text', $field['attribute']);
        $this->assertTrue($field['is_custom']);
        $this->assertTrue($field['required']);
        $this->assertTrue($field['display_name_usable']);
        // 보내지 않은 속성은 기본값(사용 켬, 본인 수정 범위)으로 응답에 실린다 — 추가 직후 표가 꺼진 항목으로 그리지 않게.
        $this->assertTrue($field['enabled'], '추가 응답이 사용 여부의 기본값을 싣지 않았다');
        $this->assertSame('self', $field['edit_scope'], '추가 응답이 범위의 기본값을 싣지 않았다');
        $this->assertFalse($field['require_existing_members']);
        $this->assertGreaterThan(110, $field['sort_order'], '새 항목이 맨 뒤에 놓이지 않았다');

        $keys = array_column($this->getJson(self::BASE, $this->auth())->json('data.fields'), 'key');
        $this->assertSame('t490_text', end($keys));
    }

    /**
     * 추가 요청 검증 — 키 형식·중복, 모르는 유형, 선택지 없는 선택 항목, 유형이 지원하지 않는 기능은 꺼서 저장.
     *
     * @scenario action=store
     *
     * @effects admin_custom_field_validation
     *
     * @return void 반환값 없음
     */
    public function test_store_validation(): void
    {
        $this->storeCustom(['key' => 'Bad Key'])->assertStatus(422)->assertJsonValidationErrors(['key']);
        $this->storeCustom(['key' => 'name'])->assertStatus(422)->assertJsonValidationErrors(['key']);
        $this->storeCustom(['type' => 'hologram'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->storeCustom(['name' => ['en' => 'English only']])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->storeCustom(['key' => 'job', 'type' => 'choice'])->assertStatus(422)->assertJsonValidationErrors(['options']);
        $this->storeCustom(['key' => 'job', 'type' => 'choice', 'options' => [
            ['value' => 'a', 'label' => ['ko' => '가']], ['value' => 'a', 'label' => ['ko' => '나']],
        ]])->assertStatus(422)->assertJsonValidationErrors(['options.0.value']);
        $this->storeCustom(['constraints' => ['regex' => 'no-delimiter']])->assertStatus(422)->assertJsonValidationErrors(['constraints.regex']);
        $this->storeCustom(['key' => 'age', 'type' => 'number', 'constraints' => ['min' => 10, 'max' => 5]])
            ->assertStatus(422)->assertJsonValidationErrors(['constraints.max']);

        // 여러 줄 글은 표시 이름·중복 금지를 지원하지 않는다 — 요청해도 꺼진 채 저장된다.
        $field = $this->storeCustom([
            'key' => 'intro', 'type' => 'textarea', 'display_name_usable' => true, 'constraints' => ['unique' => true],
        ])->assertStatus(201)->json('data.field');
        $this->assertFalse($field['display_name_usable']);
        $this->assertFalse((bool) ($field['constraints']['unique'] ?? false));

        // 「기타」만 받는 하나 선택은 선택지 없이 만들 수 있다.
        $this->storeCustom(['key' => 'referrer', 'type' => 'choice', 'constraints' => ['allow_other' => true]])->assertStatus(201);
    }

    /**
     * 선택지가 없는 선택 항목을 필수로 켜려 하면 422 이고 값은 그대로다.
     *
     * @scenario action=update
     *
     * @effects admin_field_update_validation
     *
     * @return void 반환값 없음
     */
    public function test_update_rejects_required_choice_without_options(): void
    {
        $choice = FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'empty_choice', 'type' => 'choice', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '빈 선택'], 'options' => [],
        ]);

        $this->putJson(self::BASE.'/'.$choice->id, ['required' => true], $this->auth())
            ->assertStatus(422)->assertJsonValidationErrors(['options']);
        $this->assertFalse((bool) $choice->fresh()->required);

        // 사용만 끄고 켜는 것은 선택지가 없어도 된다.
        $this->putJson(self::BASE.'/'.$choice->id, ['enabled' => false], $this->auth())->assertOk();
    }

    /**
     * 선언 항목은 운영자가 바꿀 수 있는 속성만 바뀌고, 범위를 관리자 전용으로 바꾸면 「기존 회원에게도 요구」가 꺼진다.
     *
     * @scenario action=update
     *
     * @effects admin_declared_field_limited_edit, admin_scope_change_turns_off_existing_member_requirement
     *
     * @return void 반환값 없음
     */
    public function test_update_declared_field_is_limited_and_scope_turns_off_requirement(): void
    {
        $id = $this->idOf('nickname');

        $field = $this->putJson(self::BASE.'/'.$id, [
            'name' => ['ko' => '별명', 'en' => 'Nickname'],
            'required' => true,
            'require_existing_members' => true,
            'constraints' => ['max_length' => 5],
            'display_name_usable' => true,
        ], $this->auth())->assertOk()->json('data.field');

        $this->assertSame('별명', $field['name']['ko']);
        $this->assertTrue($field['require_existing_members']);
        $this->assertSame(50, $field['constraints']['max_length'], '선언 항목의 입력 제한이 운영자 요청으로 바뀌었다');
        $this->assertFalse($field['display_name_usable'], '선언 항목의 표시 이름 사용 여부가 운영자 요청으로 바뀌었다');

        $scoped = $this->putJson(self::BASE.'/'.$id, ['edit_scope' => 'admin_edit'], $this->auth())->assertOk()->json('data.field');
        $this->assertSame('admin_edit', $scoped['edit_scope']);
        $this->assertFalse($scoped['require_existing_members'], '관리자만 수정으로 바꿨는데 기존 회원 입력 요구가 켜져 있다');

        // 그 조합을 한 요청으로 보내도 요구는 꺼진 값으로 저장된다.
        $both = $this->putJson(self::BASE.'/'.$id, ['edit_scope' => 'admin_only', 'require_existing_members' => true], $this->auth())
            ->assertOk()->json('data.field');
        $this->assertFalse($both['require_existing_members']);
    }

    /**
     * 선언한 쪽이 잠근 속성은 요청해도 바뀌지 않는다.
     *
     * @scenario action=update
     *
     * @effects admin_locked_attribute_ignored
     *
     * @return void 반환값 없음
     */
    public function test_update_ignores_locked_attributes(): void
    {
        $id = $this->idOf('name');
        FieldDefinition::whereKey($id)->toBase()->update(['locks' => json_encode(['required' => 'fields.locks.default'])]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $field = $this->putJson(self::BASE.'/'.$id, ['required' => false, 'enabled' => false], $this->auth())->assertOk()->json('data.field');

        $this->assertTrue($field['required'], '잠긴 속성이 바뀌었다');
        $this->assertFalse($field['enabled'], '잠기지 않은 속성이 바뀌지 않았다');
        $this->assertSame('이 설정은 항목을 제공한 쪽에서 고정했습니다.', $field['locks']['required']);
    }

    /**
     * 상세는 값 수와 선택지별 사용 수를 싣고, 선택지를 지우면 그 값을 고른 회원의 값이 같은 요청에서 비워진다.
     *
     * @scenario action=show
     *
     * @effects admin_option_usage_counts, admin_option_removal_clears_values
     *
     * @return void 반환값 없음
     */
    public function test_show_reports_usage_and_removing_option_clears_values(): void
    {
        $options = [
            ['value' => 'student', 'label' => ['ko' => '학생']],
            ['value' => 'worker', 'label' => ['ko' => '직장인']],
        ];
        $job = $this->storeCustom(['key' => 'job', 'type' => 'choice', 'options' => $options])->json('data.field');
        $hobby = $this->storeCustom(['key' => 'hobby', 'type' => 'multi_choice', 'required' => false, 'options' => $options])->json('data.field');

        $values = app(FieldValueService::class);
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        foreach ([[$a, 'student', ['student', 'worker']], [$b, 'student', ['student']], [$c, 'worker', ['worker']]] as [$user, $jobValue, $hobbyValue]) {
            $values->write($user, 'core.user', ['job' => $jobValue, 'hobby' => $hobbyValue], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        }

        $detail = $this->getJson(self::BASE.'/'.$job['id'], $this->auth())->assertOk()->json('data.field');
        $this->assertSame(3, $detail['values_count']);
        $this->assertSame(['student' => 2, 'worker' => 1], array_column($detail['options'], 'usage_count', 'value'));
        $hobbyDetail = $this->getJson(self::BASE.'/'.$hobby['id'], $this->auth())->json('data.field');
        $this->assertSame(['student' => 2, 'worker' => 2], array_column($hobbyDetail['options'], 'usage_count', 'value'));

        // 「학생」 선택지를 지운다.
        $kept = [['value' => 'worker', 'label' => ['ko' => '직장인']]];
        $this->putJson(self::BASE.'/'.$job['id'], ['options' => $kept], $this->auth())->assertOk();
        $this->putJson(self::BASE.'/'.$hobby['id'], ['options' => $kept], $this->auth())->assertOk();

        $this->assertSame(1, FieldValue::where('field_definition_id', $job['id'])->count(), '지운 선택지를 고른 회원의 값이 남았다');
        $read = fn (User $user) => $values->read($user, 'core.user');
        $this->assertNull($read($a)['job']);
        $this->assertSame('worker', $read($c)['job']);
        $this->assertSame(['worker'], $read($a)['hobby'], '여러 개 선택에서 지운 값만 빠지지 않았다');
        $this->assertNull($read($b)['hobby'], '남은 선택이 없는 행이 지워지지 않았다');
        $this->assertSame(['worker'], $read($c)['hobby']);
    }

    /**
     * 순서 변경 — 보낸 순서대로 저장되고 목록이 그 순서로 돌아온다.
     *
     * @scenario action=reorder
     *
     * @effects admin_field_reorder_persists
     *
     * @return void 반환값 없음
     */
    public function test_reorder_persists_order(): void
    {
        $ids = FieldDefinition::where('field_set', 'core.user')->orderBy('sort_order')->pluck('id', 'key');
        $newOrder = [$ids['nickname'], $ids['name'], ...$ids->except(['nickname', 'name'])->values()->all()];

        $response = $this->putJson(self::BASE.'/order', ['order' => $newOrder, 'fields_version' => $this->currentVersion()], $this->auth())->assertOk();

        $this->assertSame($newOrder, array_column($response->json('data.fields'), 'id'));
        $this->assertSame($newOrder, array_column($this->getJson(self::BASE, $this->auth())->json('data.fields'), 'id'), '다시 조회한 순서가 저장한 순서와 다르다');

        // 다른 집합의 항목 ID 나 겹친 ID 는 거부한다.
        $this->putJson(self::BASE.'/order', ['order' => [$ids['name'], $ids['name']]], $this->auth())->assertStatus(422);
        $this->putJson(self::BASE.'/order', ['order' => [999999]], $this->auth())->assertStatus(422);
    }

    /**
     * 삭제 — 운영자 항목은 값과 함께 지워지고, 선언 항목은 지울 수 없으며, 다른 설정이 쓰는 항목은 409 로 막는다.
     *
     * @scenario action=destroy
     *
     * @effects admin_custom_field_deleted_with_values, admin_declared_field_cannot_be_deleted, admin_field_in_use_blocks_delete
     *
     * @return void 반환값 없음
     */
    public function test_destroy_rules(): void
    {
        $custom = $this->storeCustom()->json('data.field');
        $user = User::factory()->create();
        app(FieldValueService::class)->write($user, 'core.user', ['t490_text' => '팀장'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));

        $this->deleteJson(self::BASE.'/'.$this->idOf('name'), [], $this->auth())->assertStatus(403);
        $this->assertDatabaseHas('field_definitions', ['id' => $this->idOf('name')]);

        // 다른 설정(표시 이름 등)이 이 항목을 쓰고 있다고 알리는 구독자가 있으면 삭제를 막는다.
        HookManager::addFilter('core.field_definition.filter_usages', fn (array $usages, FieldDefinition $definition) => $definition->key === 't490_text'
            ? [...$usages, ['type' => 'display_name', 'label' => '사이트 기본 표시 이름']]
            : $usages);
        $blocked = $this->deleteJson(self::BASE.'/'.$custom['id'], [], $this->auth());
        $blocked->assertStatus(409)->assertJsonPath('error_code', 'field_in_use');
        $this->assertSame('사이트 기본 표시 이름', $blocked->json('data.usages.0.label'));
        $this->assertDatabaseHas('field_definitions', ['id' => $custom['id']]);
        HookManager::clearFilter('core.field_definition.filter_usages');

        $deleted = $this->deleteJson(self::BASE.'/'.$custom['id'], [], $this->auth())->assertOk();
        $this->assertSame(1, $deleted->json('data.removed_values'));
        $this->assertDatabaseMissing('field_definitions', ['id' => $custom['id']]);
        $this->assertSame(0, FieldValue::where('field_definition_id', $custom['id'])->count(), '고아 값 행이 남았다');
    }

    /**
     * 미입력 회원 수 — 컬럼 저장 항목과 값 테이블 저장 항목 모두 탈퇴 회원을 빼고 센다.
     *
     * @scenario action=missing_count
     *
     * @effects admin_missing_count_before_requiring
     *
     * @return void 반환값 없음
     */
    public function test_missing_count_for_column_and_value_fields(): void
    {
        $existing = User::count();
        $existingWithoutMobile = User::where(fn ($query) => $query->whereNull('mobile')->orWhere('mobile', ''))->count();
        User::factory()->create(['mobile' => '010-1111-2222']);
        User::factory()->create(['mobile' => null]);
        User::factory()->create(['mobile' => '']);
        User::factory()->create(['mobile' => null, 'status' => 'withdrawn']);

        $this->getJson(self::BASE.'/'.$this->idOf('mobile').'/missing-count', $this->auth())
            ->assertOk()->assertJsonPath('data.missing_count', $existingWithoutMobile + 2);

        $custom = $this->storeCustom()->json('data.field');
        $filled = User::factory()->create();
        app(FieldValueService::class)->write($filled, 'core.user', ['t490_text' => '팀장'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $filled));

        // 탈퇴 1명과 값을 넣은 1명을 뺀 전원.
        $this->getJson(self::BASE.'/'.$custom['id'].'/missing-count', $this->auth())
            ->assertOk()->assertJsonPath('data.missing_count', $existing + 3);
    }

    /**
     * 권한 — 환경설정 조회 권한만 있으면 목록·상세는 열리고 변경은 403, 권한이 없으면 목록도 403, 없는 집합은 404.
     *
     * @scenario action=index
     *
     * @effects admin_field_permission_gate
     *
     * @return void 반환값 없음
     */
    public function test_permission_gate_by_set_permission(): void
    {
        $readOnly = $this->tokenWithPermissions(['core.settings.read']);
        $unrelated = $this->tokenWithPermissions(['core.users.read']);
        $id = $this->idOf('mobile');

        $list = $this->getJson(self::BASE, $this->auth($readOnly))->assertOk();
        $this->assertFalse($list->json('data.abilities.can_update'));
        $this->getJson(self::BASE.'/'.$id, $this->auth($readOnly))->assertOk();
        $this->getJson(self::BASE.'/'.$id.'/missing-count', $this->auth($readOnly))->assertOk();

        $this->putJson(self::BASE.'/'.$id, ['required' => true], $this->auth($readOnly))->assertStatus(403);
        $this->postJson(self::BASE, ['key' => 'x_one', 'type' => 'text', 'name' => ['ko' => '가']], $this->auth($readOnly))->assertStatus(403);
        $this->putJson(self::BASE.'/order', ['order' => [$id]], $this->auth($readOnly))->assertStatus(403);
        $this->deleteJson(self::BASE.'/'.$id, [], $this->auth($readOnly))->assertStatus(403);
        $this->assertFalse((bool) FieldDefinition::find($id)->required, '권한 없는 요청이 저장됐다');

        $this->getJson(self::BASE, $this->auth($unrelated))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE, ['Accept' => 'application/json'])->assertStatus(401);
        $this->getJson('/api/admin/field-sets/no.such.set/fields', $this->auth())->assertStatus(404);
        $this->getJson(self::BASE.'/999999', $this->auth())->assertStatus(404);
    }

    /**
     * 확장 항목의 출처 이름과 확장 유형의 출처 이름은 설치 기록의 다국어 이름(언어팩이 채운 ja 포함)에서 요청 언어로 고른다.
     * 확장 manifest 는 ko·en 뿐이라 manifest 에서 고르면 일본어 화면에 한국어가 남는다.
     *
     * @scenario action=index
     *
     * @effects admin_field_source_label_follows_installed_name_locale
     *
     * @return void 반환값 없음
     */
    public function test_source_and_type_owner_labels_use_installed_localized_name(): void
    {
        Module::factory()->create([
            'identifier' => 'test-srclabel',
            'name' => ['ko' => '시험 모듈', 'en' => 'Test Module', 'ja' => 'テストモジュール'],
        ]);
        $definition = FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'srclabel_x', 'type' => 'text', 'source_type' => 'module',
            'source_identifier' => 'test-srclabel', 'storage' => 'value', 'name' => ['ko' => '시험'],
        ]);
        $registry = app(FieldRegistry::class);

        $this->assertSame('テストモジュール', $registry->sourceLabel($definition, 'ja'));
        $this->assertSame('Test Module', $registry->sourceLabel($definition, 'en'));
        $this->assertSame('시험 모듈', $registry->sourceLabel($definition, 'ko'));

        // 확장이 준 입력 유형의 타일 배지도 식별자가 아니라 같은 이름을 싣는다.
        app(FieldTypeRegistry::class)->register(new class extends TextType
        {
            /**
             * 시험용 확장 유형 키를 반환합니다.
             *
             * @return string 유형 키
             */
            public function key(): string
            {
                return 'srclabel_text';
            }
        }, 'module', 'test-srclabel');
        $types = array_column($this->getJson(self::BASE, $this->auth())->assertOk()->json('data.types'), null, 'key');
        $this->assertSame('test-srclabel', $types['srclabel_text']['owner'] ?? null);
        $this->assertSame('시험 모듈', $types['srclabel_text']['owner_label'] ?? null);
        $this->assertNull($types['text']['owner_label'] ?? null);
    }
}
