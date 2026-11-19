<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldValueService;
use App\Helpers\TimezoneHelper;
use App\Models\FieldDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — 회원 폼·상세가 그릴 회원 항목 조회 API (`GET /api/admin/users/form-fields`, `GET /api/admin/users/{user}/form-fields`).
 */
class UserFormFieldsApiTest extends TestCase
{
    use RefreshDatabase;

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
     * 테스트가 건 잠금 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.user.locked_fields');
        HookManager::clearFilter('core.user.locked_field_reasons');
        parent::tearDown();
    }

    /**
     * 인증 헤더를 만듭니다 (한 테스트 안에서 토큰을 바꿔 쓰므로 가드를 비운다).
     *
     * @param  string|null  $token  토큰 (null 이면 최고관리자)
     * @return array<string, string> 헤더
     */
    private function auth(?string $token = null): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($token ?? $this->adminToken), 'Accept' => 'application/json'];
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
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key.' 이름'], 'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 회원 추가 화면용 — 사용 중인 항목 전부가 범위와 함께 오고, 파일 항목은 저장 뒤에 올리는 안내 대상이다.
     *
     * @scenario mode=create
     *
     * @effects admin_form_schema_lists_all_scopes, admin_form_schema_defers_files_on_create
     *
     * @return void 반환값 없음
     */
    public function test_create_form_lists_all_scopes_and_defers_files(): void
    {
        $this->custom('af_self', 'text', ['required' => true]);
        $this->custom('af_admin_edit', 'text', ['edit_scope' => FieldDefinition::SCOPE_ADMIN_EDIT]);
        $this->custom('af_admin_only', 'text', ['edit_scope' => FieldDefinition::SCOPE_ADMIN_ONLY]);
        $this->custom('af_file', 'file', ['required' => true]);
        $this->custom('af_off', 'text', ['enabled' => false]);

        $data = $this->getJson('/api/admin/users/form-fields', $this->auth())->assertOk()->json('data');
        $fields = collect($data['fields'])->keyBy('key');

        $this->assertNull($data['member']);
        $this->assertNotEmpty($data['fields_version']);
        foreach (['name', 'nickname', 'mobile', 'phone', 'homepage', 'language', 'country', 'timezone', 'address', 'signature', 'bio'] as $key) {
            $this->assertTrue($fields->has($key), "코어 항목 {$key} 가 없다");
        }
        $this->assertSame('name', $fields['name']['name']);
        $this->assertSame('self', $fields['name']['edit_scope']);
        $this->assertSame('custom_fields.af_self', $fields['af_self']['name']);
        $this->assertTrue($fields['af_self']['required']);
        $this->assertSame('admin_edit', $fields['af_admin_edit']['edit_scope']);
        $this->assertSame('admin_only', $fields['af_admin_only']['edit_scope']);
        $this->assertFalse($fields['af_admin_only']['readonly']);
        $this->assertTrue($fields['af_file']['deferred']);
        $this->assertFalse($fields['af_file']['required'], '추가 화면에서 올릴 수 없는 파일 항목이 필수로 표시됐다');
        $this->assertFalse($fields->has('af_off'), '꺼 둔 항목이 실렸다');
        // 확장 이름은 모듈과 플러그인이 선언한 항목에만 실린다
        $this->assertNull($fields['name']['source_label']);
        $this->assertNull($fields['af_self']['source_label']);
    }

    /**
     * 수정·상세용 — 현재 값의 보기용 글자, 외부가 잠근 칸, 회원의 항목 상태가 함께 온다.
     *
     * @scenario mode=update
     *
     * @effects admin_form_schema_carries_values_and_locks, admin_form_schema_reports_member_status
     *
     * @return void 반환값 없음
     */
    public function test_update_form_carries_values_locks_and_member_status(): void
    {
        $this->custom('af_choice', 'choice', ['options' => [['value' => 'a', 'label' => ['ko' => '가']], ['value' => 'b', 'label' => ['ko' => '나']]]]);
        $this->custom('af_demanded', 'text', ['required' => true, 'require_existing_members' => true]);
        $this->custom('af_file', 'file');
        $this->custom('af_yes', 'boolean');
        $this->custom('af_no', 'boolean');
        $this->custom('af_unset', 'boolean');
        $changedAt = now()->subDays(3);
        $user = User::factory()->create(['name' => '대상회원', 'country' => 'US', 'nickname_changed_at' => $changedAt, 'required_fields_deferred_at' => null]);
        app(FieldValueService::class)->write($user, 'core.user', ['af_choice' => 'b', 'af_yes' => true, 'af_no' => false], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        HookManager::addFilter('core.user.locked_fields', fn ($fields) => [...(array) $fields, 'name']);
        HookManager::addFilter('core.user.locked_field_reasons', fn ($reasons) => [...(array) $reasons, 'name' => 'sso']);

        $data = $this->getJson("/api/admin/users/{$user->uuid}/form-fields", $this->auth())->assertOk()->json('data');
        $fields = collect($data['fields'])->keyBy('key');

        $this->assertSame('나', $fields['af_choice']['display_value']);
        $this->assertSame('대상회원', $fields['name']['display_value']);
        // 예/아니오: 저장된 「아니오」는 글자로, 답한 적 없는 칸은 null
        $this->assertSame(__('fields.boolean.yes'), $fields['af_yes']['display_value']);
        $this->assertSame(__('fields.boolean.no'), $fields['af_no']['display_value']);
        $this->assertNull($fields['af_unset']['display_value']);
        $this->assertTrue($fields['name']['readonly']);
        $this->assertSame('sso', $fields['name']['locked_reason']);
        $this->assertFalse($fields['nickname']['readonly'], '관리자 화면에 닉네임 변경 주기 잠금이 걸렸다');
        $this->assertFalse($fields['af_file']['deferred']);
        $this->assertSame([], $fields['af_file']['files']);
        // 수정 화면은 비어 있는 필수 항목을 저장의 걸림돌로 삼지 않는다
        $this->assertFalse($fields['af_demanded']['required']);

        $this->assertSame($changedAt->copy()->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d H:i'), $data['member']['nickname_changed_at']);
        $this->assertNull($data['member']['required_fields_deferred_at']);
        $this->assertSame([['key' => 'af_demanded', 'label' => 'af_demanded 이름']], $data['member']['pending_required_fields']);
        $this->assertArrayHasKey('nickname_next_changeable_at', $data['member']);
    }

    /**
     * 회원 조회 권한이 없으면 두 주소 모두 403 이고, 없는 회원은 404 다.
     *
     * @scenario mode=forbidden
     *
     * @effects admin_form_schema_requires_user_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_requires_user_read_permission(): void
    {
        $member = User::factory()->create();
        $memberToken = $member->createToken('test')->plainTextToken;

        $this->getJson('/api/admin/users/form-fields', $this->auth($memberToken))->assertStatus(403);
        $this->getJson("/api/admin/users/{$member->uuid}/form-fields", $this->auth($memberToken))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/users/form-fields', ['Accept' => 'application/json'])->assertStatus(401);
        $this->getJson('/api/admin/users/00000000-0000-0000-0000-000000000000/form-fields', $this->auth())->assertStatus(404);
    }

    /**
     * 지정한 권한(범위 포함)만 가진 관리자의 토큰을 만듭니다.
     *
     * @param  array<string, string|null>  $permissions  권한 식별자 => 범위(null 이면 전체)
     * @return array{0: User, 1: string} 관리자와 토큰
     */
    private function scopedAdmin(array $permissions): array
    {
        $role = Role::create([
            'identifier' => 'form_fields_scope_'.uniqid(),
            'name' => ['ko' => '항목 범위 시험', 'en' => 'Form fields scope test'],
            'is_active' => true,
        ]);
        $pivot = [];
        foreach ($permissions as $identifier => $scope) {
            $pivot[Permission::where('identifier', $identifier)->firstOrFail()->id] = ['scope_type' => $scope, 'granted_at' => now()];
        }
        $role->permissions()->attach($pivot);

        $admin = User::factory()->create();
        $admin->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return [$admin, $admin->fresh()->createToken('test')->plainTextToken];
    }

    /**
     * 회원 추가 화면용 항목 계약은 회원 추가 권한을 요구한다 — 조회 권한만으로는 받을 수 없다.
     *
     * @scenario mode=forbidden
     *
     * @effects admin_create_form_schema_requires_user_create_permission
     *
     * @return void 반환값 없음
     */
    public function test_create_form_requires_user_create_permission(): void
    {
        [, $readOnly] = $this->scopedAdmin(['core.users.read' => null]);
        $this->getJson('/api/admin/users/form-fields', $this->auth($readOnly))->assertStatus(403);

        [, $creator] = $this->scopedAdmin(['core.users.create' => null]);
        $this->getJson('/api/admin/users/form-fields', $this->auth($creator))->assertOk();
    }

    /**
     * 회원을 지정한 항목 계약은 회원 조회 권한의 범위를 따른다 — 「본인만」 범위의 관리자는 다른 회원의 계약을 받을 수 없다.
     *
     * @scenario mode=forbidden
     *
     * @effects admin_update_form_schema_follows_user_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_update_form_follows_user_read_scope(): void
    {
        [$self, $selfToken] = $this->scopedAdmin(['core.users.read' => 'self']);
        $other = User::factory()->create();

        $this->getJson("/api/admin/users/{$other->uuid}/form-fields", $this->auth($selfToken))->assertStatus(403);
        $this->getJson("/api/admin/users/{$self->uuid}/form-fields", $this->auth($selfToken))->assertOk();
    }
}
