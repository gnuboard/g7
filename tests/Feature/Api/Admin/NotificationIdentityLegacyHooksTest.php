<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\NotificationDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 7.0.x 본인인증 메시지 관리 훅 23종이 알림 설정 경로에서도 계속 발화하는지 잠근다 (#699, 설계 결정 2026-10-05).
 *
 * 본인인증 메시지가 알림 정의(category=identity)로 통합되면서 옛 관리 API 가 사라졌지만, 공개 훅은 마이너 버전에서 지우지
 * 않는다(사용 중단 → 메이저 제거). 그래서 본인인증 분류의 정의·템플릿을 다룰 때는 새 알림 훅과 함께 옛 이름도 같은 인자 개수로
 * 발화한다. 일반 알림에서는 발화하지 않는다 — 옛 구독자는 본인인증 메시지에만 반응하던 코드다.
 */
class NotificationIdentityLegacyHooksTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> 발화를 기록할 옛 훅 이름 */
    private const LEGACY_HOOKS = [
        'core.identity.message_definition.before_create',
        'core.identity.message_definition.filter_create_data',
        'core.identity.message_definition.after_create',
        'core.identity.message_definition.store_validation_rules',
        'core.identity.message_definition.index_validation_rules',
        'core.identity.message_definition.update_validation_rules',
        'core.identity.message_definition.before_update',
        'core.identity.message_definition.filter_update_data',
        'core.identity.message_definition.after_update',
        'core.identity.message_definition.before_toggle_active',
        'core.identity.message_definition.after_toggle_active',
        'core.identity.message_definition.before_delete',
        'core.identity.message_definition.after_delete',
        'core.identity.message_template.update_validation_rules',
        'core.identity.message_template.before_update',
        'core.identity.message_template.filter_update_data',
        'core.identity.message_template.after_update',
        'core.identity.message_template.before_toggle_active',
        'core.identity.message_template.after_toggle_active',
        'core.identity.message_template.before_reset',
        'core.identity.message_template.after_reset',
        'core.identity.message_template.reset_no_default',
        'core.identity.filter_default_message_definitions',
    ];

    /** @var array<string, int> 훅별 발화 횟수 */
    private array $fired = [];

    /** @var array<string, callable> 등록한 기록용 콜백 (tearDown 에서 떼어 낸다) */
    private array $recorders = [];

    private string $token;

    /**
     * 권한·알림 정의를 시딩하고, 옛 훅 23종 모두에 발화 기록 콜백을 답니다.
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

        foreach (self::LEGACY_HOOKS as $hook) {
            $isFilter = str_contains($hook, 'filter_') || str_ends_with($hook, '_rules');
            $this->recorders[$hook] = function ($value = null) use ($hook) {
                $this->fired[$hook] = ($this->fired[$hook] ?? 0) + 1;

                return $value;
            };
            $isFilter
                ? HookManager::addFilter($hook, $this->recorders[$hook])
                : HookManager::addAction($hook, $this->recorders[$hook]);
        }
    }

    /**
     * 기록용 콜백만 떼어 냅니다(운영 리스너는 건드리지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->recorders as $hook => $callback) {
            HookManager::removeAction($hook, $callback);
            HookManager::removeFilter($hook, $callback);
        }

        parent::tearDown();
    }

    /**
     * 본인인증 정의·템플릿의 추가·조회·수정·토글·복원·삭제가 옛 훅 23종을 모두 발화한다.
     *
     * @scenario surface=admin_api
     *
     * @effects legacy_identity_management_hooks_still_fire
     *
     * @return void 반환값 없음
     */
    public function test_identity_management_fires_every_legacy_hook(): void
    {
        IdentityPolicy::create([
            'key' => 'admin.legacy.hooks', 'scope' => 'route', 'target' => 'api.test.route', 'purpose' => 'sensitive_action',
            'grace_minutes' => 0, 'enabled' => true, 'priority' => 100, 'conditions' => [], 'applies_to' => 'both',
            'fail_mode' => 'block', 'source_type' => 'admin', 'source_identifier' => 'admin',
        ]);

        // 추가 · 목록
        $created = $this->api()->postJson('/api/admin/notification-definitions', [
            'category' => 'identity',
            'policy_key' => 'admin.legacy.hooks',
            'name' => ['ko' => '옛 훅', 'en' => 'Legacy hooks'],
            'templates' => ['mail' => ['subject' => ['ko' => '제목 {code}', 'en' => 'Subject {code}'], 'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>']]],
        ])->assertStatus(201)->json('data');
        $this->api()->getJson('/api/admin/notification-definitions?category=identity&extension_type=core')->assertOk();

        // 선언 정의(회원가입) 수정 · 토글 · 템플릿 수정 · 토글 · 복원
        $signup = NotificationDefinition::where('type', 'identity.purpose.signup')->with('templates')->firstOrFail();
        $this->api()->putJson("/api/admin/notification-definitions/{$signup->id}", ['is_active' => true])->assertOk();
        $this->api()->patchJson("/api/admin/notification-definitions/{$signup->id}/toggle-active")->assertOk();
        $template = $signup->templates->firstWhere('channel', 'mail');
        $this->api()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '[수정] 제목'], 'body' => ['ko' => '<p>{code}</p>'],
        ])->assertOk();
        $this->api()->patchJson("/api/admin/notification-templates/{$template->id}/toggle-active")->assertOk();
        $this->api()->postJson("/api/admin/notification-templates/{$template->id}/reset")->assertOk();

        // 운영자 정의는 선언 기본값이 없다 → 복원 시 reset_no_default · 삭제
        $operatorTemplateId = NotificationDefinition::findOrFail($created['id'])->templates()->value('id');
        $this->api()->postJson("/api/admin/notification-templates/{$operatorTemplateId}/reset")->assertStatus(404);
        $this->api()->deleteJson("/api/admin/notification-definitions/{$created['id']}")->assertOk();

        $missing = array_values(array_filter(self::LEGACY_HOOKS, fn ($hook) => ($this->fired[$hook] ?? 0) === 0));
        $this->assertSame([], $missing, '본인인증 분류 작업에서 발화하지 않은 옛 훅: '.implode(', ', $missing));
    }

    /**
     * 일반 알림(welcome)의 수정·토글·템플릿 수정·복원은 옛 본인인증 훅을 하나도 발화하지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects legacy_identity_management_hooks_skip_general_notifications
     *
     * @return void 반환값 없음
     */
    public function test_general_notifications_do_not_fire_legacy_hooks(): void
    {
        $welcome = NotificationDefinition::where('type', 'welcome')->with('templates')->firstOrFail();
        $this->api()->getJson('/api/admin/notification-definitions?category=general')->assertOk();
        $this->api()->putJson("/api/admin/notification-definitions/{$welcome->id}", ['is_active' => true])->assertOk();
        $this->api()->patchJson("/api/admin/notification-definitions/{$welcome->id}/toggle-active")->assertOk();
        $template = $welcome->templates->firstWhere('channel', 'mail');
        $this->api()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '[수정] 환영'], 'body' => ['ko' => '<p>환영</p>'],
        ])->assertOk();
        $this->api()->patchJson("/api/admin/notification-templates/{$template->id}/toggle-active")->assertOk();

        $fired = array_keys(array_filter($this->fired, fn ($count, $hook) => $count > 0 && $hook !== 'core.identity.filter_default_message_definitions', ARRAY_FILTER_USE_BOTH));
        $this->assertSame([], $fired, '일반 알림 작업에서 옛 본인인증 훅이 발화했다');
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
}
