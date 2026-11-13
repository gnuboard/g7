<?php

namespace Tests\Feature\User;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldValueService;
use App\Models\ActivityLog;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\Role;
use App\Models\User;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use App\Services\UserService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원 항목 값의 저장·열람·파기 — 관리자 추가·수정, 내 정보 수정, 응답의 `custom_fields`, 회원 삭제와 탈퇴 파기.
 */
class UserFieldValuesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    /** @var array<int, array{0: array<string, mixed>, 1: string}> 항목 값 변경 훅이 받은 [변경 내역, 표면] */
    private array $fieldUpdates = [];

    /**
     * 역할과 코어 회원 항목을 시딩하고, 최고관리자와 항목 값 변경 훅 기록을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->admin = $this->admin->fresh();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        HookManager::addAction('core.user.fields.after_update', function (User $user, array $changes, string $surface) {
            $this->fieldUpdates[] = [$changes, $surface];
        });
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.user.fields.after_update');
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
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
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
     * 관리자 회원 추가 — 추가 항목 값이 회원과 함께 저장되고, 필수 항목은 관리자 전용 범위라도 요구된다.
     *
     * @scenario surface=admin_create
     *
     * @effects admin_create_stores_custom_field_values
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_stores_custom_fields(): void
    {
        $job = $this->custom('job', 'text');
        $grade = $this->custom('grade', 'text', ['edit_scope' => 'admin_only', 'required' => true]);

        $body = [
            'name' => '새 회원', 'email' => 'issue490+admincreate@example.com',
            'password' => 'Str0ng!Passw0rd#490', 'password_confirmation' => 'Str0ng!Passw0rd#490',
        ];

        $this->postJson('/api/admin/users', $body, $this->auth())->assertStatus(422)->assertJsonValidationErrors(['custom_fields.grade']);
        $this->assertDatabaseMissing('users', ['email' => $body['email']]);

        $this->postJson('/api/admin/users', $body + ['custom_fields' => ['job' => '개발자', 'grade' => 'A']], $this->auth())->assertStatus(201);

        $user = User::where('email', $body['email'])->firstOrFail();
        $this->assertSame('개발자', FieldValue::where('owner_id', $user->id)->where('field_definition_id', $job->id)->value('value_text'));
        $this->assertSame('A', FieldValue::where('owner_id', $user->id)->where('field_definition_id', $grade->id)->value('value_text'));
    }

    /**
     * 관리자 회원 수정 — 추가 항목 값이 바뀌고, 값이 비어 있던 필수 항목은 요구하지 않으며, 변경 훅과 활동 로그가 남는다.
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_stores_values_and_relaxes_empty_required, field_value_change_fires_hook_and_logs_activity
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_stores_values_fires_hook_and_logs(): void
    {
        $this->custom('job', 'text', ['required' => true]);
        $this->custom('grade', 'text', ['edit_scope' => 'admin_only']);
        $target = User::factory()->create(['name' => '대상 회원']);
        $base = ['name' => '대상 회원', 'email' => $target->email, 'role_ids' => $this->userRoleIds()];

        // 「직업」이 새로 필수가 됐지만 이 회원은 값이 비어 있었다 — 다른 칸만 고쳐도 저장된다.
        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['bio' => '소개 수정'], $this->auth())->assertOk();
        $this->assertSame('소개 수정', $target->fresh()->bio);
        $this->assertSame([], $this->fieldUpdates, '항목 값을 보내지 않았는데 변경 훅이 돌았다');

        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['custom_fields' => ['job' => '디자이너', 'grade' => 'S']], $this->auth())->assertOk();

        $values = app(FieldValueService::class)->read($target, 'core.user');
        $this->assertSame('디자이너', $values['job']);
        $this->assertSame('S', $values['grade']);

        $this->assertCount(1, $this->fieldUpdates);
        [$changes, $surface] = $this->fieldUpdates[0];
        $this->assertSame('admin_update', $surface);
        $this->assertSame(['old' => null, 'new' => '디자이너'], $changes['job']);

        // 값이 있던 필수 항목은 비울 수 없다.
        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['custom_fields' => ['job' => '']], $this->auth())
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.job']);

        // 활동 로그 — 관리자만 열람 항목의 값은 가려서 남긴다.
        $log = ActivityLog::where('action', 'user.fields_update')->latest('id')->first();
        $this->assertNotNull($log, '추가 항목 변경이 활동 로그에 남지 않았다');
        $logged = collect($log->changes)->keyBy('field');
        $this->assertSame('디자이너', $logged['fields.job']['new']);
        $this->assertSame('***', $logged['fields.grade']['new'], '관리자만 열람 항목의 값이 활동 로그에 그대로 남았다');
    }

    /**
     * 내 정보 수정 — 본인이 고칠 수 있는 항목만 저장되고, 관리자만 수정·열람 항목은 보내도 버린다. 응답에도 관리자 전용 값은 없다.
     *
     * @scenario surface=self_update
     *
     * @effects self_update_stores_only_self_scope_fields, profile_response_hides_admin_only_values
     *
     * @return void 반환값 없음
     */
    public function test_self_update_only_touches_self_scope_fields(): void
    {
        $this->custom('job', 'text');
        $this->custom('memo', 'text', ['edit_scope' => 'admin_edit']);
        $this->custom('grade', 'text', ['edit_scope' => 'admin_only']);

        $member = User::factory()->create(['name' => '본인']);
        $member->roles()->attach($this->userRoleIds()[0], ['assigned_at' => now(), 'assigned_by' => null]);
        $token = $member->fresh()->createToken('test')->plainTextToken;

        // 관리자가 넣어 둔 관리자 전용 값.
        app(FieldValueService::class)->write($member, 'core.user', ['memo' => '관리자 메모', 'grade' => 'A'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $member));

        foreach (['/api/me', '/api/user/profile'] as $index => $uri) {
            $this->putJson($uri, [
                'name' => '본인', 'email' => $member->email,
                'custom_fields' => ['job' => '직업'.$index, 'memo' => '내가 바꿈', 'grade' => 'Z'],
            ], $this->auth($token))->assertOk();
        }

        $values = app(FieldValueService::class)->read($member, 'core.user');
        $this->assertSame('직업1', $values['job'], '두 수정 경로 가운데 하나가 추가 항목을 저장하지 않았다');
        $this->assertSame('관리자 메모', $values['memo'], '관리자만 수정 항목을 본인이 바꿨다');
        $this->assertSame('A', $values['grade'], '관리자만 열람 항목을 본인이 바꿨다');
        $this->assertSame('self_update', $this->fieldUpdates[0][1]);

        $profile = $this->getJson('/api/me', $this->auth($token))->assertOk()->json('data.custom_fields');
        $this->assertSame('직업1', $profile['job']);
        $this->assertSame('관리자 메모', $profile['memo'], '관리자만 수정 항목은 본인이 볼 수 있어야 한다');
        $this->assertArrayNotHasKey('grade', $profile, '관리자만 열람 항목이 본인 응답에 실렸다');

        $detail = $this->getJson("/api/admin/users/{$member->uuid}", $this->auth())->assertOk()->json('data.custom_fields');
        $this->assertSame('A', $detail['grade'], '관리자 상세에 관리자만 열람 항목이 없다');
    }

    /**
     * 추가 항목이 없는 사이트의 응답은 빈 `custom_fields` 를 싣고, 기존 수정 요청은 그대로 저장된다.
     *
     * @scenario surface=self_update
     *
     * @effects profile_update_unchanged_without_custom_fields
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_without_custom_fields_is_unchanged(): void
    {
        $member = User::factory()->create(['name' => '본인']);
        $member->roles()->attach($this->userRoleIds()[0], ['assigned_at' => now(), 'assigned_by' => null]);
        $token = $member->fresh()->createToken('test')->plainTextToken;

        $this->putJson('/api/me', [
            'name' => '바꾼 이름', 'email' => $member->email, 'nickname' => '별명', 'mobile' => '010-1234-5678',
            'zipcode' => '04524', 'address' => '서울', 'address_detail' => '3층', 'homepage' => 'https://example.com',
        ], $this->auth($token))->assertOk();

        $fresh = $member->fresh();
        $this->assertSame(['바꾼 이름', '별명', '010-1234-5678', '04524', '서울', '3층', 'https://example.com'], [
            $fresh->name, $fresh->nickname, $fresh->mobile, $fresh->zipcode, $fresh->address, $fresh->address_detail, $fresh->homepage,
        ]);
        $this->assertSame([], (array) $this->getJson('/api/me', $this->auth($token))->json('data.custom_fields'));
        $this->assertSame([], $this->fieldUpdates);
    }

    /**
     * 서비스를 직접 부르는 수정(SSO 동기화 등)은 관리자 표면으로 저장하고, 본인 표면을 지정하면 본인 범위만 저장한다.
     *
     * @scenario surface=admin_update
     *
     * @effects update_service_surface_option
     *
     * @return void 반환값 없음
     */
    public function test_update_service_surface_option(): void
    {
        $this->custom('memo', 'text', ['edit_scope' => 'admin_edit']);
        $member = User::factory()->create();
        $service = app(UserService::class);
        $this->actingAs($this->admin);

        $service->updateUser($member, ['custom_fields' => ['memo' => '본인 표면']], ['field_surface' => 'self_update']);
        $this->assertNull(app(FieldValueService::class)->read($member, 'core.user')['memo']);

        $service->updateUser($member, ['custom_fields' => ['memo' => '기본 표면']]);
        $this->assertSame('기본 표면', app(FieldValueService::class)->read($member, 'core.user')['memo']);
    }

    /**
     * 관리자 회원 목록 — 「회원 검색」을 켠 추가 항목으로 찾고, 「회원 목록 열」을 켠 항목의 표시 값이 행에 실린다.
     *
     * @scenario surface=admin_list
     *
     * @effects admin_list_searches_and_shows_custom_fields
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_search_and_columns_for_custom_fields(): void
    {
        $options = [['value' => 'dev', 'label' => ['ko' => '개발자']], ['value' => 'pm', 'label' => ['ko' => '기획자']]];
        $this->custom('job', 'choice', ['options' => $options, 'admin_list_column' => true, 'admin_searchable' => true]);
        $this->custom('company', 'text', ['admin_list_column' => true, 'admin_searchable' => true]);
        $this->custom('secret', 'text', ['admin_searchable' => false]);

        $values = app(FieldValueService::class);
        [$a, $b] = User::factory()->count(2)->create()->all();
        $values->write($a, 'core.user', ['job' => 'dev', 'company' => 'Acme Corp', 'secret' => '비밀'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $a));
        $values->write($b, 'core.user', ['job' => 'pm', 'company' => '다른 회사'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $b));

        $search = fn (string $field, string $value) => $this->getJson(
            '/api/admin/users?'.http_build_query(['filters' => [['field' => $field, 'value' => $value]], 'per_page' => 100]),
            $this->auth(),
        );

        // 글 항목은 대소문자를 가리지 않고 포함 검색, 선택 항목은 선택지 값으로 찾는다.
        $byCompany = $search('custom:company', 'acme')->assertOk()->json('data.data');
        $this->assertSame([$a->uuid], array_column($byCompany, 'uuid'));
        $this->assertSame([$b->uuid], array_column($search('custom:job', 'pm')->assertOk()->json('data.data'), 'uuid'));

        // 행에는 목록 열로 켠 항목만 보기용 문자열로 실린다.
        $this->assertSame(['job' => '개발자', 'company' => 'Acme Corp'], $byCompany[0]['custom_fields']);

        // 「회원 검색」을 켜지 않은 항목과 없는 항목은 검색 대상으로 고를 수 없다.
        $search('custom:secret', '비밀')->assertStatus(422);
        $search('custom:nope', 'x')->assertStatus(422);

        // 값 조회는 회원 수와 무관하게 쿼리 1회다.
        User::factory()->count(5)->create();
        \DB::enableQueryLog();
        $this->getJson('/api/admin/users?per_page=100', $this->auth())->assertOk();
        $valueQueries = array_filter(\DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'field_values'));
        \DB::disableQueryLog();
        $this->assertCount(1, $valueQueries, '목록의 추가 항목 값 조회가 회원 수만큼 늘었다');
    }

    /**
     * 회원 삭제와 탈퇴 회원 2차 파기가 추가 항목 값을 함께 지운다.
     *
     * @scenario surface=admin_update
     *
     * @effects user_delete_and_retention_purge_field_values
     *
     * @return void 반환값 없음
     */
    public function test_delete_and_retention_purge_remove_field_values(): void
    {
        $this->custom('job', 'text');
        $values = app(FieldValueService::class);
        [$deleted, $withdrawn, $kept] = User::factory()->count(3)->create()->all();
        foreach ([$deleted, $withdrawn, $kept] as $user) {
            $values->write($user, 'core.user', ['job' => '직업'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        }
        $this->assertSame(3, FieldValue::count());

        $this->actingAs($this->admin);
        app(UserService::class)->deleteUser($deleted);
        $this->assertSame(0, FieldValue::where('owner_id', $deleted->id)->count(), '삭제한 회원의 항목 값이 남았다');

        // 탈퇴 뒤 보유기간이 지난 회원의 2차 파기.
        config(['g7_settings.core.privacy.withdrawn_retention_enabled' => true, 'g7_settings.core.privacy.withdrawn_retention_days' => 1]);
        $withdrawn->forceFill(['status' => 'withdrawn', 'withdrawn_at' => now()->subDays(10)])->save();
        $result = app(WithdrawnUserRetentionHandler::class)->process(false, true);

        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertSame(0, FieldValue::where('owner_id', $withdrawn->id)->count(), '2차 파기가 항목 값을 지우지 않았다');
        $this->assertSame(1, FieldValue::where('owner_id', $kept->id)->count(), '다른 회원의 항목 값이 지워졌다');
    }
}
