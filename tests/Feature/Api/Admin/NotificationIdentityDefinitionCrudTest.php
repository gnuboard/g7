<?php

namespace Tests\Feature\Api\Admin;

use App\Models\IdentityPolicy;
use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 알림 설정 > 본인인증 「정책별 문구」 추가·삭제·목록 API 테스트 (#699)
 *
 * 7.0.x 의 본인인증 메시지 전용 API(`/api/admin/identity/messages/*`)를 알림 정의 API 로 옮겼다.
 * 옛 API 테스트(AdminIdentityMessageDefinitionAdminCrudTest)가 지키던 살아 있는 계약 —
 * 운영자 정책 키만·중복 거부·선언 정의 삭제 거부·권한·abilities — 을 새 경로에서 잠근다.
 */
class NotificationIdentityDefinitionCrudTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    /**
     * 권한·알림 정의를 시딩하고 슈퍼 관리자 토큰을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);
        app(NotificationDefinitionService::class)->invalidateAllCache();

        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $admin->createToken('t')->plainTextToken;
    }

    /**
     * 운영자 정책에 문구를 추가하면 규칙 type·core/admin 소유·메일 템플릿으로 만들어진다.
     *
     * @scenario surface=admin_api
     *
     * @effects store_creates_operator_policy_definition
     *
     * @return void 반환값 없음
     */
    public function test_store_creates_operator_policy_definition(): void
    {
        $this->makeAdminPolicy('admin.board.delete_confirm');

        $response = $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.board.delete_confirm'));

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'identity.policy.admin.board.delete_confirm');
        $response->assertJsonPath('data.category', 'identity');
        $response->assertJsonPath('data.extension_identifier', 'admin');
        $response->assertJsonPath('data.is_default', false);
        $response->assertJsonPath('data.abilities.can_delete', true);

        $definition = NotificationDefinition::where('type', 'identity.policy.admin.board.delete_confirm')->firstOrFail();
        $this->assertSame('core', $definition->extension_type);
        $this->assertSame(['mail'], $definition->channels);
        $this->assertSame('[정책] 게시글 삭제 확인 {code}', $definition->templates()->where('channel', 'mail')->first()->subject['ko']);
    }

    /**
     * 정책 키가 100자 type 을 넘기면 해시 type 으로 저장되고, 목록은 그 행에 원래 정책 키를 실어 준다.
     *
     * @scenario surface=admin_api
     *
     * @effects long_policy_key_hashed_within_column
     *
     * @return void 반환값 없음
     */
    public function test_store_hashes_long_policy_key(): void
    {
        $key = 'admin.'.str_repeat('x', 100);
        $this->makeAdminPolicy($key);

        $this->api()->postJson('/api/admin/notification-definitions', $this->payload($key))->assertStatus(201);

        $this->assertDatabaseHas('notification_definitions', ['type' => 'identity.policy.~'.substr(sha1($key), 0, 20)]);

        // 화면은 해시를 되돌릴 수 없으므로 목록이 정책 키를 실어 준다 — 「이미 문구가 있는 정책」 판정에 쓴다
        $this->makeAdminPolicy('admin.short');
        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.short'))->assertStatus(201);
        $rows = collect($this->api()->getJson('/api/admin/notification-definitions?per_page=100&extension_type=core&category=identity')
            ->assertStatus(200)->json('data.data'))->keyBy('type');
        $this->assertSame($key, $rows['identity.policy.~'.substr(sha1($key), 0, 20)]['policy_key']);
        $this->assertSame('admin.short', $rows['identity.policy.admin.short']['policy_key']);
        $this->assertNull($rows['identity.purpose.signup']['policy_key']);
    }

    /**
     * 코어·확장 정책 키·없는 키·다른 분류·중복은 422 로 거부된다.
     *
     * @scenario surface=admin_api
     *
     * @effects store_rejects_invalid_policy
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_invalid_requests(): void
    {
        IdentityPolicy::create($this->policyAttributes('core.only.policy', 'core'));
        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('core.only.policy'))
            ->assertStatus(422)->assertJsonValidationErrors(['policy_key']);

        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('not.a.policy'))
            ->assertStatus(422)->assertJsonValidationErrors(['policy_key']);

        $this->makeAdminPolicy('admin.dup');
        $this->api()->postJson('/api/admin/notification-definitions', array_merge($this->payload('admin.dup'), ['category' => 'general']))
            ->assertStatus(422)->assertJsonValidationErrors(['category']);

        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.dup'))->assertStatus(201);
        $duplicate = $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.dup'));
        $duplicate->assertStatus(422);
        $this->assertSame(
            __('validation.identity_notification.definition_already_exists'),
            $duplicate->json('errors.policy_key.0')
        );

        $empty = $this->payload('admin.dup2');
        $this->makeAdminPolicy('admin.dup2');
        $empty['templates']['mail']['body'] = ['ko' => '', 'en' => ''];
        $this->api()->postJson('/api/admin/notification-definitions', $empty)
            ->assertStatus(422)->assertJsonValidationErrors(['templates.mail.body']);
    }

    /**
     * 운영자 정의는 삭제되고, 선언 정의·일반 알림은 403 이다.
     *
     * @scenario surface=admin_api
     *
     * @effects destroy_only_operator_definitions
     *
     * @return void 반환값 없음
     */
    public function test_destroy_only_operator_definitions(): void
    {
        $this->makeAdminPolicy('admin.to.delete');
        $id = $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.to.delete'))->json('data.id');

        $this->api()->deleteJson("/api/admin/notification-definitions/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('notification_definitions', ['id' => $id]);
        $this->assertDatabaseMissing('notification_templates', ['definition_id' => $id]);

        $seeded = NotificationDefinition::where('type', 'identity.purpose.signup')->value('id');
        $general = NotificationDefinition::where('type', 'welcome')->value('id');
        $this->api()->deleteJson("/api/admin/notification-definitions/{$seeded}")->assertStatus(403);
        $this->api()->deleteJson("/api/admin/notification-definitions/{$general}")->assertStatus(403);
        $this->api()->deleteJson('/api/admin/notification-definitions/999999')->assertStatus(404);
    }

    /**
     * 목록 행에서도 운영자가 추가한 정책별 문구는 can_delete=true 다 — 화면의 [삭제] 단추는 목록 행의 값으로 그린다.
     *
     * 컬렉션은 항목을 이미 NotificationDefinitionResource 로 감싼 채 넘기므로, 행 판정이 감싼 리소스를
     * 모델로 오인하지 않아야 한다(2026-10-06 실측: 상세 API 는 true, 목록 행은 false 라 [삭제] 가 안 보였다).
     *
     * @scenario surface=admin_api
     *
     * @effects list_row_operator_definition_deletable
     *
     * @return void 반환값 없음
     */
    public function test_list_row_of_operator_definition_is_deletable(): void
    {
        $this->makeAdminPolicy('admin.list.deletable');
        $id = $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.list.deletable'))
            ->assertStatus(201)->json('data.id');

        $rows = collect($this->api()->getJson('/api/admin/notification-definitions?per_page=100&extension_type=core&category=identity')
            ->assertStatus(200)->json('data.data'));

        $operator = $rows->firstWhere('id', $id);
        $this->assertNotNull($operator);
        $this->assertTrue($operator['abilities']['can_delete'], '운영자 정책별 문구 행은 [삭제] 가 열려야 한다');
        $rows->reject(fn ($row) => $row['id'] === $id)
            ->each(fn ($row) => $this->assertFalse($row['abilities']['can_delete'], "선언 정의 {$row['type']} 는 삭제 불가여야 한다"));
    }

    /**
     * 분류 필터 — identity 는 본인인증만, general 은 일반 알림만, extension_type=core 는 운영자 정의까지 담는다.
     *
     * @scenario surface=admin_api
     *
     * @effects index_category_filter
     *
     * @return void 반환값 없음
     */
    public function test_index_category_filter(): void
    {
        $this->makeAdminPolicy('admin.listed');
        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.listed'))->assertStatus(201);

        $identity = $this->api()->getJson('/api/admin/notification-definitions?per_page=100&extension_type=core&category=identity')
            ->assertStatus(200)->json('data.data');
        $types = array_column($identity, 'type');
        $this->assertContains('identity.purpose.login', $types);
        $this->assertContains('identity.policy.admin.listed', $types);
        $this->assertSame(['identity'], array_values(array_unique(array_column($identity, 'category'))));
        $this->assertCount(7, $identity, '코어 선언 6 + 운영자 1');

        $general = $this->api()->getJson('/api/admin/notification-definitions?per_page=100&extension_type=core&category=general')
            ->assertStatus(200)->json('data.data');
        $this->assertNotContains('identity.purpose.login', array_column($general, 'type'));
        $this->assertContains('welcome', array_column($general, 'type'));

        $this->api()->getJson('/api/admin/notification-definitions?category=bogus')->assertStatus(422);
    }

    /**
     * 컬렉션은 can_create, 선언 정의 행은 can_delete=false 를 싣는다. 조회 전용 관리자는 둘 다 false·POST 403.
     *
     * @scenario surface=admin_api
     *
     * @effects abilities_and_permissions
     *
     * @return void 반환값 없음
     */
    public function test_abilities_and_permissions(): void
    {
        $response = $this->api()->getJson('/api/admin/notification-definitions?extension_type=core&category=identity');
        $response->assertJsonPath('data.abilities.can_create', true);
        foreach ($response->json('data.data') as $row) {
            $this->assertFalse($row['abilities']['can_delete'], "선언 정의 {$row['type']} 는 삭제 불가여야 한다");
        }

        $viewerRole = Role::create([
            'identifier' => 'idv699-settings-viewer',
            'name' => ['ko' => '설정 조회 전용', 'en' => 'Settings viewer'],
            'description' => ['ko' => '설정 조회만', 'en' => 'Read only'],
            'is_system' => false,
        ]);
        $viewerRole->permissions()->attach(Permission::where('identifier', 'core.settings.read')->value('id'));
        $viewer = User::factory()->create(['is_super' => false]);
        $viewer->roles()->attach($viewerRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        // 같은 테스트 안에서 앞 요청의 관리자 인증이 가드에 남지 않게 비운다.
        $this->app['auth']->forgetGuards();
        $viewerApi = $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('v')->plainTextToken, 'Accept' => 'application/json']);

        $list = $viewerApi->getJson('/api/admin/notification-definitions?extension_type=core&category=identity');
        $list->assertStatus(200)->assertJsonPath('data.abilities.can_create', false);

        $this->makeAdminPolicy('admin.viewer.denied');
        $viewerApi->postJson('/api/admin/notification-definitions', $this->payload('admin.viewer.denied'))->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->getJson('/api/admin/notification-definitions')->assertStatus(401);
    }

    /**
     * 운영자 정의는 코어 알림 정의 재시딩(업데이트·언어팩 활성화)에서 지워지지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects operator_definition_survives_reseed
     *
     * @return void 반환값 없음
     */
    public function test_operator_definition_survives_reseed(): void
    {
        $this->makeAdminPolicy('admin.survivor');
        $this->api()->postJson('/api/admin/notification-definitions', $this->payload('admin.survivor'))->assertStatus(201);

        $this->seed(NotificationDefinitionSeeder::class);

        $this->assertDatabaseHas('notification_definitions', [
            'type' => IdentityNotificationType::forPolicy('admin.survivor'),
            'extension_identifier' => 'admin',
        ]);
    }

    /**
     * 운영자가 선언 정의의 문구를 고쳐 정의가 「기본」 이 아니게 되어도 [삭제] 능력은 열리지 않는다 —
     * 서버 삭제 판정(운영자 추가 정의만)과 목록 abilities 가 같은 규칙을 써야 화면에 누를 수 없는 버튼이 뜨지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects customized_declared_definition_not_deletable
     *
     * @return void 반환값 없음
     */
    public function test_customized_declared_definition_is_not_deletable(): void
    {
        $definition = NotificationDefinition::where('type', 'identity.purpose.signup')->firstOrFail();
        $template = $definition->templates()->where('channel', 'mail')->firstOrFail();

        $this->api()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '[운영자] 가입 인증번호 {code}', 'en' => '[Operator] Signup code {code}'],
            'body' => $template->body,
        ])->assertStatus(200);
        $this->assertFalse((bool) $definition->fresh()->is_default, '전제: 문구를 고치면 정의의 「기본」 이 풀린다');

        $rows = collect($this->api()->getJson('/api/admin/notification-definitions?extension_type=core&category=identity')->json('data.data'));
        $row = $rows->firstWhere('type', 'identity.purpose.signup');
        $this->assertNotNull($row);
        $this->assertFalse($row['abilities']['can_delete'], '고친 선언 정의에 [삭제] 가 열리면 누르는 순간 403 이 난다');

        $this->api()->deleteJson("/api/admin/notification-definitions/{$definition->id}")->assertStatus(403);
    }

    /**
     * 본인인증 문구도 알림 템플릿 API 로 고친다 — 빈 본문은 422 (옛 본인인증 메시지 API 의 본문 필수 계약 이식).
     *
     * @scenario surface=admin_api
     *
     * @effects identity_template_update_requires_body
     *
     * @return void 반환값 없음
     */
    public function test_identity_template_update_requires_body(): void
    {
        $template = NotificationDefinition::where('type', 'identity.purpose.login')->firstOrFail()
            ->templates()->where('channel', 'mail')->firstOrFail();

        $this->api()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '', 'en' => ''],
        ])->assertStatus(422)->assertJsonValidationErrors(['body']);
    }

    /**
     * 꺼 둔 본인인증 템플릿을 편집·저장해도 다시 켜지지 않는다 (옛 본인인증 메시지 API 의 #727 W13 계약 이식).
     *
     * @scenario surface=admin_api
     *
     * @effects identity_template_edit_keeps_inactive
     *
     * @return void 반환값 없음
     */
    public function test_editing_inactive_identity_template_keeps_it_inactive(): void
    {
        $template = NotificationDefinition::where('type', 'identity.purpose.password_reset')->firstOrFail()
            ->templates()->where('channel', 'mail')->firstOrFail();
        $template->update(['is_active' => false]);

        $this->api()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '[운영자] 비밀번호 재설정', 'en' => '[Operator] Password reset'],
            'body' => $template->body,
        ])->assertStatus(200);

        $this->assertFalse((bool) $template->fresh()->is_active);
    }

    /**
     * 관리자 토큰을 실은 요청 빌더.
     *
     * @return static 요청 빌더
     */
    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 정책별 문구 추가 요청 본문.
     *
     * @param  string  $policyKey  정책 키
     * @return array<string, mixed> 요청 본문
     */
    private function payload(string $policyKey): array
    {
        return [
            'category' => 'identity',
            'policy_key' => $policyKey,
            'name' => ['ko' => '게시글 삭제 확인', 'en' => 'Post delete confirm'],
            'templates' => [
                'mail' => [
                    'subject' => ['ko' => '[정책] 게시글 삭제 확인 {code}', 'en' => '[Policy] Delete confirm {code}'],
                    'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
                ],
            ],
        ];
    }

    /**
     * 운영자(source_type=admin) 본인인증 정책을 만듭니다.
     *
     * @param  string  $key  정책 키
     * @return IdentityPolicy 생성된 정책
     */
    private function makeAdminPolicy(string $key): IdentityPolicy
    {
        return IdentityPolicy::create($this->policyAttributes($key, 'admin'));
    }

    /**
     * 정책 속성 배열.
     *
     * @param  string  $key  정책 키
     * @param  string  $source  소유 출처 (admin | core)
     * @return array<string, mixed> 속성
     */
    private function policyAttributes(string $key, string $source): array
    {
        return [
            'key' => $key,
            'scope' => 'route',
            'target' => 'api.test.route',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'conditions' => [],
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => $source,
            'source_identifier' => $source,
        ];
    }
}
