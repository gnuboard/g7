<?php

namespace Tests\Feature\DisplayName;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameChoicePolicy;
use App\DisplayName\NicknameRequirement;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Http\Resources\UserResource;
use App\Models\FieldDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\AuthService;
use App\Services\CoreUpdateService;
use App\Services\UserService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 표시 이름 — 가입과 내 정보에서 종류 고르기, 권한과 허용 종류 검증, 응답의 새 키, 닉네임 잠금,
 * 환경설정 회원 탭의 안내와 저장, 추가 항목 삭제 차단, 새 권한의 역할 부여.
 */
class DisplayNameChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    /**
     * 역할과 코어 회원 항목을 시딩하고 최고관리자를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true, 'name' => '관리자', 'nickname' => null, 'email' => 'boss@example.com']);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->admin = $this->admin->fresh();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.user.display_name_changed');
        parent::tearDown();
    }

    /**
     * 표시 이름 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  회원 범주 설정
     * @return void 반환값 없음
     */
    private function settings(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
    }

    /**
     * 회원이 종류를 고를 수 있는 설정으로 바꿉니다 (사이트 기본 영역이 「회원이 고른 값」).
     *
     * @param  array<int, string>  $kinds  회원이 고를 수 있는 종류
     * @param  string  $default  회원 기본 종류
     * @return void 반환값 없음
     */
    private function letMembersChoose(array $kinds = ['name', 'nickname', 'masked_name'], string $default = 'name'): void
    {
        $this->settings([
            'display_name_selectable_kinds' => $kinds,
            'display_name_member_default_kind' => $default,
            'display_name_areas' => ['core:site' => ['mode' => 'member'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name']],
        ]);
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
     * 서비스로 일반 회원을 만듭니다 (`user` 역할이 붙는다).
     *
     * @param  string  $local  이메일 앞부분
     * @param  string|null  $nickname  닉네임
     * @param  string  $name  이름
     * @return User 만든 회원
     */
    private function member(string $local, ?string $nickname = '길동이', string $name = '홍길동'): User
    {
        return app(AuthService::class)->register(
            ['name' => $name, 'nickname' => $nickname, 'email' => "{$local}@example.com"],
            ['issue_token' => false],
        )['user']->fresh();
    }

    /**
     * 회원의 토큰 헤더를 만듭니다.
     *
     * @param  User  $user  회원
     * @return array<string, string> 헤더
     */
    private function as(User $user): array
    {
        return $this->auth($user->createToken('test')->plainTextToken);
    }

    /**
     * 내 정보 수정 본문을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $extra  더할 값
     * @return array<string, mixed> 본문
     */
    private function profileBody(User $user, array $extra = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'nickname' => $user->nickname], $extra);
    }

    /**
     * 공개 가입 요청 본문을 만듭니다.
     *
     * @param  string  $local  이메일 앞부분
     * @param  array<string, mixed>  $extra  더할 값
     * @return array<string, mixed> 본문
     */
    private function signupBody(string $local, array $extra = []): array
    {
        return array_merge([
            'name' => '가입자', 'nickname' => '새내기', 'email' => "{$local}@example.com",
            'password' => 'Iss490!displayAa1', 'password_confirmation' => 'Iss490!displayAa1',
            'agree_terms' => true, 'agree_privacy' => true,
        ], $extra);
    }

    /**
     * `user` 역할에서 표시 이름 선택 권한을 뗍니다.
     *
     * @return void 반환값 없음
     */
    private function revokeChoosePermission(): void
    {
        $permission = Permission::where('identifier', DisplayNameChoicePolicy::PERMISSION)->firstOrFail();
        Role::where('identifier', 'user')->firstOrFail()->permissions()->detach($permission->id);
    }

    /**
     * 표시 이름으로 쓸 수 있는 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @return FieldDefinition 만든 항목
     */
    private function displayField(string $key = 'alias'): FieldDefinition
    {
        $definition = FieldDefinition::create([
            'field_set' => FieldSet::CORE_USER, 'key' => $key, 'type' => 'text', 'source_type' => 'custom', 'source_identifier' => 'custom',
            'storage' => 'value', 'name' => ['ko' => '활동명', 'en' => 'Alias'], 'enabled' => true, 'display_name_usable' => true, 'sort_order' => 900,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition;
    }

    /**
     * 항목 계약 목록에서 키로 항목을 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $fields  항목 목록
     * @param  string  $key  항목 키
     * @return array<string, mixed>|null 항목
     */
    private function field(array $fields, string $key): ?array
    {
        foreach ($fields as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * 표시 이름 설정이 기본값인 사이트는 모든 응답의 이름 값이 종전과 같고, `display_name` 은 이름과 같은 값으로 더해질 뿐이다.
     * 고를 종류가 하나뿐이라 선택 칸은 나타나지 않고 닉네임 항목도 잠기지 않는다.
     *
     * @scenario case=default_site_unchanged
     *
     * @effects default_settings_keep_name_values, default_settings_display_name_equals_name, default_settings_hide_choice, default_settings_leave_nickname_unlocked
     *
     * @return void 반환값 없음
     */
    public function test_default_settings_change_nothing_but_add_display_name(): void
    {
        $user = $this->member('plain');
        $headers = $this->as($user);

        $authUser = $this->getJson('/api/auth/user', $headers)->assertOk()->json('data');
        $profile = $this->getJson('/api/me', $headers)->assertOk()->json('data');
        foreach ([$authUser, $profile] as $data) {
            $this->assertSame(['홍길동', '길동이', '홍길동', null, false], [
                $data['name'], $data['nickname'], $data['display_name'], $data['display_name_kind'], $data['can_choose_display_name'],
            ]);
        }

        $list = $this->getJson('/api/admin/users?per_page=50', $this->auth())->assertOk()->json('data.data');
        $row = collect($list)->firstWhere('uuid', $user->uuid);
        $this->assertSame(['홍길동', '길동이', '홍길동'], [$row['name'], $row['nickname'], $row['display_name']]);

        $detail = $this->getJson("/api/admin/users/{$user->uuid}", $this->auth())->assertOk()->json('data');
        $this->assertSame(['홍길동', '홍길동'], [$detail['name'], $detail['display_name']]);

        // 가입 화면: 종전 다섯 칸 그대로, 닉네임은 선택이고 잠기지 않는다. 선택 칸 묶음은 고를 수 없음으로 온다.
        $signup = $this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->assertOk()->json('data');
        $this->assertSame(['name', 'nickname', 'mobile', 'phone', 'language'], array_column($signup['fields'], 'key'));
        $this->assertFalse($this->field($signup['fields'], 'nickname')['required']);
        $this->assertSame([false, [], 'name'], [$signup['display_name']['can_choose'], $signup['display_name']['options'], $signup['display_name']['default_kind']]);
        $this->assertSame(NicknameRequirement::LEVEL_NONE, app(NicknameRequirement::class)->level());

        // 내 정보 수정과 가입도 종전 본문 그대로 된다 (가입 요청은 세션 로그인을 남기므로 마지막에 확인한다)
        $this->putJson('/api/me', $this->profileBody($user, ['nickname' => null]), $this->as($user))->assertOk();
        $this->assertNull($user->fresh()->nickname);
        $this->postJson('/api/auth/register', $this->signupBody('fresh', ['nickname' => null]))->assertStatus(201)
            ->assertJsonPath('data.user.name', '가입자')->assertJsonPath('data.user.display_name', '가입자');
    }

    /**
     * 가입 화면에서 종류를 고르면 저장되고, 그 종류가 닉네임이 필요하면 닉네임이 필수가 된다.
     *
     * @scenario case=choose_on_signup
     *
     * @effects signup_fields_offer_selectable_kinds_with_preview, signup_saves_chosen_kind, signup_requires_nickname_for_nickname_kind, signup_preview_uses_typed_values
     *
     * @return void 반환값 없음
     */
    public function test_member_chooses_kind_on_signup(): void
    {
        $this->letMembersChoose();

        $bundle = $this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->assertOk()->json('data.display_name');
        $this->assertTrue($bundle['can_choose']);
        $this->assertSame(['name', 'nickname', 'masked_name'], array_column($bundle['options'], 'value'));
        $this->assertSame(['이름', '닉네임', '가린 이름'], array_column($bundle['options'], 'label'));
        // 입력값을 주지 않으면 표본 회원으로 미리보기를 만든다
        $this->assertTrue($bundle['preview_is_sample']);
        $this->assertSame(['홍길동', '길동이', '홍*동'], array_column($bundle['options'], 'preview'));
        $this->assertSame([false, true, false], array_column($bundle['options'], 'requires_nickname'));
        $this->assertSame([null, false, 'name'], [$bundle['value'], $bundle['value_unavailable'], $bundle['effective_kind']]);

        // 입력 중인 값으로 미리보기 — 닉네임이 비면 대체 값(아이디 가림)
        $typed = $this->getJson('/api/auth/register/fields?'.http_build_query(['preview_name' => '남궁민수', 'preview_nickname' => '', 'preview_email' => 'minsu@example.com']), ['Accept-Language' => 'ko'])
            ->assertOk()->json('data.display_name');
        $this->assertFalse($typed['preview_is_sample']);
        $this->assertSame(['남궁민수', 'min***', '남**수'], array_column($typed['options'], 'preview'));

        // 닉네임 종류를 고르고 닉네임을 비우면 닉네임 칸의 오류
        $this->postJson('/api/auth/register', $this->signupBody('s1', ['display_name_kind' => 'nickname', 'nickname' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['nickname']);
        // 이름 종류면 닉네임 없이 가입된다
        $this->postJson('/api/auth/register', $this->signupBody('s2', ['display_name_kind' => 'masked_name', 'nickname' => null]))->assertStatus(201)
            ->assertJsonPath('data.user.display_name', '가*자');
        $this->assertSame('masked_name', User::where('email', 's2@example.com')->value('display_name_kind'));

        $this->postJson('/api/auth/register', $this->signupBody('s3', ['display_name_kind' => 'nickname']))->assertStatus(201)
            ->assertJsonPath('data.user.display_name', '새내기')->assertJsonPath('data.user.name', '가입자');
        $this->assertSame('nickname', User::where('email', 's3@example.com')->value('display_name_kind'));
    }

    /**
     * 내 정보 수정에서 종류를 고르면 저장되고 응답과 다른 회원에게 보이는 이름이 바뀐다. 빈 값으로 보내면 고른 종류를 지운다.
     *
     * @scenario case=choose_on_profile
     *
     * @effects profile_saves_chosen_kind, profile_clears_kind_with_null, profile_fields_report_current_name, display_name_changed_action_fired, admin_cannot_change_members_kind
     *
     * @return void 반환값 없음
     */
    public function test_member_chooses_kind_on_profile(): void
    {
        $this->letMembersChoose();
        $user = $this->member('p1');
        $fired = [];
        HookManager::addAction('core.user.display_name_changed', function (User $changed, array $what) use (&$fired) {
            $fired[] = [$changed->id, $what];
        });

        $this->assertTrue($this->getJson('/api/me', $this->as($user))->assertOk()->json('data.can_choose_display_name'));

        $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => 'nickname']), $this->as($user))->assertOk()
            ->assertJsonPath('data.display_name', '길동이')->assertJsonPath('data.name', '홍길동');
        $this->assertSame('nickname', $user->fresh()->display_name_kind);
        $this->assertSame([[$user->id, ['display_name_kind']]], $fired);

        $bundle = $this->getJson('/api/me/fields', $this->as($user))->assertOk()->json('data.display_name');
        $this->assertSame(['nickname', 'nickname', '길동이', false], [$bundle['value'], $bundle['effective_kind'], $bundle['current'], $bundle['preview_is_sample']]);
        $this->assertSame(['홍길동', '길동이', '홍*동'], array_column($bundle['options'], 'preview'));
        // 입력 중인 닉네임으로 미리보기
        $typed = $this->getJson('/api/me/fields?preview_nickname='.urlencode('바람'), $this->as($user))->assertOk()->json('data.display_name');
        $this->assertSame(['홍길동', '바람', '홍*동'], array_column($typed['options'], 'preview'));

        // 종류를 보내지 않으면 그대로, 닉네임만 바꾸면 이름이 달라졌다고 알린다
        $this->putJson('/api/me', $this->profileBody($user, ['nickname' => '새바람']), $this->as($user))->assertOk()->assertJsonPath('data.display_name', '새바람');
        $this->assertSame('nickname', $user->fresh()->display_name_kind);
        $this->assertSame([$user->id, ['nickname']], $fired[1]);

        // 닉네임으로 보이는 회원은 닉네임을 비울 수 없다
        $this->putJson('/api/me', $this->profileBody($user->fresh(), ['nickname' => null]), $this->as($user))->assertStatus(422)->assertJsonValidationErrors(['nickname']);

        // 관리자는 회원의 종류를 바꾸지 못한다(보내도 버린다)
        $roleIds = [(int) Role::where('identifier', 'user')->value('id')];
        $this->putJson("/api/admin/users/{$user->uuid}", ['name' => '홍길동', 'email' => $user->email, 'role_ids' => $roleIds, 'display_name_kind' => 'masked_name'], $this->auth())->assertOk();
        app(UserService::class)->updateUser($user->fresh(), ['display_name_kind' => 'masked_name']);
        $this->assertSame('nickname', $user->fresh()->display_name_kind);

        // 빈 값: 고른 종류를 지우고 회원 기본 종류로 보인다
        $this->putJson('/api/me', $this->profileBody($user->fresh(), ['display_name_kind' => null]), $this->as($user))->assertOk()->assertJsonPath('data.display_name', '홍길동');
        $this->assertNull($user->fresh()->display_name_kind);
    }

    /**
     * 표시 이름을 고를 권한이 없는 회원과 그런 역할로 가입하는 사람이 종류를 보내면 422 다. 화면 묶음도 고를 수 없음으로 온다.
     *
     * @scenario case=no_permission
     *
     * @effects kind_choice_without_permission_is_422_on_profile, kind_choice_without_permission_is_422_on_signup, bundle_hides_options_without_permission
     *
     * @return void 반환값 없음
     */
    public function test_choice_without_permission_is_rejected(): void
    {
        $this->letMembersChoose();
        $user = $this->member('n1');
        $this->revokeChoosePermission();
        $user = $user->fresh();

        $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => 'nickname']), $this->as($user))
            ->assertStatus(422)->assertJsonValidationErrors(['display_name_kind'])
            ->assertJsonPath('errors.display_name_kind.0', __('display_name.validation.not_permitted'));
        $this->assertNull($user->fresh()->display_name_kind);

        $profile = $this->getJson('/api/me', $this->as($user))->assertOk()->json('data');
        $this->assertFalse($profile['can_choose_display_name']);
        $bundle = $this->getJson('/api/me/fields', $this->as($user))->assertOk()->json('data.display_name');
        $this->assertSame([false, []], [$bundle['can_choose'], $bundle['options']]);

        $this->assertFalse($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->assertOk()->json('data.display_name.can_choose'));
        $this->postJson('/api/auth/register', $this->signupBody('n2', ['display_name_kind' => 'nickname']))
            ->assertStatus(422)->assertJsonValidationErrors(['display_name_kind']);
        $this->assertDatabaseMissing('users', ['email' => 'n2@example.com']);

        // 요청 규칙을 거치지 않는 서비스 호출이 실어 보낸 값도 저장되지 않는다
        $created = app(AuthService::class)->register(['name' => '직접', 'email' => 'n3@example.com', 'display_name_kind' => 'nickname'], ['issue_token' => false])['user'];
        $this->assertNull($created->fresh()->display_name_kind);
    }

    /**
     * 운영자가 허용하지 않은 종류와 없는 종류는 422 다.
     *
     * @scenario case=not_selectable
     *
     * @effects kind_outside_selectable_is_422, unknown_kind_is_422
     *
     * @return void 반환값 없음
     */
    public function test_kind_outside_selectable_list_is_rejected(): void
    {
        $this->letMembersChoose(['name', 'nickname']);
        $user = $this->member('o1');

        foreach (['masked_name', 'no_such_kind', 'field:ghost'] as $kind) {
            $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => $kind]), $this->as($user))
                ->assertStatus(422)->assertJsonValidationErrors(['display_name_kind'])
                ->assertJsonPath('errors.display_name_kind.0', __('display_name.validation.not_selectable'));
        }
        $this->postJson('/api/auth/register', $this->signupBody('o2', ['display_name_kind' => 'masked_name']))
            ->assertStatus(422)->assertJsonValidationErrors(['display_name_kind']);
        $this->assertNull($user->fresh()->display_name_kind);
    }

    /**
     * 고른 종류가 허용에서 빠진 회원은 회원 기본 종류로 보이고, 항목 API 가 다시 고르라고 알린다. 다른 칸은 그대로 고칠 수 있다.
     *
     * @scenario case=choice_no_longer_selectable
     *
     * @effects removed_choice_reports_value_unavailable_with_previous_label, removed_choice_shows_default_kind, removed_choice_does_not_block_other_edits, removed_field_kind_reports_field_name
     *
     * @return void 반환값 없음
     */
    public function test_choice_removed_from_selectable_list(): void
    {
        $field = $this->displayField('alias');
        $this->letMembersChoose(['name', 'nickname', 'field:alias']);
        $user = $this->member('u1');
        app(FieldValueService::class)->write($user, FieldSet::CORE_USER, ['alias' => '달빛 검객'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));

        $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => 'field:alias']), $this->as($user))->assertOk()->assertJsonPath('data.display_name', '달빛 검객');

        // 운영자가 그 종류를 허용에서 뺐다
        $this->letMembersChoose(['name', 'nickname']);
        $bundle = $this->getJson('/api/me/fields', $this->as($user))->assertOk()->json('data.display_name');
        $this->assertSame([null, true, ['value' => 'field:alias', 'label' => '활동명'], 'name', '홍길동'], [
            $bundle['value'], $bundle['value_unavailable'], $bundle['unavailable_kind'], $bundle['effective_kind'], $bundle['current'],
        ]);
        $this->assertSame('홍길동', $this->getJson('/api/me', $this->as($user))->assertOk()->json('data.display_name'));

        // 종류를 보내지 않으면 다른 칸은 그대로 고칠 수 있다. 저장된 옛 종류는 다시 고를 때까지 남는다.
        $this->putJson('/api/me', $this->profileBody($user, ['bio' => '소개']), $this->as($user))->assertOk();
        $this->assertSame(['소개', 'field:alias'], [$user->fresh()->bio, $user->fresh()->display_name_kind]);

        // 항목의 「표시 이름으로 사용 가능」을 꺼도 전에 고른 종류 이름은 항목 이름으로 보인다
        $field->update(['display_name_usable' => false]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->assertSame('활동명', $this->getJson('/api/me/fields', $this->as($user))->assertOk()->json('data.display_name.unavailable_kind.label'));

        // 다시 고르면 풀린다
        $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => 'nickname']), $this->as($user))->assertOk()->assertJsonPath('data.display_name', '길동이');
        $this->assertFalse($this->getJson('/api/me/fields', $this->as($user))->json('data.display_name.value_unavailable'));
    }

    /**
     * 어떤 영역이 닉네임 종류로 고정이면 닉네임 항목이 사용, 필수로 잠긴다. 운영자 표와 화면 항목 계약, 검증 규칙이 같은 값을 본다.
     *
     * @scenario case=nickname_locked_for_all
     *
     * @effects fixed_nickname_kind_forces_nickname_enabled_and_required, nickname_lock_reason_is_nickname_display, locked_nickname_attributes_cannot_be_changed, stored_nickname_settings_survive_lock
     *
     * @return void 반환값 없음
     */
    public function test_fixed_nickname_kind_locks_the_nickname_field(): void
    {
        $nickname = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'nickname')->firstOrFail();
        $nickname->update(['enabled' => false, 'required' => false]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->assertNull($this->field($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->json('data.fields'), 'nickname'), '꺼 둔 닉네임이 가입 화면에 나왔다');

        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name_nickname']]]);
        $this->assertSame(NicknameRequirement::LEVEL_ALL, app(NicknameRequirement::class)->level());

        // 운영자 표: 사용, 필수로 나가고 두 속성이 잠긴다
        $row = collect($this->getJson('/api/admin/field-sets/core.user/fields', $this->auth())->assertOk()->json('data.fields'))->firstWhere('key', 'nickname');
        $this->assertSame([true, true], [$row['enabled'], $row['required']]);
        $this->assertSame(['enabled' => 'nickname_display', 'required' => 'nickname_display'], $row['lock_reasons']);
        $this->assertSame(__('display_name.locks.nickname_display'), $row['locks']['enabled']);

        // 가입 화면과 검증: 닉네임 필수
        $this->assertTrue($this->field($this->getJson('/api/auth/register/fields', ['Accept-Language' => 'ko'])->json('data.fields'), 'nickname')['required']);
        $this->postJson('/api/auth/register', $this->signupBody('l1', ['nickname' => null]))->assertStatus(422)->assertJsonValidationErrors(['nickname']);

        // 잠긴 속성은 저장되지 않는다. 잠기지 않은 속성은 저장된다.
        $this->putJson("/api/admin/field-sets/core.user/fields/{$nickname->id}", ['enabled' => false, 'required' => false, 'show_on_signup' => true], $this->auth())
            ->assertOk()->assertJsonPath('data.field.enabled', true)->assertJsonPath('data.field.required', true);
        $this->assertSame([false, false], [(bool) $nickname->fresh()->enabled, (bool) $nickname->fresh()->required], '잠긴 동안의 요청이 저장된 값을 바꿨다');

        // 설정을 되돌리면 운영자가 정해 둔 값이 그대로 돌아온다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name']]]);
        $row = collect($this->getJson('/api/admin/field-sets/core.user/fields', $this->auth())->json('data.fields'))->firstWhere('key', 'nickname');
        $this->assertSame([false, false, []], [$row['enabled'], $row['required'], $row['lock_reasons']]);
    }

    /**
     * 「회원이 고른 값」에 닉네임 종류가 있으면 닉네임 사용만 잠기고, 닉네임으로 보이는 회원만 닉네임이 필수다.
     * 영역의 인스턴스(게시판마다 등)가 닉네임 종류로 고정이어도 전원 필수가 된다.
     *
     * @scenario case=nickname_locked_for_chosen
     *
     * @effects selectable_nickname_kind_forces_nickname_enabled_only, default_nickname_kind_requires_nickname_for_unchosen_members, instance_fixed_nickname_kind_locks_for_all
     *
     * @return void 반환값 없음
     */
    public function test_selectable_nickname_kind_locks_only_usage(): void
    {
        $nickname = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'nickname')->firstOrFail();
        $nickname->update(['enabled' => false]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        // 고를 수 있는 종류에 닉네임이 있어도 「회원이 고른 값」 영역이 없으면 요구하지 않는다
        $this->settings(['display_name_selectable_kinds' => ['name', 'nickname']]);
        $this->assertSame(NicknameRequirement::LEVEL_NONE, app(NicknameRequirement::class)->level());

        $this->letMembersChoose(['name', 'nickname']);
        $this->assertSame(NicknameRequirement::LEVEL_CHOSEN, app(NicknameRequirement::class)->level());
        $row = collect($this->getJson('/api/admin/field-sets/core.user/fields', $this->auth())->json('data.fields'))->firstWhere('key', 'nickname');
        $this->assertSame([true, false, ['enabled' => 'nickname_display']], [$row['enabled'], $row['required'], $row['lock_reasons']]);

        // 이름으로 보이는 회원은 닉네임 없이 저장된다
        $user = $this->member('c1', null);
        $this->putJson('/api/me', $this->profileBody($user, ['bio' => '소개']), $this->as($user))->assertOk();

        // 회원 기본 종류가 닉네임이면 고르지 않은 회원도 닉네임이 필수다
        $this->letMembersChoose(['name', 'nickname'], 'nickname');
        $this->putJson('/api/me', $this->profileBody($user, ['bio' => '다시']), $this->as($user))->assertStatus(422)->assertJsonValidationErrors(['nickname']);
        $this->putJson('/api/me', $this->profileBody($user, ['display_name_kind' => 'name']), $this->as($user))->assertOk();

        // 인스턴스가 닉네임 종류로 고정이면 전원 필수
        $this->settings(['display_name_selectable_kinds' => ['name'], 'display_name_member_default_kind' => 'name', 'display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name']]]);
        app(DisplayNameAreaRegistry::class)->register(new DisplayNameArea(
            key: 'demo-board:posts',
            label: ['ko' => '게시판 글'],
            ownerType: 'module',
            ownerIdentifier: 'demo-board',
            usedKindsProvider: fn () => [['mode' => 'fixed', 'kind' => 'nickname', 'label' => '자유게시판']],
        ));
        app(NicknameRequirement::class)->flush();
        $this->assertSame(NicknameRequirement::LEVEL_ALL, app(NicknameRequirement::class)->level());
    }

    /**
     * 표시 이름 구획의 안내: 닉네임이 빈 회원 수, 종류와 영역 목록. 조회 권한이 없으면 403 이다.
     *
     * @scenario case=settings_info_api
     *
     * @effects nickname_empty_count_excludes_withdrawn, display_name_options_list_kinds_and_areas, settings_info_requires_settings_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_settings_info_apis(): void
    {
        $this->member('e1', '있음');
        $this->member('e2', null);
        $gone = $this->member('e3', null);
        $gone->withdraw();

        $count = $this->getJson('/api/admin/member-fields/nickname-empty-count', $this->auth())->assertOk()->json('data');
        // 닉네임이 빈 회원: 최고관리자와 e2 (탈퇴 회원은 세지 않는다)
        $this->assertSame(2, $count['nickname_empty_count']);
        $this->assertSame(['enabled' => true, 'required' => false, 'require_existing_members' => false], $count['nickname']);
        $this->assertSame('none', $count['nickname_requirement']);

        $this->displayField('alias');
        $options = $this->getJson('/api/admin/member-fields/display-name-options', $this->auth())->assertOk()->json('data');
        $kinds = collect($options['kinds'])->keyBy('key');
        $this->assertSame(['name', 'nickname', 'masked_name', 'masked_id', 'name_nickname', 'field:alias'], array_column($options['kinds'], 'key'));
        $this->assertSame(['홍길동', '길동이', '홍*동', 'gil***', '홍길동(길동이)'], $kinds->only(['name', 'nickname', 'masked_name', 'masked_id', 'name_nickname'])->pluck('preview')->values()->all());
        $this->assertSame(['활동명', 'custom', false], [$kinds['field:alias']['label'], $kinds['field:alias']['owner_type'], $kinds['field:alias']['requires_nickname']]);
        $this->assertTrue($kinds['nickname']['requires_nickname']);

        $areas = collect($options['areas'])->keyBy('key');
        $this->assertSame('core:site', $options['areas'][0]['key']);
        $this->assertSame([0, false, 'fixed', 'name'], [$areas['core:site']['depth'], $areas['core:site']['can_inherit'], $areas['core:site']['mode'], $areas['core:site']['kind']]);
        $this->assertSame(['core:notification', 2, 'inherit', 'notification'], [
            $areas['core:notification:mail']['parent'], $areas['core:notification:mail']['depth'], $areas['core:notification:mail']['mode'], $areas['core:notification:mail']['audience'],
        ]);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'name', 'source' => 'core:site'], $areas['core:notification:mail']['effective']);
        $this->assertSame([['name'], 'name'], [$options['selectable_kinds'], $options['member_default_kind']]);

        $member = $this->member('e4');
        $this->getJson('/api/admin/member-fields/nickname-empty-count', $this->as($member))->assertStatus(403);
        $this->getJson('/api/admin/member-fields/display-name-options', $this->as($member))->assertStatus(403);
    }

    /**
     * 회원 탭 저장: 표시 이름 값은 종류, 영역 등록소로 검증하고, 「로그인 시 닉네임 입력 요구」를 켜면 닉네임 항목의
     * 「기존 회원에게도 입력 요구」가 함께 켜진다. 지시 값은 저장하지 않고, 화면이 보내지 않은 영역의 기존 값은 남으며, 이름이 구워진 캐시를 비운다.
     *
     * @scenario case=settings_save
     *
     * @effects settings_save_validates_kinds_and_areas, settings_save_turns_on_nickname_require_existing_members, settings_save_drops_transient_key, settings_save_preserves_unregistered_area_settings, settings_save_clears_seo_cache
     *
     * @return void 반환값 없음
     */
    public function test_member_tab_save(): void
    {
        $seo = Mockery::mock(SeoCacheManagerInterface::class);
        $seo->shouldReceive('clearAll')->once();
        $this->app->instance(SeoCacheManagerInterface::class, $seo);

        $save = fn (array $member) => $this->postJson('/api/admin/settings', ['_tab' => 'member', 'member' => $member], $this->auth());

        // 없는 종류, 없는 영역, 고정인데 종류 없음, 기본 종류가 허용 목록 밖, 사이트 기본 영역의 따름
        $save(['display_name_selectable_kinds' => ['name', 'no_such_kind']])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_selectable_kinds.1']);
        $save(['display_name_selectable_kinds' => ['name'], 'display_name_member_default_kind' => 'nickname'])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_member_default_kind']);
        $save(['display_name_areas' => ['ghost:area' => ['mode' => 'fixed', 'kind' => 'name']]])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_areas']);
        $save(['display_name_areas' => ['core:admin' => ['mode' => 'fixed']]])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_areas']);
        $save(['display_name_areas' => ['core:site' => ['mode' => 'inherit']]])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_areas']);

        // 꺼진 확장의 영역 값이 저장 파일에 남아 있다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'gone-extension:reviews' => ['mode' => 'fixed', 'kind' => 'masked_name']]]);

        $nickname = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'nickname')->firstOrFail();
        $this->assertFalse((bool) $nickname->require_existing_members);

        $save([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name'], 'core:notification' => ['mode' => 'inherit', 'kind' => 'name']],
            'nickname_require_existing_members' => true,
        ])->assertOk();

        $stored = app(ConfigRepositoryInterface::class)->getCategory('member');
        $this->assertArrayNotHasKey('nickname_require_existing_members', $stored, '지시 값이 설정으로 저장됐다');
        $this->assertSame(['name', 'nickname'], $stored['display_name_selectable_kinds']);
        $this->assertSame([
            'gone-extension:reviews' => ['mode' => 'fixed', 'kind' => 'masked_name'],
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:notification' => ['mode' => 'inherit'],
        ], $stored['display_name_areas']);
        $this->assertTrue((bool) $nickname->fresh()->require_existing_members, '닉네임 항목의 「기존 회원에게도 입력 요구」가 켜지지 않았다');
    }

    /**
     * 표시 이름 종류로 쓰이는 추가 항목은 삭제가 409 로 막히고 사용처 목록이 온다. 쓰이지 않게 되면 지워진다.
     *
     * @scenario case=delete_blocked
     *
     * @effects field_used_as_display_name_cannot_be_deleted, delete_conflict_lists_usages, unused_field_is_deleted
     *
     * @return void 반환값 없음
     */
    public function test_field_used_as_display_name_cannot_be_deleted(): void
    {
        $field = $this->displayField('alias');
        $this->settings([
            'display_name_selectable_kinds' => ['name', 'field:alias'],
            'display_name_member_default_kind' => 'field:alias',
            'display_name_areas' => ['core:site' => ['mode' => 'member'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'field:alias']],
        ]);
        app(DisplayNameAreaRegistry::class)->register(new DisplayNameArea(
            key: 'demo-board:posts',
            label: ['ko' => '게시판 글'],
            ownerType: 'module',
            ownerIdentifier: 'demo-board',
            usedKindsProvider: fn () => [['mode' => 'fixed', 'kind' => 'field:alias', 'label' => '자유게시판'], ['mode' => 'fixed', 'kind' => 'name', 'label' => '공지']],
        ));

        $response = $this->deleteJson("/api/admin/field-sets/core.user/fields/{$field->id}", [], $this->auth());
        $response->assertStatus(409)->assertJsonPath('error_code', 'field_in_use');
        $this->assertSame([
            ['type' => 'selectable_kind', 'label' => __('display_name.usages.selectable_kind'), 'area' => null],
            ['type' => 'member_default_kind', 'label' => __('display_name.usages.member_default_kind'), 'area' => null],
            ['type' => 'area', 'label' => __('display_name.usages.area', ['area' => __('display_name.areas.admin')]), 'area' => 'core:admin'],
            ['type' => 'area_instance', 'label' => '게시판 글: 자유게시판', 'area' => 'demo-board:posts'],
        ], $response->json('data.usages'));
        // 화면은 같은 목록을 오류 본문에서 읽는다
        $this->assertSame($response->json('data.usages'), $response->json('errors.usages'));
        $this->assertDatabaseHas('field_definitions', ['id' => $field->id]);

        // 다른 추가 항목은 영향이 없다
        $other = $this->displayField('motto');
        $this->deleteJson("/api/admin/field-sets/core.user/fields/{$other->id}", [], $this->auth())->assertOk();

        // 쓰이지 않게 하면 지워진다
        $this->settings([
            'display_name_selectable_kinds' => ['name'], 'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name']],
        ]);
        app(DisplayNameAreaRegistry::class)->flush();
        $this->deleteJson("/api/admin/field-sets/core.user/fields/{$field->id}", [], $this->auth())->assertOk();
        $this->assertDatabaseMissing('field_definitions', ['id' => $field->id]);
    }

    /**
     * 새 권한은 기본 `user` 역할에 붙고, 운영자가 `user` 역할의 다른 권한을 고친 사이트에서도 선언 재동기화로 붙는다.
     * 운영자가 뗀 권한은 다시 붙지 않는다.
     *
     * @scenario case=role_permission_sync
     *
     * @effects choose_permission_granted_to_user_role_by_default, choose_permission_attached_on_resync_for_customized_user_role, operator_removed_permission_stays_removed
     *
     * @return void 반환값 없음
     */
    public function test_choose_permission_reaches_customized_user_role(): void
    {
        $role = Role::where('identifier', 'user')->firstOrFail();
        $choose = Permission::where('identifier', DisplayNameChoicePolicy::PERMISSION)->firstOrFail();
        $this->assertSame('user', $choose->type instanceof \BackedEnum ? $choose->type->value : $choose->type);
        $this->assertTrue($role->permissions()->where('permissions.id', $choose->id)->exists(), '기본 user 역할에 새 권한이 없다');
        $this->assertTrue(app(DisplayNameChoicePolicy::class)->permittedForSignup());

        // 이 권한이 생기기 전의 사이트: 권한 행이 없고, 운영자가 user 역할에서 「내 정보 수정」을 떼어 두었다
        $role->permissions()->detach($choose->id);
        $choose->delete();
        $profileUpdate = Permission::where('identifier', 'core.profile.update')->firstOrFail();
        $role->permissions()->detach($profileUpdate->id);
        $role->forceFill(['user_overrides' => ['core.profile.update']])->save();
        $this->assertFalse(app(DisplayNameChoicePolicy::class)->permittedForSignup());

        app(CoreUpdateService::class)->syncCoreRolesAndPermissions();

        $role = $role->fresh();
        $this->assertTrue($role->permissions()->where('identifier', DisplayNameChoicePolicy::PERMISSION)->exists(), '운영자가 고친 user 역할에 새 권한이 붙지 않았다');
        $this->assertFalse($role->permissions()->where('identifier', 'core.profile.update')->exists(), '운영자가 뗀 권한이 다시 붙었다');
        $this->assertTrue(app(DisplayNameChoicePolicy::class)->permittedForSignup());

        // 사용을 끈 역할의 권한은 가입 화면 판정에 쓰이지 않는다
        $role->forceFill(['is_active' => false])->save();
        $this->assertFalse(app(DisplayNameChoicePolicy::class)->permittedForSignup());
    }

    /**
     * 회원 응답의 일곱 모양이 `display_name` 을 싣고, 본인 응답과 관리자 상세가 고른 종류와 선택 가능 여부를 싣는다.
     * 관리자 화면의 본인 이름은 관리자 영역의 값이고, 관리자 회원 폼은 회원의 표시 이름 상태를 읽기 전용으로 받는다.
     *
     * @scenario case=response_shapes
     *
     * @effects all_user_resource_shapes_carry_display_name, self_and_admin_detail_carry_kind_and_can_choose, admin_auth_uses_admin_area, admin_member_status_reports_chosen_kind_and_shown_names, list_rows_carry_display_name_without_per_row_queries
     *
     * @return void 반환값 없음
     */
    public function test_response_shapes_carry_display_name(): void
    {
        $this->displayField('alias');
        $this->settings([
            'display_name_selectable_kinds' => ['name', 'nickname', 'field:alias'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'member'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name_nickname']],
        ]);
        $user = $this->member('r1');
        $user->forceFill(['display_name_kind' => 'nickname'])->save();
        $user = $user->fresh(['roles.permissions']);
        $this->actingAs($user);
        $resource = new UserResource($user);

        $shapes = [
            'toArray' => $resource->resolve(request()),
            'toAuthArray' => $resource->toAuthArray(),
            'toListArray' => $resource->toListArray(),
            'toProfileArray' => $resource->toProfileArray(),
            'withAdminInfo' => $resource->withAdminInfo(),
            'toStatisticsArray' => $resource->toStatisticsArray(),
        ];
        foreach ($shapes as $shape => $data) {
            $this->assertSame('길동이', $data['display_name'], "{$shape} 의 display_name");
            $this->assertSame('홍길동', $data['name'], "{$shape} 의 name 이 바뀌었다");
        }
        // 관리자 화면의 본인 응답은 관리자 영역의 표시 이름
        $this->assertSame('홍길동(길동이)', $resource->toAdminAuthArray()['display_name']);

        foreach (['toAuthArray', 'toProfileArray', 'withAdminInfo'] as $shape) {
            $this->assertSame(['nickname', true], [$shapes[$shape]['display_name_kind'], $shapes[$shape]['can_choose_display_name']], $shape);
        }
        $this->assertSame(['nickname', true], [$resource->toAdminAuthArray()['display_name_kind'], $resource->toAdminAuthArray()['can_choose_display_name']]);
        foreach (['toArray', 'toListArray', 'toStatisticsArray'] as $shape) {
            $this->assertArrayNotHasKey('can_choose_display_name', $shapes[$shape], "{$shape} 에 선택 가능 여부가 실렸다");
        }

        // 관리자 회원 폼의 회원 상태(읽기 전용)
        $status = $this->getJson("/api/admin/users/{$user->uuid}/form-fields", $this->auth())->assertOk()->json('data.member');
        $this->assertSame(['nickname', '닉네임', false, 'nickname', '닉네임', '길동이', '홍길동(길동이)'], [
            $status['display_name_kind'], $status['display_name_kind_label'], $status['display_name_kind_unavailable'],
            $status['display_name_effective_kind'], $status['display_name_effective_kind_label'], $status['display_name'], $status['display_name_admin'],
        ]);

        // 목록 행: 추가 항목 종류를 고른 회원이 여럿이어도 값은 쿼리 한 번
        foreach (['r2', 'r3', 'r4'] as $index => $local) {
            $other = $this->member($local, '닉'.$index);
            $other->forceFill(['display_name_kind' => 'field:alias'])->save();
            app(FieldValueService::class)->write($other, FieldSet::CORE_USER, ['alias' => '활동명'.$index], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $other));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = collect($this->getJson('/api/admin/users?per_page=50', $this->auth())->assertOk()->json('data.data'))->keyBy('email');
        $fieldValueQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();

        $this->assertSame(['길동이', '활동명0', '활동명1', '활동명2', '관리자'], [
            $rows['r1@example.com']['display_name'], $rows['r2@example.com']['display_name'], $rows['r3@example.com']['display_name'],
            $rows['r4@example.com']['display_name'], $rows['boss@example.com']['display_name'],
        ]);
        $this->assertLessThanOrEqual(1, count($fieldValueQueries), '목록이 추가 항목 값을 회원마다 읽었다');
    }

    /**
     * 공개 설정에는 회원이 고를 수 있는 종류와 회원 기본 종류만 나가고, 영역별 설정은 나가지 않는다.
     *
     * @scenario case=public_settings
     *
     * @effects public_config_exposes_selectable_kinds_only, public_config_hides_area_settings
     *
     * @return void 반환값 없음
     */
    public function test_public_config_hides_area_settings(): void
    {
        $member = $this->getJson('/api/frontend-config')->assertOk()->json('data.settings.member') ?? [];

        $this->assertArrayNotHasKey('display_name_areas', $member);
        $this->assertArrayNotHasKey('nickname_banned_words', $member);
        $this->assertSame(['display_name_member_default_kind', 'display_name_selectable_kinds', 'nickname_change_interval_days'], collect(array_keys($member))->sort()->values()->all());
    }
}
