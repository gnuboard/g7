<?php

namespace Tests\Feature\Admin;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * 템플릿 조작의 서버 수락 조건 — 화면이 막는 조작을 서버도 거절하는지 (#703)
 *
 * 관리자 템플릿 목록은 활성 템플릿의 스위치·삭제를 잠근다(같은 유형의 다른 템플릿을 켜면
 * 자동으로 바뀐다). 그런데 API 는 그 요청을 그대로 받아 유형의 활성 템플릿이 하나도 없는
 * 상태를 만들 수 있었다. 또 모듈·플러그인과 달리 템플릿 활성화·삭제에는 「진행 중(설치·
 * 삭제·업데이트) 작업」 가드가 없어, 업데이트가 도는 템플릿을 켜거나 지울 수 있었다.
 *
 * 시나리오 매니페스트: tests/scenarios/admin-action-gate-parity.yaml
 */
class TemplateOperationGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 진행 중 상태 가드 대상 템플릿 식별자 (디스크에 디렉터리를 만들지 않는다).
     */
    private const GUARD_IDENTIFIER = 'test-opguard';

    /**
     * 테스트가 바인딩한 Mockery 목을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 템플릿 활성화·삭제 권한을 가진 관리자를 만듭니다.
     *
     * AdminMiddleware 는 `admin` 역할을 확인하므로 그 역할과 권한 전용 역할을 함께 배정합니다.
     *
     * @return User 관리자
     */
    private function createTemplateAdmin(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.templates.read', 'core.templates.activate', 'core.templates.uninstall'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $permissionRole = Role::create([
            'identifier' => 'template_guard_'.uniqid(),
            'name' => json_encode(['ko' => '템플릿 운영자', 'en' => 'Template Operator']),
            'description' => json_encode(['ko' => '', 'en' => '']),
            'is_active' => true,
        ]);
        $permissionRole->permissions()->sync($permissionIds);

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

        return $user->fresh();
    }

    /**
     * 관리자 토큰을 단 요청 빌더를 반환합니다.
     *
     * @return static 헤더가 설정된 테스트 인스턴스
     */
    private function asTemplateAdmin(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->createTemplateAdmin()->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 유형의 기존 템플릿을 모두 비활성으로 돌린 뒤, 그 유형의 활성 템플릿을 지정한 수만큼 만듭니다.
     *
     * 테스트 DB 에 이미 활성 템플릿이 있어도 「마지막 활성」 여부가 이 테스트의 배치로만 정해지도록
     * 한다(트랜잭션이라 끝나면 되돌아간다).
     *
     * @param  string  $type  템플릿 유형 (admin|user)
     * @param  int  $count  만들 활성 템플릿 수
     * @return array<int, Template> 만든 활성 템플릿 (첫 번째가 조작 대상)
     */
    private function arrangeActiveTemplates(string $type, int $count): array
    {
        Template::query()->where('type', $type)->update(['status' => ExtensionStatus::Inactive->value]);

        $templates = [];
        for ($i = 0; $i < $count; $i++) {
            $templates[] = Template::factory()->create([
                'identifier' => 'testguard-'.$type.$i.substr(uniqid(), -6),
                'type' => $type,
                'status' => ExtensionStatus::Active->value,
            ]);
        }

        return $templates;
    }

    /**
     * 템플릿 매니저를 목으로 바꿉니다 — 서비스 가드가 매니저 호출 전에 거절하는지 보려고
     * 비활성화·삭제는 호출되면 안 되도록 둡니다.
     *
     * @return void 반환값 없음
     */
    private function bindManagerThatMustNotBeCalled(): void
    {
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('ensureLoaded')->andReturnNull();
        $mock->shouldReceive('getTemplateInfo')->andReturn(null);
        $mock->shouldNotReceive('deactivateTemplate');
        $mock->shouldNotReceive('uninstallTemplate');

        $this->app->instance(TemplateManagerInterface::class, $mock);
    }

    /**
     * 실제 TemplateManager 에 디스크 없이 템플릿 정의를 올리고, 지정한 상태의 DB 행을 만듭니다.
     *
     * @param  ExtensionStatus  $status  DB 행 상태
     * @return TemplateManager 정의가 적재된 매니저
     */
    private function managerWithTemplateInStatus(ExtensionStatus $status): TemplateManager
    {
        Template::query()->where('identifier', self::GUARD_IDENTIFIER)->delete();
        Template::factory()->create([
            'identifier' => self::GUARD_IDENTIFIER,
            'vendor' => 'test',
            'type' => 'admin',
            'status' => $status->value,
        ]);

        $manager = app(TemplateManager::class);
        $templates = new \ReflectionProperty(TemplateManager::class, 'templates');
        $templates->setValue($manager, [
            self::GUARD_IDENTIFIER => [
                'identifier' => self::GUARD_IDENTIFIER,
                'vendor' => 'test',
                'name' => ['ko' => '가드 테스트', 'en' => 'Guard Test'],
                'version' => '1.0.0',
                'type' => 'admin',
                'dependencies' => [],
            ],
        ]);

        return $manager;
    }

    /**
     * 설치·삭제·업데이트가 진행 중인 템플릿의 활성화는 거절된다 — 모듈·플러그인 활성화와 같은 가드.
     *
     * @scenario defect=template_activate_in_progress
     *
     * @effects template_activate_refused_while_operation_in_progress
     *
     * @return void 반환값 없음
     */
    public function test_activate_is_refused_while_template_operation_in_progress(): void
    {
        foreach ([ExtensionStatus::Installing, ExtensionStatus::Uninstalling, ExtensionStatus::Updating] as $status) {
            $manager = $this->managerWithTemplateInStatus($status);

            try {
                $manager->activateTemplate(self::GUARD_IDENTIFIER, true);
                $this->fail("진행 중({$status->value}) 템플릿의 활성화가 거절되지 않았습니다.");
            } catch (\RuntimeException $e) {
                $this->assertSame(
                    __('extensions.errors.operation_in_progress', ['name' => self::GUARD_IDENTIFIER, 'status' => $status->value]),
                    $e->getMessage()
                );
            }

            $this->assertSame(
                $status->value,
                Template::query()->where('identifier', self::GUARD_IDENTIFIER)->value('status'),
                '거절된 활성화는 상태를 바꾸지 않아야 합니다.'
            );
        }
    }

    /**
     * 설치·삭제·업데이트가 진행 중인 템플릿의 삭제는 거절된다 — 모듈·플러그인 삭제와 같은 가드.
     *
     * @scenario defect=template_uninstall_in_progress
     *
     * @effects template_uninstall_refused_while_operation_in_progress
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_is_refused_while_template_operation_in_progress(): void
    {
        foreach ([ExtensionStatus::Installing, ExtensionStatus::Uninstalling, ExtensionStatus::Updating] as $status) {
            $manager = $this->managerWithTemplateInStatus($status);

            try {
                $manager->uninstallTemplate(self::GUARD_IDENTIFIER);
                $this->fail("진행 중({$status->value}) 템플릿의 삭제가 거절되지 않았습니다.");
            } catch (\RuntimeException $e) {
                $this->assertSame(
                    __('extensions.errors.operation_in_progress', ['name' => self::GUARD_IDENTIFIER, 'status' => $status->value]),
                    $e->getMessage()
                );
            }

            $this->assertDatabaseHas('templates', [
                'identifier' => self::GUARD_IDENTIFIER,
                'status' => $status->value,
            ]);
        }
    }

    /**
     * 유형(관리자·사용자)의 마지막 활성 템플릿 비활성화는 422 로 거절되고 상태가 바뀌지 않는다.
     *
     * @scenario defect=template_deactivate_last_active
     *
     * @effects last_active_template_deactivation_refused
     *
     * @return void 반환값 없음
     */
    public function test_deactivating_last_active_template_of_type_is_refused(): void
    {
        foreach (['admin', 'user'] as $type) {
            [$template] = $this->arrangeActiveTemplates($type, 1);
            $this->bindManagerThatMustNotBeCalled();

            $response = $this->asTemplateAdmin()->postJson('/api/admin/templates/deactivate', [
                'template_name' => $template->identifier,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['template_name']);
            $this->assertSame(
                __('templates.errors.last_active_deactivate', ['type' => __('templates.types.'.$type)]),
                $response->json('errors.template_name.0')
            );
            $this->assertDatabaseHas('templates', [
                'id' => $template->id,
                'status' => ExtensionStatus::Active->value,
            ]);
        }
    }

    /**
     * 같은 유형의 다른 활성 템플릿이 남아 있으면 비활성화는 그대로 받아들여진다(과차단 금지).
     *
     * @scenario defect=template_deactivate_last_active
     *
     * @effects deactivation_allowed_when_another_template_of_type_stays_active
     *
     * @return void 반환값 없음
     */
    public function test_deactivating_is_allowed_when_another_template_of_type_stays_active(): void
    {
        [$target] = $this->arrangeActiveTemplates('admin', 2);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('ensureLoaded')->andReturnNull();
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $target->identifier,
            'vendor' => $target->vendor,
            'name' => $target->name,
            'version' => $target->version,
            'type' => $target->type,
            'status' => ExtensionStatus::Inactive->value,
        ]);
        $mock->shouldReceive('deactivateTemplate')->once()->andReturnUsing(function () use ($target) {
            $target->update(['status' => ExtensionStatus::Inactive->value]);

            return true;
        });
        $this->app->instance(TemplateManagerInterface::class, $mock);

        $response = $this->asTemplateAdmin()->postJson('/api/admin/templates/deactivate', [
            'template_name' => $target->identifier,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', [
            'id' => $target->id,
            'status' => ExtensionStatus::Inactive->value,
        ]);
    }

    /**
     * 유형의 마지막 활성 템플릿 삭제는 422 로 거절되고 템플릿은 남는다.
     *
     * @scenario defect=template_uninstall_last_active
     *
     * @effects last_active_template_uninstall_refused
     *
     * @return void 반환값 없음
     */
    public function test_uninstalling_last_active_template_of_type_is_refused(): void
    {
        foreach (['admin', 'user'] as $type) {
            [$template] = $this->arrangeActiveTemplates($type, 1);
            $this->bindManagerThatMustNotBeCalled();

            $response = $this->asTemplateAdmin()->deleteJson('/api/admin/templates/uninstall', [
                'template_name' => $template->identifier,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['template_name']);
            $this->assertSame(
                __('templates.errors.last_active_uninstall', ['type' => __('templates.types.'.$type)]),
                $response->json('errors.template_name.0')
            );
            $this->assertDatabaseHas('templates', [
                'id' => $template->id,
                'status' => ExtensionStatus::Active->value,
            ]);
        }
    }
}
