<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 확장 관리 API 가 서비스의 실패 문구를 한 번 더 감싸지 않는지 검증합니다 (#703 브라우저 실측).
 *
 * 진행 중인 템플릿을 켜자 응답 message 가 「템플릿 활성화에 실패했습니다: 템플릿 활성화에 실패했습니다: …」
 * 였다. 서비스가 이미 「…실패했습니다: 원인」으로 번역해 던진 검증 오류를 컨트롤러가 같은 문구로 다시
 * 감쌌기 때문이다. 설치 경로는 이미 첫 오류 문구를 그대로 쓴다(이중 래핑 방지) — 나머지 경로도 같게 한다.
 *
 * 시나리오 매니페스트: tests/scenarios/extension-failure-message-wrap.yaml
 */
class ExtensionFailureMessageWrapTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 템플릿·모듈 활성화 권한을 가진 관리자 토큰을 단 요청 빌더를 반환합니다.
     *
     * AdminMiddleware 는 `admin` 역할을, 라우트는 `core.templates.activate`·`core.modules.activate` 권한을 본다.
     *
     * @return static 헤더가 설정된 테스트 인스턴스
     */
    private function asSuperAdmin(): static
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.templates.activate', 'core.modules.activate'] as $identifier) {
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
            'identifier' => 'failmsg_'.uniqid(),
            'name' => json_encode(['ko' => '확장 운영자', 'en' => 'Extension Operator']),
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

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 서비스를 목으로 바꿔 지정한 메서드가 이미 포장된 실패 문구의 검증 오류를 던지게 하고, API 응답 message 가
     * 그 문구 그대로인지 확인합니다.
     *
     * @param  class-string  $serviceClass  목으로 바꿀 서비스
     * @param  string  $method  실패시킬 서비스 메서드
     * @param  string  $field  검증 오류 필드
     * @param  string  $message  서비스가 던질 (이미 포장된) 문구
     * @param  string  $url  호출할 API 경로
     * @param  array<string, mixed>  $body  요청 본문
     * @return void 반환값 없음
     */
    private function assertServiceMessageIsNotWrappedTwice(string $serviceClass, string $method, string $field, string $message, string $url, array $body): void
    {
        $this->mock($serviceClass, function ($mock) use ($method, $field, $message) {
            $mock->shouldReceive($method)->andThrow(ValidationException::withMessages([$field => [$message]]));
        });

        $response = $this->asSuperAdmin()->postJson($url, $body);

        $response->assertStatus(422);
        $this->assertSame($message, $response->json('message'), "{$url} 응답 message 가 서비스 문구와 다릅니다.");
    }

    /**
     * 템플릿 활성화 거절 문구는 앞말이 두 번 붙지 않는다 — 브라우저 실측에서 드러난 경로.
     *
     * @scenario surface=template_activate
     *
     * @effects failure_message_not_wrapped_twice
     *
     * @return void 반환값 없음
     */
    public function test_template_activate_failure_message_is_not_wrapped_twice(): void
    {
        $this->assertServiceMessageIsNotWrappedTwice(
            TemplateService::class, 'activateTemplate', 'identifier',
            __('templates.errors.activation_failed', ['error' => 'busy']),
            '/api/admin/templates/activate', ['template_name' => 'any-template']
        );
    }

    /**
     * 템플릿 비활성화 거절 문구는 앞말이 두 번 붙지 않는다.
     *
     * @scenario surface=template_deactivate
     *
     * @effects failure_message_not_wrapped_twice
     *
     * @return void 반환값 없음
     */
    public function test_template_deactivate_failure_message_is_not_wrapped_twice(): void
    {
        $this->assertServiceMessageIsNotWrappedTwice(
            TemplateService::class, 'deactivateTemplate', 'identifier',
            __('templates.errors.deactivation_failed', ['error' => 'busy']),
            '/api/admin/templates/deactivate', ['template_name' => 'any-template']
        );
    }

    /**
     * 모듈 활성화 거절 문구는 앞말이 두 번 붙지 않는다.
     *
     * @scenario surface=module_activate
     *
     * @effects failure_message_not_wrapped_twice
     *
     * @return void 반환값 없음
     */
    public function test_module_activate_failure_message_is_not_wrapped_twice(): void
    {
        $this->assertServiceMessageIsNotWrappedTwice(
            ModuleService::class, 'activateModule', 'module_name',
            __('modules.activation_failed', ['error' => 'busy']),
            '/api/admin/modules/activate', ['module_name' => 'any-module']
        );
    }

    /**
     * 모듈 비활성화 거절 문구는 앞말이 두 번 붙지 않는다.
     *
     * @scenario surface=module_deactivate
     *
     * @effects failure_message_not_wrapped_twice
     *
     * @return void 반환값 없음
     */
    public function test_module_deactivate_failure_message_is_not_wrapped_twice(): void
    {
        $this->assertServiceMessageIsNotWrappedTwice(
            ModuleService::class, 'deactivateModule', 'module_name',
            __('modules.deactivation_failed', ['error' => 'busy']),
            '/api/admin/modules/deactivate', ['module_name' => 'any-module']
        );
    }
}
