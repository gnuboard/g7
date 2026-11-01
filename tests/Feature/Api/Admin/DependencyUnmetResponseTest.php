<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 관리자 API 의 의존 미충족 응답 — 템플릿 활성화 409 · 호환성 복구 409 (#152)
 *
 * 회귀 1: 템플릿 활성화가 버전 미달 의존 위에서 200 을 돌려주고(같은 응답에 dependencies_met:false) 직후 사이트가 503 이 됐다.
 * 회귀 2: 호환성 복구 버튼이 활성화 결과를 버려, 의존 미충족으로 켜지지 않았는데도 「복구되었습니다」(200)를 냈다.
 * 목 대신 실제 매니저로 판정한다(판정식이 응답까지 이어지는지 잠근다).
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class DependencyUnmetResponseTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'test-dep152-api';

    /**
     * 버전 미달 의존 모듈과, 그 모듈을 요구하는 비활성 사용자 템플릿을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Module::create([
            'identifier' => 'test-dep152-mod', 'vendor' => 'test', 'name' => ['ko' => '의존 모듈', 'en' => 'Dep Module'],
            'version' => '0.1.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);

        $manager = app(TemplateManagerInterface::class);
        // 요청 경로의 TemplateService 가 ensureLoaded() 로 디렉토리를 다시 읽어 아래 주입을 지우지 않게 먼저 로드한다
        $manager->ensureLoaded();
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($manager);
        $loaded[self::TEMPLATE] = [
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'name' => ['ko' => '시험 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'dependencies' => ['modules' => ['test-dep152-mod' => '>=9.9.9']],
        ];
        $templates->setValue($manager, $loaded);
        TemplateManager::invalidateTemplateStatusCache();
    }

    /**
     * 테스트 뒤 상태 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TemplateManager::invalidateTemplateStatusCache();
        parent::tearDown();
    }

    /**
     * 템플릿 활성화 권한을 가진 관리자 토큰을 단 요청 빌더를 반환합니다.
     *
     * @return static 헤더가 설정된 테스트 인스턴스
     */
    private function asTemplateOperator(): static
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.templates.activate'],
            [
                'name' => json_encode(['ko' => 'core.templates.activate', 'en' => 'core.templates.activate']),
                'description' => json_encode(['ko' => '', 'en' => '']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
        $permissionRole = Role::create([
            'identifier' => 'dep152_'.uniqid(),
            'name' => json_encode(['ko' => '템플릿 운영자', 'en' => 'Template Operator']),
            'description' => json_encode(['ko' => '', 'en' => '']),
            'is_active' => true,
        ]);
        $permissionRole->permissions()->sync([$permission->id]);
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($permissionRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
            'Accept-Language' => 'ko',
        ]);
    }

    /**
     * 버전 미달 의존 템플릿 활성화는 409 이고 항목이 사유·버전·종류와 템플릿 타입을 싣는다.
     *
     * @scenario surface=api_activate_409
     *
     * @effects activation_blocks_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_activate_returns_409_with_version_mismatch(): void
    {
        Template::factory()->create(['identifier' => self::TEMPLATE, 'vendor' => 'test', 'type' => 'user', 'status' => ExtensionStatus::Inactive->value]);

        $response = $this->asTemplateOperator()->postJson('/api/admin/templates/activate', ['template_name' => self::TEMPLATE]);

        $response->assertStatus(409);
        $response->assertJsonPath('errors.missing_modules.0.status', 'version_mismatch');
        $response->assertJsonPath('errors.missing_modules.0.required_version', '>=9.9.9');
        $response->assertJsonPath('errors.missing_modules.0.installed_version', '0.1.0');
        $response->assertJsonPath('errors.missing_modules.0.type', 'module');
        $response->assertJsonPath('errors.template_type', 'user');
        $this->assertSame(ExtensionStatus::Inactive->value, Template::where('identifier', self::TEMPLATE)->value('status'));
    }

    /**
     * 호환성 복구가 의존 미충족으로 켜지지 못하면 409 와 사람이 읽는 사유 문장을 돌려준다.
     *
     * @scenario surface=api_recover_409
     *
     * @effects recover_reports_failure
     *
     * @return void 반환값 없음
     */
    public function test_recover_returns_409_when_dependencies_unmet(): void
    {
        Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'type' => 'user',
            'status' => ExtensionStatus::Inactive->value,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=7.0.0',
        ]);

        $response = $this->asTemplateOperator()->postJson('/api/admin/extensions/template/'.self::TEMPLATE.'/recover');

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertSame(
            __('extensions.errors.recover_dependencies_unmet', ['list' => '의존 모듈(test-dep152-mod) 버전 미달 0.1.0 → >=9.9.9'], 'ko'),
            $response->json('message')
        );
        $response->assertJsonPath('errors.missing_modules.0.status', 'version_mismatch');
        $this->assertSame(ExtensionStatus::Inactive->value, Template::where('identifier', self::TEMPLATE)->value('status'));
    }

    /**
     * 의존과 무관한 이유로 켜지지 못하면 의존 문구(빈 목록) 대신 매니저가 남긴 사유를 그대로 싣는다.
     *
     * 회귀: 확장이 스스로 활성화를 거부해도 「필요한 확장이 조건에 맞지 않습니다: 」(빈 목록)로 알려 실제 사유가 가려졌다.
     *
     * @scenario surface=api_recover_409_other_reason
     *
     * @effects recover_reports_failure
     *
     * @return void 반환값 없음
     */
    public function test_recover_reports_manager_reason_when_failure_is_not_dependency(): void
    {
        Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'type' => 'user',
            'status' => ExtensionStatus::Inactive->value,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=7.0.0',
        ]);

        $manager = \Mockery::mock(TemplateManagerInterface::class);
        $manager->shouldReceive('getTemplate')->andReturn(null);
        $manager->shouldReceive('activateTemplate')->once()->with(self::TEMPLATE)
            ->andReturn(['success' => false, 'reason' => '시험 확장이 활성화를 거부했습니다']);
        $this->app->instance(TemplateManagerInterface::class, $manager);

        $response = $this->asTemplateOperator()->postJson('/api/admin/extensions/template/'.self::TEMPLATE.'/recover');

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertSame(
            __('extensions.errors.recover_failed', ['reason' => '시험 확장이 활성화를 거부했습니다'], 'ko'),
            $response->json('message')
        );
        $this->assertNull($response->json('errors.missing_modules'));
    }
}
