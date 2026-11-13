<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackScope;
use App\Enums\PermissionType;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\ModuleManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\Module as ModuleRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Board\Fields\UserNotificationFieldHandler;
use Modules\Sirsoft\Board\Listeners\UserNotificationSettingsListener;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ActivatesBundledLanguagePack;

/**
 * 게시판 알림 수신 설정 4칸의 회원 항목 편입 — 선언, 4경로(가입, 관리자 추가, 관리자 수정, 내 정보 수정)의 저장과 응답,
 * 이중 저장 없음, 모듈을 끄면 항목이 빠짐, 회원 값 파기.
 */
#[Group('board')]
#[Group('fields')]
class UserNotificationFieldSetTest extends ModuleTestCase
{
    use ActivatesBundledLanguagePack;

    private const TABLE = 'board_user_notification_settings';

    private const PASSWORD = 'Str0ng!Passw0rd#490';

    /**
     * 모듈의 항목 선언을 동기화한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionSyncHelper::class)->sync('module', 'sirsoft-board', (new Module)->getFields());
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 모듈 상태 캐시와 항목 정의 메모를 비운다 (롤백된 행이 다음 테스트에 남지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        parent::tearDown();
    }

    /**
     * 회원 조회, 추가, 수정 권한을 가진 최고관리자를 만들어 로그인시킵니다.
     *
     * @return User 최고관리자
     */
    private function actingAsSuperAdmin(): User
    {
        $permissionIds = [];
        foreach (['core.users.read', 'core.users.create', 'core.users.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }
        $role = Role::where('identifier', 'admin')->firstOrFail();
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach($role->id, ['assigned_at' => now()]);
        $admin = $admin->fresh();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * 일반 회원 역할 ID 목록을 반환합니다.
     *
     * @return array<int, int> 역할 ID 목록
     */
    private function userRoleIds(): array
    {
        return [(int) Role::where('identifier', 'user')->value('id')];
    }

    /**
     * 항목 정의의 속성을 운영자가 바꾼 것처럼 고칩니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function overrideField(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 콜백이 도는 동안 알림 설정 테이블에 나간 쓰기 쿼리(insert, update) 수를 셉니다.
     *
     * @param  callable  $callback  실행할 동작
     * @return int 쓰기 쿼리 수
     */
    private function countSettingWrites(callable $callback): int
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_contains($query->sql, self::TABLE) && preg_match('/^\s*(insert|update)\b/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $callback();

        return $writes;
    }

    /**
     * 선언 — 네 항목이 회원 집합에 예/아니오, 핸들러 저장, 알림 묶음, 가입 표시 꺼짐으로 올라간다.
     *
     * @scenario case=declaration
     *
     * @effects notification_fields_declared_in_core_user_set
     *
     * @return void 반환값 없음
     */
    public function test_declares_four_notification_fields_in_core_user_set(): void
    {
        // 모듈은 비회원 작성자 집합에도 항목을 선언한다 — 여기서는 회원 집합의 선언만 본다
        $declared = collect((new Module)->getFields())->where('field_set', FieldSet::CORE_USER)->keyBy('key');

        $this->assertSame(UserNotificationFieldHandler::FIELD_KEYS, $declared->keys()->all());

        foreach (UserNotificationFieldHandler::FIELD_KEYS as $key) {
            $row = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', $key)->first();

            $this->assertNotNull($row, "{$key} 항목이 동기화되지 않았다");
            $this->assertSame('module', $row->source_type);
            $this->assertSame('sirsoft-board', $row->source_identifier);
            $this->assertSame('boolean', $row->type);
            $this->assertSame('handler', $row->storage);
            $this->assertSame(UserNotificationFieldHandler::class, $row->storage_target);
            $this->assertFalse((bool) $row->show_on_signup);
            $this->assertFalse((bool) $row->required);
            $this->assertSame('self', $row->edit_scope);
            $this->assertSame(['group' => 'notifications'], $row->ui);
            $this->assertNotSame('', (string) ($row->name['ko'] ?? ''));
            $this->assertNotSame('', (string) ($row->name['en'] ?? ''));
            $this->assertNotSame('', (string) ($row->description['ko'] ?? ''));
            // 요청 키는 항목 키 그대로(최상위)
            $this->assertSame($key, app(FieldRegistry::class)->attributeFor($row));
        }
    }

    /**
     * 활성 ja 언어팩이 있으면 모듈이 선언한 항목(회원 알림 4칸, 비회원 작성자 항목) 이름에 ja 가 실린다.
     *
     * 선언은 모듈 자체 언어 파일만 읽어 일본어 화면에서 이 항목들만 한국어로 보였다(실측 C-O4). 이제
     * `ExtensionLangTexts` 로 모듈을 대상으로 하는 활성 팩의 항목 이름 파일(번들 팩은 `lang-packs/_bundled/g7-module-sirsoft-board-ja/backend/ja/fields.php`)도 읽는다.
     *
     * @scenario case=language_pack_names
     *
     * @effects declared_field_names_include_active_language_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_declared_field_names_include_active_ja_language_pack(): void
    {
        $this->activateBundledLanguagePack('g7-module-sirsoft-board-ja', LanguagePackScope::Module, 'sirsoft-board');

        $declared = collect((new Module)->getFields());
        $this->assertNotEmpty($declared);

        foreach ($declared as $declaration) {
            $this->assertNotSame('', (string) ($declaration['name']['ja'] ?? ''), "{$declaration['field_set']}.{$declaration['key']} 이름에 ja 가 없다");
            $this->assertNotSame($declaration['name']['ko'], $declaration['name']['ja']);
        }
        $this->assertSame('コメント通知', $declared->firstWhere('key', 'notify_comment')['name']['ja']);
    }

    /**
     * 리스너는 응답 필터만 구독한다 — 검증과 저장 훅을 구독하면 항목 엔진과 겹쳐 두 번 검증하고 두 번 저장한다.
     *
     * @scenario case=subscription_contract
     *
     * @effects listener_subscribes_resource_filter_only
     *
     * @return void 반환값 없음
     */
    public function test_listener_no_longer_subscribes_validation_and_save_hooks(): void
    {
        $this->assertSame(['core.user.filter_resource_data'], array_keys(UserNotificationSettingsListener::getSubscribedHooks()));
    }

    /**
     * 관리자 회원 추가 — 보낸 칸이 회원과 함께 저장되고, 보내지 않은 칸은 기본값(false)으로 남는다.
     *
     * @scenario case=admin_create
     *
     * @effects notification_values_saved_on_admin_create
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_stores_notification_values(): void
    {
        $this->actingAsSuperAdmin();
        $email = 'issue490+boardcreate'.uniqid().'@example.com';

        $this->postJson('/api/admin/users', [
            'name' => '알림 회원', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'role_ids' => $this->userRoleIds(),
            'notify_post_complete' => true,
            'notify_comment' => true,
            'notify_post_reply' => false,
        ])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $setting = UserNotificationSetting::where('user_id', $user->id)->first();

        $this->assertNotNull($setting);
        $this->assertTrue($setting->notify_post_complete);
        $this->assertTrue($setting->notify_comment);
        $this->assertFalse($setting->notify_post_reply);
        $this->assertFalse($setting->notify_reply_comment);
    }

    /**
     * 관리자 회원 추가 — 큐 설정과 무관하게 회원 생성과 같은 요청 안에서 저장된다(워커를 기다리지 않는다).
     *
     * @scenario case=admin_create_queue_independent
     *
     * @effects notification_values_saved_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_saves_in_request_even_when_hooks_are_queued(): void
    {
        config(['queue.default' => 'database']);
        $this->actingAsSuperAdmin();

        $user = app(UserService::class)->createUser([
            'name' => '알림 회원',
            'email' => 'issue490+boardqueue'.uniqid().'@example.com',
            'password' => self::PASSWORD,
            'notify_post_complete' => true,
        ]);

        // 워커를 돌리지 않았는데도 값이 있다
        $this->assertTrue((bool) UserNotificationSetting::where('user_id', $user->id)->value('notify_post_complete'));
    }

    /**
     * 알림 칸 없이 회원을 만들면 설정 행을 만들지 않는다.
     *
     * @scenario case=admin_create_no_fields
     *
     * @effects no_settings_row_without_notification_fields
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_without_notification_fields_creates_no_row(): void
    {
        $this->actingAsSuperAdmin();

        $user = app(UserService::class)->createUser([
            'name' => '알림 없는 회원',
            'email' => 'issue490+boardplain'.uniqid().'@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->assertDatabaseMissing(self::TABLE, ['user_id' => $user->id]);
    }

    /**
     * 관리자 회원 수정 — 값이 저장되고 응답의 최상위 키로 돌아오며, 바뀐 칸마다 쓰기가 한 번만 나간다.
     *
     * @scenario case=admin_update
     *
     * @effects notification_values_saved_on_admin_update, notification_values_in_user_response, single_write_per_changed_field
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_stores_values_once_and_returns_them(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->createUser();

        $writes = $this->countSettingWrites(function () use ($target) {
            $this->putJson("/api/admin/users/{$target->uuid}", [
                'name' => $target->name, 'email' => $target->email,
                'role_ids' => $this->userRoleIds(),
                'notify_post_reply' => true,
                'notify_post_complete' => false,
                'notify_comment' => false,
                'notify_reply_comment' => false,
            ])->assertOk();
        });

        // 바뀐 칸은 하나(notify_post_reply)뿐이다 — 값이 같은 칸은 쓰지 않고, 옛 리스너와 겹친 두 번째 저장도 없다
        $this->assertSame(1, $writes);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => $target->id, 'notify_post_reply' => true, 'notify_comment' => false]);

        $this->getJson("/api/admin/users/{$target->uuid}")
            ->assertOk()
            ->assertJsonPath('data.notify_post_reply', true)
            ->assertJsonPath('data.notify_post_complete', false)
            ->assertJsonPath('data.notify_comment', false)
            ->assertJsonPath('data.notify_reply_comment', false);
    }

    /**
     * 관리자 회원 수정 — 요청에 실리지 않은 칸은 저장된 값이 그대로 남는다.
     *
     * @scenario case=admin_update_partial
     *
     * @effects absent_notification_fields_untouched
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_leaves_absent_fields_untouched(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->createUser();
        UserNotificationSetting::create(['user_id' => $target->id, 'notify_post_complete' => true, 'notify_comment' => true]);

        $this->putJson("/api/admin/users/{$target->uuid}", [
            'name' => $target->name, 'email' => $target->email,
            'role_ids' => $this->userRoleIds(),
            'notify_comment' => false,
        ])->assertOk();

        $setting = UserNotificationSetting::where('user_id', $target->id)->firstOrFail();
        $this->assertTrue($setting->notify_post_complete, '요청에 없던 칸이 바뀌었다');
        $this->assertFalse($setting->notify_comment);
    }

    /**
     * 예/아니오가 아닌 값은 그 칸의 검증 오류가 된다 (규칙은 항목 엔진 한 곳에서 한 번만 붙는다).
     *
     * @scenario case=validation
     *
     * @effects notification_field_validated_as_boolean_once
     *
     * @return void 반환값 없음
     */
    public function test_non_boolean_value_is_rejected_and_rule_is_added_once(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->createUser();

        $this->putJson("/api/admin/users/{$target->uuid}", [
            'name' => $target->name, 'email' => $target->email,
            'role_ids' => $this->userRoleIds(),
            'notify_comment' => 'maybe',
        ])->assertStatus(422)->assertJsonValidationErrors(['notify_comment']);

        $rules = app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $target));
        $this->assertSame(['nullable', 'boolean'], $rules['notify_comment']);
    }

    /**
     * 내 정보 수정 — 값이 저장되고 내 정보 응답의 최상위 키로 돌아오며, 바뀐 칸마다 쓰기가 한 번만 나간다.
     *
     * @scenario case=self_update
     *
     * @effects notification_values_saved_on_self_update, notification_values_in_user_response, single_write_per_changed_field
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_stores_values_once_and_returns_them(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $writes = $this->countSettingWrites(function () use ($user) {
            $this->putJson('/api/me', [
                'name' => $user->name, 'email' => $user->email,
                'notify_comment' => true,
                'notify_reply_comment' => true,
            ])->assertOk();
        });

        $this->assertSame(2, $writes);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => $user->id, 'notify_comment' => true, 'notify_reply_comment' => true, 'notify_post_complete' => false]);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.notify_comment', true)
            ->assertJsonPath('data.notify_reply_comment', true)
            ->assertJsonPath('data.notify_post_complete', false);
    }

    /**
     * 가입 — 기본값에서는 가입 화면이 받지 않아 보낸 값이 무시되고, 운영자가 가입 표시를 켜면 저장된다.
     *
     * @scenario case=signup
     *
     * @effects notification_values_ignored_on_signup_by_default, notification_values_saved_on_signup_when_enabled
     *
     * @return void 반환값 없음
     */
    public function test_signup_ignores_values_by_default_and_stores_them_when_shown(): void
    {
        $body = fn (string $email) => [
            'name' => '가입 회원', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'agree_terms' => true, 'agree_privacy' => true,
            'notify_comment' => true,
        ];

        // 기본값: 가입 화면 표시 꺼짐
        $signupKeys = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->pluck('key');
        $this->assertNotContains('notify_comment', $signupKeys->all());

        $first = 'issue490+boardsignup'.uniqid().'@example.com';
        $this->postJson('/api/auth/register', $body($first))->assertStatus(201);
        $this->assertDatabaseMissing(self::TABLE, ['user_id' => User::where('email', $first)->value('id')]);

        // 운영자가 가입 화면 표시를 켬
        $this->overrideField('notify_comment', ['show_on_signup' => true]);
        $this->app['auth']->forgetGuards();

        $signupKeys = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->pluck('key');
        $this->assertContains('notify_comment', $signupKeys->all());

        $second = 'issue490+boardsignup'.uniqid().'@example.com';
        $this->postJson('/api/auth/register', $body($second))->assertStatus(201);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => User::where('email', $second)->value('id'), 'notify_comment' => true]);
    }

    /**
     * 관리자 회원 폼의 항목 계약 — 네 항목이 모듈 소유 항목으로, 현재 값과 함께 나온다.
     *
     * @scenario case=admin_form_schema
     *
     * @effects notification_fields_in_admin_form_schema
     *
     * @return void 반환값 없음
     */
    public function test_admin_form_schema_lists_fields_as_module_owned(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->createUser();
        UserNotificationSetting::create(['user_id' => $target->id, 'notify_comment' => true]);

        $fields = collect($this->getJson("/api/admin/users/{$target->uuid}/form-fields")->assertOk()->json('data.fields'))->keyBy('key');

        foreach (UserNotificationFieldHandler::FIELD_KEYS as $key) {
            $this->assertTrue($fields->has($key), "{$key} 항목이 계약에 없다");
            $this->assertSame($key, $fields[$key]['name']);
            $this->assertSame('boolean', $fields[$key]['type']);
            $this->assertSame('module', $fields[$key]['owner_type']);
            $this->assertSame('sirsoft-board', $fields[$key]['owner']);
            $this->assertSame('notifications', $fields[$key]['ui']['group']);
            $this->assertFalse($fields[$key]['required']);
        }
        // 켠 칸과 끈 칸의 보기용 글자가 다르다 (핸들러가 읽은 현재 값이 계약에 실린다)
        $this->assertNotNull($fields['notify_comment']['display_value']);
        $this->assertNotSame($fields['notify_comment']['display_value'], $fields['notify_post_reply']['display_value']);

        // 추가 화면의 계약에도 나온다
        $createKeys = collect($this->getJson('/api/admin/users/form-fields')->assertOk()->json('data.fields'))->pluck('key')->all();
        $this->assertEmpty(array_diff(UserNotificationFieldHandler::FIELD_KEYS, $createKeys));
    }

    /**
     * 모듈을 끄면 항목이 규칙과 계약에서 빠지고, 보낸 값은 저장되지 않는다.
     *
     * @scenario case=module_inactive
     *
     * @effects notification_fields_removed_when_module_inactive
     *
     * @return void 반환값 없음
     */
    public function test_fields_disappear_when_module_is_inactive(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->createUser();

        ModuleRecord::where('identifier', 'sirsoft-board')->update(['status' => ExtensionStatus::Inactive->value]);
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertNotContains('sirsoft-board', ModuleManager::getActiveModuleIdentifiers());

        $rules = app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $target));
        foreach (UserNotificationFieldHandler::FIELD_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $rules);
        }

        $activeKeys = app(FieldRegistry::class)->active(FieldSet::CORE_USER)->pluck('key')->all();
        $this->assertEmpty(array_intersect(UserNotificationFieldHandler::FIELD_KEYS, $activeKeys));

        $values = app(FieldValueService::class)->partition(FieldSet::CORE_USER, ['notify_comment' => true], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $target));
        $this->assertSame([], $values['values']);

        // 행은 지워지지 않는다 — 다시 켜면 운영자 설정과 함께 돌아온다
        $this->assertSame(4, FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('source_type', 'module')->where('source_identifier', 'sirsoft-board')->count());
    }

    /**
     * 회원 값 파기 — 항목 값 파기가 이 회원의 알림 설정 행을 지운다(네 항목이 차례로 불러도 안전하다).
     *
     * @scenario case=purge
     *
     * @effects notification_settings_row_purged_with_member_values
     *
     * @return void 반환값 없음
     */
    public function test_purge_owner_deletes_settings_row(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        UserNotificationSetting::create(['user_id' => $user->id, 'notify_comment' => true]);
        UserNotificationSetting::create(['user_id' => $other->id, 'notify_comment' => true]);

        app(FieldValueService::class)->purgeOwner($user, FieldSet::CORE_USER);

        $this->assertDatabaseMissing(self::TABLE, ['user_id' => $user->id]);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => $other->id]);
    }
}
